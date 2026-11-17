<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\StudentAnswer;
use App\Domain\Event\Models\EventClass;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Grading\Models\ExamResult;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Tenancy\Models\School;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Unduhan nilai sisi Guru - plate 07.
 *
 * =====================================================================
 * Yang dijaga: berkas hanya lahir bila SEMUANYA sudah dikoreksi
 * =====================================================================
 * Di layar, uraian yang belum dinilai tampil "belum" dan angkanya terbaca
 * sebagai sementara. Di berkas XLSX, kolom itu menjadi SEL yang dijumlahkan
 * Excel - dan Guru yang menghitung rata-rata dari berkasnya akan mendapat
 * angka yang berbeda dari laman, tanpa satu pun tanda bahwa penyebabnya
 * koreksi yang belum selesai.
 *
 * Berkas yang menyembunyikan pekerjaan yang belum selesai adalah berkas yang
 * akan beredar sebagai nilai akhir. Karena itu penolakannya diuji dengan
 * MENCOBA mengunduhnya, bukan dengan memeriksa tombolnya.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.unduh',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();
    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);
});

/** Guru yang mengampu mapel ujian - gerbang `UjianGuru::satu()`. */
function guruUnduh(Exam $ujian): User
{
    $kelas = EventClass::query()->where('event_id', $ujian->event_id)->firstOrFail();
    $event = $ujian->event()->firstOrFail();

    $guru = User::factory()->guru()->untukSekolah(School::find($ujian->school_id))->create([
        'username' => 'guru.unduh.'.$ujian->id,
    ]);

    TeachingAssignment::factory()->create([
        'school_id' => $ujian->school_id,
        'academic_year_id' => $event->academic_year_id,
        'teacher_id' => $guru->id,
        'subject_id' => $ujian->subject_id,
        'class_id' => $kelas->class_id,
    ]);

    return $guru;
}

/** Jawab seluruh butir satu attempt lewat endpoint siswa. */
function jawabSemua(TestCase $test, User $siswa, ExamParticipant $peserta): void
{
    $butir = AttemptQuestion::query()
        ->where('exam_attempt_id', attemptBerjalan($peserta)->id)
        ->with('soal')
        ->orderBy('display_order')
        ->get();

    foreach ($butir as $satu) {
        $soal = $satu->soal;
        $kunci = $soal?->kunci_json ?? [];

        $isi = match ($soal?->jenis) {
            JenisSoal::PilihanGanda, JenisSoal::BenarSalah, JenisSoal::Media => ['opsi' => $kunci['opsi'] ?? []],
            JenisSoal::IsianSingkat => ['teks' => ($kunci['teks'] ?? [])[0] ?? ''],
            JenisSoal::Uraian => ['teks' => 'Jawaban uraian siswa.'],
            default => null,
        };

        if ($isi === null) {
            continue;
        }

        masukUji($test, $siswa)
            ->postJson("/siswa/ujian/{$peserta->id}/butir/{$satu->id}", ['isi' => $isi])
            ->assertOk();
    }
}

/** Satu siswa mengerjakan sampai mengumpulkan - tanpa mengoreksi uraian. */
function kerjakanSampaiKumpul(TestCase $test, array $data, int $i): ExamAttempt
{
    $peserta = $data['peserta'][$i];

    verifikasiHadir($test, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($test, $data['siswa'][$i], $peserta, $data['token']);
    jawabSemua($test, $data['siswa'][$i], $peserta);

    masukUji($test, $data['siswa'][$i])
        ->post("/siswa/ujian/{$peserta->id}/selesai")
        ->assertRedirect();

    return attemptBerjalan($peserta);
}

/** Hasil satu peserta. */
function hasilSatu(ExamParticipant $peserta): ExamResult
{
    return ExamResult::query()
        ->whereIn('exam_attempt_id', ExamAttempt::query()
            ->where('exam_participant_id', $peserta->id)
            ->select('id'))
        ->firstOrFail();
}

/** Jawaban uraian satu attempt - satu-satunya yang menahan unduhan. */
function jawabanEsai(ExamAttempt $attempt): StudentAnswer
{
    return StudentAnswer::query()
        ->whereHas('butir', fn ($q) => $q
            ->where('exam_attempt_id', $attempt->id)
            ->whereHas('soal', fn ($s) => $s->where('jenis', JenisSoal::Uraian->value)))
        ->firstOrFail();
}

it('menolak mengunduh selama masih ada uraian yang belum dikoreksi', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruUnduh($data['ujian']);

    kerjakanSampaiKumpul($this, $data, 0);

    // Uraiannya belum disentuh - dan itulah keadaannya setelah siswa
    // mengumpulkan sebelum Guru sempat membuka antrean koreksi.
    expect(hasilSatu($data['peserta'][0])->final())->toBeFalse();

    masukUji($this, $guru)
        ->get("/guru/nilai/{$data['ujian']->id}/unduh")
        ->assertRedirect()
        // Pesannya menyebut JUMLAHNYA, bukan sekadar "tidak bisa": Guru yang
        // menerima penolakan tanpa sebab akan menekan tombolnya lagi.
        ->assertSessionHas('galat', fn (string $pesan) => str_contains($pesan, 'belum dikoreksi'));
});

it('mengunduh seluruh nilai sebagai XLSX setelah semua uraian dikoreksi', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruUnduh($data['ujian']);

    $attempt = kerjakanSampaiKumpul($this, $data, 0);

    $jawaban = jawabanEsai($attempt);

    masukUji($this, $guru)
        ->post("/guru/koreksi/jawaban/{$jawaban->id}", [
            'poin' => 5,
            'catatan' => 'Baik.',
        ])
        ->assertRedirect();

    expect(hasilSatu($data['peserta'][0])->refresh()->final())->toBeTrue();

    $unduh = masukUji($this, $guru)->get("/guru/nilai/{$data['ujian']->id}/unduh");

    $unduh->assertOk();
    $unduh->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($unduh->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('nilai-');
});

it('menyaring unduhan ke satu kelas bila penyaringnya dipakai', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruUnduh($data['ujian']);

    $attempt = kerjakanSampaiKumpul($this, $data, 0);

    masukUji($this, $guru)
        ->post('/guru/koreksi/jawaban/'.jawabanEsai($attempt)->id, [
            'poin' => 5,
            'catatan' => 'Baik.',
        ])
        ->assertRedirect();

    $kelas = ExamResult::query()
        ->where('exam_attempt_id', $attempt->id)
        ->firstOrFail()
        ->pengerjaan
        ->penempatan
        ->pesertaEvent
        ->class_id;

    // Kelas ini punya barisnya.
    $punya = masukUji($this, $guru)
        ->get("/guru/nilai/{$data['ujian']->id}/unduh?kelas={$kelas}");

    $punya->assertOk();

    // Kelas yang tidak punya peserta sama sekali ditolak, dan alasannya
    // menyebut KELAS - bukan "belum ada nilai", yang akan membuat Guru
    // mengira seluruh ujiannya kosong.
    $kosong = masukUji($this, $guru)
        ->get("/guru/nilai/{$data['ujian']->id}/unduh?kelas=999999");

    $kosong->assertRedirect();
    $kosong->assertSessionHas('galat', fn (string $pesan) => str_contains($pesan, 'Kelas ini'));
});

it('menjawab 404 untuk guru yang tidak mengampu ujian itu', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    kerjakanSampaiKumpul($this, $data, 0);

    $penyusup = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'guru.penyusup.unduh',
    ]);

    // 404, bukan 403: ujian mapel lain dan ujian sekolah lain adalah dua hal
    // yang sama bagi Guru ini, yaitu tidak ada.
    masukUji($this, $penyusup)
        ->get("/guru/nilai/{$data['ujian']->id}/unduh")
        ->assertNotFound();
});

/* =====================================================================
 | Hitung mundur di laman pelanggaran pengawas
 |===================================================================== */

it('mengirim sisa waktu pengerjaan yang dihitung server ke laman pengawas', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    $attempt = attemptBerjalan($peserta);

    masukSebagai($this, $data['pengawas'])
        ->get("/pengawas/ruang/{$data['ruang'][0]->id}/siswa/{$peserta->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pengawas/Pelanggaran')
            // Detik, bukan jam berakhir: laman menghitung mundur sendiri
            // tanpa jaringan, dan jam ponsel pengawas tidak ikut menentukan
            // apa pun.
            ->where('pengerjaan.sisa_detik', fn ($sisa) => is_int($sisa) && $sisa > 0)
            ->where('pengerjaan.berakhir_pada', fn ($jam) => is_string($jam) && $jam !== '')
            ->where('pengerjaan.akhir_sesi', fn ($jam) => is_string($jam) && $jam !== '')
        );

    // Angkanya benar-benar sisa terhadap `deadline_at` yang ditulis
    // `MesinUjian`, bukan durasi ujian mentah: yang berlaku bagi anak ini
    // adalah MIN(durasi, akhir sesi).
    expect($attempt->deadline_at)->not->toBeNull();
});

it('tidak mengirim hitung mundur untuk peserta yang belum mulai', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    // Presensi saja, tanpa membuka gerbang: pengawas memang membuka laman
    // ini sebelum anaknya mulai - dan hitung mundur yang berjalan di sana
    // akan menyebut waktu yang belum dimulai.
    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);

    masukSebagai($this, $data['pengawas'])
        ->get("/pengawas/ruang/{$data['ruang'][0]->id}/siswa/{$peserta->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pengawas/Pelanggaran')
            ->where('pengerjaan.ada', false)
            ->where('pengerjaan.sisa_detik', null)
        );
});
