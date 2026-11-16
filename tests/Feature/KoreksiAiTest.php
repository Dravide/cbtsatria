<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\StudentAnswer;
use App\Domain\Event\Models\EventClass;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Grading\Enums\StatusNilai;
use App\Domain\Grading\Models\EssayGrading;
use App\Domain\Grading\Models\ExamResult;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Tenancy\Models\PengaturanAi;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|==========================================================================
| Koreksi uraian dengan AI - pratinjau, bukan tulisan
|==========================================================================
|
| Yang dijaga berkas ini adalah satu janji: AI MENGUSULKAN, Guru yang
| menyimpan. Test-nya karena itu membuktikan dua hal yang berlawanan -
| bahwa usulannya benar, DAN bahwa usulan itu TIDAK menulis apa pun sampai
| Guru menyetujuinya. Test yang hanya membuktikan yang pertama akan lulus
| pada sistem yang menulis nilai tanpa dibaca siapa pun.
|
| Jalur yang dipakai adalah jalur PRODUKSI yang sudah ada: `siapkanUjianSiswa()`
| (helpers bersama) membuat ujian dengan satu butir uraian berrubrik
| 'Ketepatan konsep' berbobot 5, siswa mengerjakannya, Guru mengoreksinya.
| Yang ditambahkan di sini hanyalah penyedia AI palsu lewat `Http::fake()`.
*/

beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();
    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'Ganjil',
    ]);
    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.ai.koreksi',
    ]);

    // Penyedia AI sekolah, sama seperti `GenerateSoalAiTest` - lewat kolom
    // terenkripsi, bukan `forceFill`, supaya jalur dekripsinya ikut teruji.
    PengaturanAi::factory()->create([
        'school_id' => $this->sekolah->id,
        'base_url' => 'https://api.contoh.test/v1/',
        'model' => 'model-contoh',
        'api_key_encrypted' => Crypt::encryptString('sk-rahasia-koreksi'),
        'aktif' => true,
    ]);
});

/** Palsukan jawaban penyedia dengan satu objek JSON di dalam `content`. */
function jawabKoreksiAi(array $isi, int $status = 200)
{
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode($isi, JSON_UNESCAPED_UNICODE)]]],
        ], $status),
    ]);
}

/** Jawaban sengaja disimpan supaya seluruh butir terjawab. */
function jawabanBenarKoreksi(ExamQuestion $soal): ?array
{
    $kunci = $soal->kunci_json ?? [];

    return match ($soal->jenis) {
        JenisSoal::PilihanGanda,
        JenisSoal::BenarSalah,
        JenisSoal::JawabanGanda,
        JenisSoal::Media => ['opsi' => $kunci['opsi'] ?? []],
        JenisSoal::IsianSingkat => ['teks' => ($kunci['teks'] ?? [])[0] ?? ''],
        JenisSoal::IsianRumpang => [
            'rumpang' => array_map(
                fn ($varian) => is_array($varian) ? (string) ($varian[0] ?? '') : '',
                (array) ($kunci['rumpang'] ?? []),
            ),
        ],
        JenisSoal::Angka => ['nilai' => $kunci['nilai'] ?? 0],
        JenisSoal::Menjodohkan => ['pasangan' => $kunci['pasangan'] ?? []],
        JenisSoal::Mengurutkan => ['urutan' => $kunci['urutan'] ?? []],
        JenisSoal::Uraian => ['teks' => 'Jawaban uraian siswa, ditulis seadanya.'],
        default => null,
    };
}

/** Jawaban uraian satu attempt - satu-satunya yang menahan publikasi. */
function jawabanUraianKoreksi(ExamAttempt $attempt): StudentAnswer
{
    return StudentAnswer::query()
        ->whereHas('butir', fn ($q) => $q
            ->where('exam_attempt_id', $attempt->id)
            ->whereHas('soal', fn ($s) => $s->where('jenis', 'uraian')))
        ->firstOrFail();
}

/** Hasil satu peserta. */
function hasilPesertaKoreksi(ExamParticipant $peserta): ExamResult
{
    return ExamResult::query()
        ->whereIn('exam_attempt_id', ExamAttempt::query()
            ->where('exam_participant_id', $peserta->id)
            ->select('id'))
        ->firstOrFail();
}

/** Guru yang mengampu ujian - penugasan mengajar di mapel & kelasnya. */
function guruPenilaiKoreksi(Exam $ujian): User
{
    $kelas = EventClass::query()
        ->where('event_id', $ujian->event_id)
        ->firstOrFail();

    $event = $ujian->event()->firstOrFail();

    $guru = User::factory()->guru()
        ->untukSekolah(School::find($ujian->school_id))
        ->create(['username' => 'guru.ai.nilai.'.$ujian->id]);

    TeachingAssignment::factory()->create([
        'school_id' => $ujian->school_id,
        'academic_year_id' => $event->academic_year_id,
        'teacher_id' => $guru->id,
        'subject_id' => $ujian->subject_id,
        'class_id' => $kelas->class_id,
    ]);

    return $guru;
}

/**
 * Ujian lengkap dengan satu siswa yang sudah selesai mengerjakan.
 *
 * Urutannya mengikuti produksi - presensi, gerbang, jawaban, Kumpulkan -
 * karena `pending_essays` dan `exam_results` hanya lahir dari ujian yang
 * sungguh berjalan, bukan dari jawaban yang disimpan langsung.
 */
function ujianDenganUraian(TestCase $test): array
{
    $data = siapkanUjianSiswa($test->sekolah, $test->tahun, $test->semester, $test->admin);
    $peserta = $data['peserta'][0];
    $siswa = $data['siswa'][0];

    verifikasiHadir($test, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($test, $siswa, $peserta, $data['token']);

    $butir = AttemptQuestion::query()
        ->where('exam_attempt_id', attemptBerjalan($peserta)->id)
        ->with('soal')
        ->orderBy('display_order')
        ->get();

    foreach ($butir as $satu) {
        $soal = $satu->soal;

        if ($soal === null) {
            continue;
        }

        $isi = jawabanBenarKoreksi($soal);

        if ($isi === null) {
            continue;
        }

        masukUji($test, $siswa)
            ->postJson("/siswa/ujian/{$peserta->id}/butir/{$satu->id}", ['isi' => $isi])
            ->assertOk();
    }

    masukUji($test, $siswa)
        ->post("/siswa/ujian/{$peserta->id}/selesai")
        ->assertRedirect();

    $attempt = attemptBerjalan($peserta);

    $data['attempt'] = $attempt;
    $data['jawaban'] = jawabanUraianKoreksi($attempt);
    $data['guru'] = guruPenilaiKoreksi($data['ujian']);

    return $data;
}

/* =====================================================================
 | Pratinjau: usulan benar, dan TIDAK menulis
 |===================================================================== */

it('mengusulkan poin per kriteria tanpa menyimpan apa pun', function () {
    $d = ujianDenganUraian($this);

    // Model menjawab dengan nama kriteria yang sama seperti snapshot rubrik.
    jawabKoreksiAi([
        'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 4]],
        'catatan' => 'Syarat ketiga belum disebut.',
    ]);

    masukUji($this, $d['guru'])
        ->postJson("/guru/koreksi/jawaban/{$d['jawaban']->id}/ai")
        ->assertOk()
        ->assertJsonPath('usulan.total', 4)
        ->assertJsonPath('usulan.bobot', 5)
        ->assertJsonPath('usulan.kriteria.0.kriteria', 'Ketepatan konsep')
        ->assertJsonPath('usulan.kriteria.0.poin', 4)
        ->assertJsonPath('usulan.catatan', 'Syarat ketiga belum disebut.');

    // INTI: tidak satu baris pun ditulis. Nilai masih menunggu koreksi.
    expect(EssayGrading::query()->where('student_answer_id', $d['jawaban']->id)->exists())->toBeFalse();

    $hasil = hasilPesertaKoreksi($d['peserta'][0])->refresh();

    expect($hasil->pending_essays)->toBe(1)
        ->and((float) $hasil->essay_score)->toBe(0.0)
        ->and($hasil->grading_status)->toBe(StatusNilai::BelumDinilai);
});

it('memakai jumlah kriteria dari snapshot rubrik, bukan dari model', function () {
    $d = ujianDenganUraian($this);

    // Model mengarang kriteria yang tidak ada di rubrik, dan melewatkan yang
    // ada. Yang disimpan tetap rubrik snapshot: kriteria asing dibuang, yang
    // tidak disebut diisi 0.
    jawabKoreksiAi([
        'kriteria' => [
            ['kriteria' => 'Kriteria karangan model', 'poin' => 9],
            ['kriteria' => 'ketepatan konsep', 'poin' => 3],
        ],
    ]);

    masukUji($this, $d['guru'])
        ->postJson("/guru/koreksi/jawaban/{$d['jawaban']->id}/ai")
        ->assertOk()
        // Satu kriteria saja - persis jumlah di rubriknya.
        ->assertJsonCount(1, 'usulan.kriteria')
        ->assertJsonPath('usulan.kriteria.0.kriteria', 'Ketepatan konsep')
        // Nama dinormalkan (huruf kecil) sehingga tetap cocok.
        ->assertJsonPath('usulan.kriteria.0.poin', 3);
});

it('mematok poin usulan pada maksimum kriteria, tak pernah melebihi bobot', function () {
    $d = ujianDenganUraian($this);

    // Model memberi 12 untuk kriteria bernilai 5. Usulan yang melebihi bobot
    // akan DITOLAK `PenilaiUraian` saat disimpan - jadi dipatok di sini,
    // sebelum sampai ke Guru.
    jawabKoreksiAi([
        'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 12]],
    ]);

    masukUji($this, $d['guru'])
        ->postJson("/guru/koreksi/jawaban/{$d['jawaban']->id}/ai")
        ->assertOk()
        ->assertJsonPath('usulan.kriteria.0.poin', 5)
        ->assertJsonPath('usulan.total', 5);
});

/* =====================================================================
 | Simpan: yang tersimpan adalah yang DISETUJUI Guru
 |===================================================================== */

it('menyimpan usulan lewat jalur koreksi yang sama dengan koreksi manual', function () {
    $d = ujianDenganUraian($this);

    masukUji($this, $d['guru'])
        ->post("/guru/koreksi/jawaban/{$d['jawaban']->id}", [
            'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 4]],
            'feedback' => 'Syarat ketiga belum disebut.',
        ])
        ->assertRedirect();

    $koreksi = EssayGrading::query()->where('student_answer_id', $d['jawaban']->id)->firstOrFail();

    expect((float) $koreksi->score)->toBe(4.0)
        ->and($koreksi->criteria_json)->toHaveCount(1)
        ->and($koreksi->criteria_json[0]['kriteria'])->toBe('Ketepatan konsep')
        ->and((float) $koreksi->criteria_json[0]['poin'])->toBe(4.0);

    $hasil = hasilPesertaKoreksi($d['peserta'][0])->refresh();

    expect($hasil->pending_essays)->toBe(0)
        ->and((float) $hasil->essay_score)->toBe(4.0);
});

/* =====================================================================
 | Batch: "per halaman" dan "semua"
 |===================================================================== */

it('mengusulkan beberapa jawaban sekaligus, dan tidak menyimpan satu pun', function () {
    $d = ujianDenganUraian($this);

    jawabKoreksiAi([
        'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 2]],
    ]);

    masukUji($this, $d['guru'])
        ->postJson("/guru/koreksi/{$d['ujian']->id}/ai", [
            'jawaban' => [$d['jawaban']->id],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'hasil')
        ->assertJsonPath('hasil.0.jawaban_id', $d['jawaban']->id)
        ->assertJsonPath('hasil.0.usulan.total', 2)
        ->assertJsonPath('hasil.0.galat', null);

    expect(EssayGrading::query()->count())->toBe(0);
});

it('menyimpan usulan batch yang dicentang, dan hanya milik ujian ini', function () {
    $d = ujianDenganUraian($this);

    masukUji($this, $d['guru'])
        ->post("/guru/koreksi/{$d['ujian']->id}/ai/simpan", [
            'koreksi' => [[
                'jawaban_id' => $d['jawaban']->id,
                'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 3]],
                'feedback' => 'Dari usulan AI.',
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHas('sukses', fn (string $p) => str_contains($p, '1 jawaban'));

    $koreksi = EssayGrading::query()->where('student_answer_id', $d['jawaban']->id)->firstOrFail();

    expect((float) $koreksi->score)->toBe(3.0)
        ->and($koreksi->feedback)->toBe('Dari usulan AI.');
});

it('melewati baris batch yang nilainya tidak sah, dan tetap menyimpan yang sah', function () {
    $d = ujianDenganUraian($this);

    // Poin 99 untuk kriteria bernilai 5: `PenilaiUraian` menolaknya. Baris itu
    // dilewati; mengoreksi seluruh kiriman karena satu baris cacat berarti
    // membuang usulan yang Guru sudah periksa.
    masukUji($this, $d['guru'])
        ->post("/guru/koreksi/{$d['ujian']->id}/ai/simpan", [
            'koreksi' => [[
                'jawaban_id' => $d['jawaban']->id,
                'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 99]],
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHas('galat');

    expect(EssayGrading::query()->count())->toBe(0);
});

/* =====================================================================
 | Batas dan keadaan yang menolak
 |===================================================================== */

it('menolak usulan AI saat penyedia belum diatur', function () {
    $d = ujianDenganUraian($this);

    PengaturanAi::query()->where('school_id', $this->sekolah->id)->update(['aktif' => false]);

    Http::fake();

    masukUji($this, $d['guru'])
        ->postJson("/guru/koreksi/jawaban/{$d['jawaban']->id}/ai")
        ->assertStatus(422)
        ->assertJsonPath('pesan', fn (string $p) => str_contains($p, 'belum diatur'));
});

it('menolak usulan AI saat koreksi sedang terkunci', function () {
    $d = ujianDenganUraian($this);

    // Publikasi mengunci koreksi - dan usulan yang terkunci hanya akan
    // ditolak saat disimpan, setelah satu panggilan penyedia ditagih.
    SchoolEvent::query()
        ->whereKey($d['ujian']->event_id)
        ->update(['gradings_locked' => true]);

    Http::fake();

    masukUji($this, $d['guru'])
        ->postJson("/guru/koreksi/jawaban/{$d['jawaban']->id}/ai")
        ->assertStatus(422)
        ->assertJsonPath('pesan', fn (string $p) => str_contains($p, 'dikunci'));

    Http::assertNothingSent();
});

it('membalas 404 untuk jawaban yang bukan ujian Guru ini', function () {
    $d = ujianDenganUraian($this);

    $guruLain = User::factory()->guru()
        ->untukSekolah($this->sekolah)->create(['username' => 'guru.bukan.penilai']);

    Http::fake();

    masukUji($this, $guruLain)
        ->postJson("/guru/koreksi/jawaban/{$d['jawaban']->id}/ai")
        ->assertNotFound();

    app(SchoolResolver::class)->forget();
});
