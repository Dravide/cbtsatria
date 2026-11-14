<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\StudentAnswer;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Event\Models\EventClass;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Grading\Enums\StatusNilai;
use App\Domain\Grading\Exports\LaporanData;
use App\Domain\Grading\Jobs\BuatEksporLaporan;
use App\Domain\Grading\Models\ExamResult;
use App\Domain\Grading\Models\QuestionAnalysis;
use App\Domain\Grading\Models\ReportExport;
use App\Domain\Grading\Services\PenghitungAnalisis;
use App\Domain\Grading\Services\PenghitungHasil;
use App\Domain\Grading\Services\PenilaiUraian;
use App\Domain\Grading\Services\PublikasiHasil;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Fase 8 - penilaian, hasil, pelaporan. Wireframe plate 06, 07, 08, 21, 22, 28.
 *
 * =====================================================================
 * Yang diuji berkas ini adalah TIGA KALIMAT kriteria selesai blueprint
 * =====================================================================
 * `blueprint-cbt.html:1276` menutup Fase 8 dengan satu kalimat yang memuat
 * tiga syarat, dan berkas ini satu test untuk masing-masingnya:
 *
 *   1. "nilai akhir cocok dengan rumus PRD" - PRD §13.4:
 *      (total skor diperoleh / total skor maksimal) x 100. Yang membuatnya
 *      layak diuji bukan aritmetikanya, melainkan PENYEBUTNYA: bobot uraian
 *      yang belum dikoreksi tetap ikut dihitung, sehingga angkanya sementara
 *      lebih RENDAH, bukan dilebih-lebihkan. Test yang mengharapkan 100 saat
 *      uraian belum dinilai akan lulus hanya pada sistem yang berbohong.
 *
 *   2. "tombol publikasi tidak dapat menandai hasil yang masih menunggu
 *      koreksi" - dan yang membuktikannya adalah test yang MENCOBA
 *      menerbitkan hasil yang belum selesai, bukan test yang menerbitkan
 *      hasil yang sudah selesai.
 *
 *   3. "siswa hanya melihat nilainya sendiri" - dua siswa di satu ujian,
 *      dan yang diperiksa adalah ketiadaan baris siswa kedua di payload
 *      siswa pertama. Payload yang berisi kedua baris akan lulus setiap
 *      pemeriksaan "siswa bisa membuka lamannya".
 *
 * =====================================================================
 * Satu ujian dipakai berulang, dan itu bukan penghematan
 * =====================================================================
 * `siapkanUjianSiswa()` membangun satu event lengkap - kelas, mapel, ruang,
 * sesi, penempatan, pengawas, token. Membangunnya ulang di setiap test
 * berarti dua puluh kali pekerjaan yang sama, dan yang lebih buruk: setiap
 * test akan membuktikan hal yang berbeda tentang DATA, bukan tentang
 * penilaiannya. Berkas ini menguji penilaian, jadi datanya dibuat identik.
 *
 * Paketnya membawa EMPAT jenis - pilihan ganda (bobot 2), isian (3), uraian
 * (5), media (1) - sehingga total maksimalnya 11. Empat jenis itu dipilih
 * `paketUntukUjian()` dengan alasan yang sudah ditulis di sana: ketiganya
 * jalur penilaian yang berbeda, dan uraian adalah satu-satunya yang menahan
 * publikasi.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);
    $this->lain = School::factory()->create(['name' => 'SMK Negeri 2']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.nilai',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'Ganjil',
    ]);
});

/* =====================================================================
 | Pembantu berkas ini
 |===================================================================== */

/**
 * Guru yang benar-benar mengampu mapel ujian ini.
 *
 * Bukan sekadar `User::factory()->guru()`: `UjianGuru::satu()` dan
 * `GradingPolicy::mengampuUjian()` sama-sama menuntut penugasan, dan Guru
 * tanpa penugasan akan menerima 404 dari setiap laman Fase 8 - sehingga
 * test yang memakainya akan menguji gerbangnya, bukan penilaiannya.
 *
 * `TeachingAssignment` dipilih, bukan `EventTeacherAssignment`: keduanya
 * diterima policy, dan yang pertama sudah dipakai seeder Fase 4 - jadi
 * bentuknya sama dengan yang benar-benar ada di basis data produksi.
 */
function guruPenilai(Exam $ujian): User
{
    $kelas = EventClass::query()
        ->where('event_id', $ujian->event_id)
        ->firstOrFail();

    // Tahun ajarannya dibaca dari EVENT, bukan dari kelasnya: `event_classes`
    // hanya menyimpan pasangan event-kelas, dan tahun ajaran adalah properti
    // eventnya.
    $event = $ujian->event()->firstOrFail();

    $guru = User::factory()->guru()->untukSekolah(School::find($ujian->school_id))->create([
        'username' => 'guru.nilai.'.$ujian->id,
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

/**
 * Jawaban yang BENAR untuk satu butir, menurut snapshotnya.
 *
 * Dibaca dari `kunci_json` snapshot, bukan dari bank soal: yang dinilai
 * adalah snapshot, dan test yang menjawab dari bank soal akan lulus pada
 * sistem yang menilai bank soal - persis kesalahan yang dicegah PembekuPaket.
 *
 * @return array<string, mixed>|null
 */
function jawabanBenar(ExamQuestion $soal): ?array
{
    $kunci = $soal->kunci_json ?? [];

    return match ($soal->jenis) {
        JenisSoal::PilihanGanda,
        JenisSoal::BenarSalah,
        JenisSoal::JawabanGanda,
        JenisSoal::Media => ['opsi' => $kunci['opsi'] ?? []],

        JenisSoal::IsianSingkat => ['teks' => ($kunci['teks'] ?? [])[0] ?? ''],

        // Setiap lubang dijawab dengan varian pertamanya: itu satu-satunya
        // bentuk yang pasti bernilai penuh, dan test ini menguji mesin
        // penilaian - bukan ketahanan pencocokan teks.
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

/** Jawaban yang salah, untuk jenis yang bisa dipastikan salah. */
function jawabanSalah(ExamQuestion $soal): ?array
{
    return match ($soal->jenis) {
        JenisSoal::PilihanGanda, JenisSoal::BenarSalah, JenisSoal::Media => ['opsi' => ['b']],
        JenisSoal::IsianSingkat => ['teks' => 'delapan'],
        // Satu lubang dibiarkan BENAR dan sisanya salah, supaya bila jenis
        // ini muncul di paket contoh ujiannya tetap menerima skor sebagian
        // yang bukan nol - dan bila mesinnya mengabaikan lubang kosong,
        // test-nya menangkap itu sebagai nilai penuh yang tidak semestinya.
        JenisSoal::IsianRumpang => ['rumpang' => ['bukan-jawaban', '']],
        default => null,
    };
}

/**
 * Jawab seluruh butir satu attempt lewat endpoint yang dipakai siswa.
 *
 * Lewat HTTP, bukan lewat `JawabanService::simpan()`: yang diperiksa berkas
 * ini adalah nilai yang lahir dari ujian yang SUNGGUH berjalan, dan
 * simpanan langsung akan melewati validasi bentuk, `bersihkan()`, dan
 * penghitungan versi - tiga hal yang bisa mengubah isi jawaban sebelum ia
 * dinilai.
 */
function jawabSeluruhButir(TestCase $test, User $siswa, ExamParticipant $peserta, ?JenisSoal $salahkan = null): void
{
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

        $isi = $salahkan !== null && $soal->jenis === $salahkan
            ? jawabanSalah($soal)
            : jawabanBenar($soal);

        if ($isi === null) {
            continue;
        }

        masukUji($test, $siswa)
            ->postJson("/siswa/ujian/{$peserta->id}/butir/{$satu->id}", ['isi' => $isi])
            ->assertOk();
    }
}

/**
 * Satu siswa mengerjakan dari awal sampai dikumpulkan.
 *
 * Urutannya mengikuti produksi, bukan disusun ulang untuk test: presensi
 * pengawas, gerbang, jawaban, lalu Kumpulkan. Test yang memalsukan salah
 * satu langkah akan membuktikan bahwa penilaiannya bekerja ATAS KEADAAN
 * YANG TIDAK PERNAH TERJADI.
 */
function kerjakanUjian(TestCase $test, array $data, int $i, ?JenisSoal $salahkan = null): ExamAttempt
{
    $peserta = $data['peserta'][$i];

    verifikasiHadir($test, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($test, $data['siswa'][$i], $peserta, $data['token']);
    jawabSeluruhButir($test, $data['siswa'][$i], $peserta, $salahkan);

    masukUji($test, $data['siswa'][$i])
        ->post("/siswa/ujian/{$peserta->id}/selesai")
        ->assertRedirect();

    return attemptBerjalan($peserta);
}

/** Hasil satu peserta. */
function hasilPeserta(ExamParticipant $peserta): ExamResult
{
    return ExamResult::query()
        ->whereIn('exam_attempt_id', ExamAttempt::query()
            ->where('exam_participant_id', $peserta->id)
            ->select('id'))
        ->firstOrFail();
}

/** Jawaban uraian satu attempt - satu-satunya yang menahan publikasi. */
function jawabanUraian(ExamAttempt $attempt): StudentAnswer
{
    return StudentAnswer::query()
        ->whereHas('butir', fn ($q) => $q
            ->where('exam_attempt_id', $attempt->id)
            ->whereHas('soal', fn ($s) => $s->where('jenis', JenisSoal::Uraian->value)))
        ->firstOrFail();
}

/* =====================================================================
 | PRD §13.4 - rumus nilai akhir
 |===================================================================== */

it('menghitung nilai akhir menurut rumus PRD, dengan bobot uraian tetap di penyebut', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $attempt = kerjakanUjian($this, $data, 0);
    $hasil = hasilPeserta($data['peserta'][0]);

    // Objektif: PG 2 + isian 3 + media 1 = 6. Uraian (bobot 5) belum
    // dikoreksi, dan bobotnya TETAP masuk penyebut.
    expect((float) $hasil->automatic_score)->toBe(6.0)
        ->and((float) $hasil->essay_score)->toBe(0.0)
        ->and((float) $hasil->total_score)->toBe(6.0)
        ->and((float) $hasil->maximum_score)->toBe(11.0)
        // 6 / 11 x 100 = 54.5454... -> 54.55
        ->and((float) $hasil->final_percentage)->toBe(54.55)
        ->and($hasil->pending_essays)->toBe(1)
        // Satu uraian, dan satu-satunya uraian itu belum dikoreksi:
        // `sisaUraian === totalUraian`, jadi keadaannya `belum_dinilai`, bukan
        // `dinilai_sebagian`. Bedanya nyata di layar Guru - "belum mulai" dan
        // "setengah jalan" menuntut tindakan yang berbeda.
        ->and($hasil->grading_status)->toBe(StatusNilai::BelumDinilai)
        // Angka sementara tidak boleh pernah dianggap final - lihat PRD
        // §13.4: "hasil yang masih mempunyai essay belum terkoreksi tidak
        // boleh dianggap final".
        ->and($hasil->final())->toBeFalse()
        ->and($hasil->published_at)->toBeNull();

    expect($attempt->status)->toBe(ExamAttempt::STATUS_DIKIRIM);
});

it('menjadikan nilai final setelah uraiannya dikoreksi penuh', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    $attempt = kerjakanUjian($this, $data, 0);

    masukUji($this, $guru)
        ->post('/guru/koreksi/jawaban/'.jawabanUraian($attempt)->id, [
            'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 5]],
            'feedback' => 'Langkahnya lengkap.',
        ])
        ->assertRedirect();

    $hasil = hasilPeserta($data['peserta'][0])->refresh();

    expect((float) $hasil->essay_score)->toBe(5.0)
        ->and((float) $hasil->total_score)->toBe(11.0)
        ->and((float) $hasil->final_percentage)->toBe(100.0)
        ->and($hasil->pending_essays)->toBe(0)
        ->and($hasil->grading_status)->toBe(StatusNilai::DinilaiPenuh)
        ->and($hasil->final())->toBeTrue();
});

it('menurunkan nilai menurut poin yang benar-benar diberikan, bukan menurut rubriknya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    $attempt = kerjakanUjian($this, $data, 0, JenisSoal::PilihanGanda);

    // PG dijawab salah: objektif tinggal 3 + 1 = 4. Uraian diberi 3 dari 5.
    masukUji($this, $guru)
        ->post('/guru/koreksi/jawaban/'.jawabanUraian($attempt)->id, [
            'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 3]],
        ])
        ->assertRedirect();

    $hasil = hasilPeserta($data['peserta'][0])->refresh();

    // 4 + 3 = 7 dari 11 -> 63.64
    expect((float) $hasil->automatic_score)->toBe(4.0)
        ->and((float) $hasil->essay_score)->toBe(3.0)
        ->and((float) $hasil->final_percentage)->toBe(63.64);
});

it('menolak koreksi yang poin totalnya melebihi bobot butir', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    $attempt = kerjakanUjian($this, $data, 0);
    $jawaban = jawabanUraian($attempt);

    // Rubrik yang totalnya melebihi bobot adalah ujian yang nilainya bisa
    // melewati 100 - dan 100 adalah batas yang tidak bisa dijelaskan ke
    // siapa pun kalau terlampaui.
    expect(fn () => app(PenilaiUraian::class)->koreksi(
        $jawaban,
        $guru,
        [['kriteria' => 'Ketepatan konsep', 'poin' => 7]],
    ))->toThrow(ValidationException::class);

    expect((float) hasilPeserta($data['peserta'][0])->refresh()->essay_score)->toBe(0.0);
});

it('membiarkan nilai uraian null saat belum dikoreksi, bukan nol', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $attempt = kerjakanUjian($this, $data, 0);

    // `is_correct` uraian dibiarkan null oleh `PenilaiOtomatis`, dan itu yang
    // membedakan "belum dinilai" dari "salah" di seluruh laporan.
    expect(jawabanUraian($attempt)->is_correct)->toBeNull();

    $butir = AttemptQuestion::query()
        ->where('exam_attempt_id', $attempt->id)
        ->whereHas('soal', fn ($s) => $s->where('jenis', JenisSoal::Uraian->value))
        ->firstOrFail();

    // Dan yang objektif SUDAH dinilai saat dikumpulkan - satu penilaian,
    // bukan satu per penyegaran halaman.
    expect(AttemptQuestion::query()
        ->where('exam_attempt_id', $attempt->id)
        ->whereKeyNot($butir->id)
        ->whereHas('soal', fn ($s) => $s->where('jenis', '!=', JenisSoal::Uraian->value))
        ->whereHas('jawaban', fn ($j) => $j->whereNotNull('score_awarded'))
        ->count())->toBe(3);
});

/* =====================================================================
 | Gerbang publikasi - "tidak dapat menandai hasil yang masih menunggu"
 |===================================================================== */

it('menolak publikasi selama masih ada uraian yang belum dikoreksi', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    kerjakanUjian($this, $data, 0);
    kerjakanUjian($this, $data, 1);

    $event = $data['event']->refresh();

    expect(app(PublikasiHasil::class)->sisaKoreksi($event))->toBe(2);

    masukUji($this, $this->admin)
        ->post("/admin/events/{$event->id}/hasil/terbitkan")
        ->assertSessionHasErrors('publikasi');

    // Dan yang menentukan: eventnya TIDAK berubah. Gerbang yang menolak
    // tetapi tetap menulis kolomnya adalah gerbang yang tidak menolak apa pun.
    expect($event->refresh()->results_published_at)->toBeNull()
        ->and($event->hasilTerbit())->toBeFalse()
        ->and($event->koreksiTerkunci())->toBeFalse()
        ->and(hasilPeserta($data['peserta'][0])->published_at)->toBeNull();
});

it('tidak menghitung uraian yang TIDAK dijawab sebagai pekerjaan yang menunggu', function () {
    // Keadaan yang paling sering terjadi di ruang ujian, dan yang paling
    // mudah membuat tombol publikasi mati selamanya: siswa menekan Kumpulkan
    // dengan satu uraian yang tidak ia tulis sama sekali.
    //
    // `JawabanService` hanya membuat baris `student_answers` saat ada yang
    // disimpan, sehingga uraian kosong TIDAK punya baris - dan antrean koreksi
    // (plate 06) membaca baris, bukan butir. Kalau keadaan itu tetap dihitung
    // `pending_essays`, Admin menerima "masih ada N jawaban uraian yang belum
    // dikoreksi" untuk N yang tidak ada di antrean siapa pun: Guru tidak dapat
    // mengoreksinya, dan publikasi tidak akan pernah bisa dijalankan.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    $butir = AttemptQuestion::query()
        ->where('exam_attempt_id', attemptBerjalan($peserta)->id)
        ->with('soal')
        ->orderBy('display_order')
        ->get();

    // Seluruh butir dijawab KECUALI uraian.
    foreach ($butir as $satu) {
        if ($satu->soal?->jenis === JenisSoal::Uraian) {
            continue;
        }

        masukUji($this, $data['siswa'][0])
            ->postJson("/siswa/ujian/{$peserta->id}/butir/{$satu->id}", ['isi' => jawabanBenar($satu->soal)])
            ->assertOk();
    }

    masukUji($this, $data['siswa'][0])->post("/siswa/ujian/{$peserta->id}/selesai")->assertRedirect();

    $hasil = hasilPeserta($peserta)->refresh();
    $uraian = $butir->first(fn (AttemptQuestion $b) => $b->soal?->jenis === JenisSoal::Uraian);

    // Prasyaratnya dibuktikan lebih dulu: barisnya memang tidak ada, sehingga
    // test ini benar-benar menguji keadaan yang dimaksud - bukan keadaan lain
    // yang kebetulan juga menghasilkan pending 0.
    expect($uraian->fresh()->jawaban)->toBeNull()
        ->and($hasil->pending_essays)->toBe(0)
        ->and($hasil->grading_status)->toBe(StatusNilai::DinilaiPenuh)
        ->and($hasil->final())->toBeTrue();

    // Objektifnya benar, dan uraian kosong menyumbang nol - tetapi bobotnya
    // TETAP di penyebut, sehingga nilainya bukan 100.
    expect((float) $hasil->automatic_score)->toBe(6.0)
        ->and((float) $hasil->essay_score)->toBe(0.0)
        ->and((float) $hasil->maximum_score)->toBe(11.0);

    // Dan yang menentukan: publikasinya benar-benar BISA dijalankan.
    $event = $data['event']->refresh();

    expect(app(PublikasiHasil::class)->sisaKoreksi($event))->toBe(0);

    masukUji($this, $this->admin)
        ->post("/admin/events/{$event->id}/hasil/terbitkan")
        ->assertRedirect();

    expect($event->refresh()->hasilTerbit())->toBeTrue();
});

it('menerbitkan seluruh hasil event setelah uraian terakhir dikoreksi', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    $attempt0 = kerjakanUjian($this, $data, 0);
    $attempt1 = kerjakanUjian($this, $data, 1);

    foreach ([$attempt0, $attempt1] as $attempt) {
        masukUji($this, $guru)
            ->post('/guru/koreksi/jawaban/'.jawabanUraian($attempt)->id, [
                'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 5]],
            ])
            ->assertRedirect();
    }

    $event = $data['event']->refresh();

    expect(app(PublikasiHasil::class)->sisaKoreksi($event))->toBe(0);

    masukUji($this, $this->admin)
        ->post("/admin/events/{$event->id}/hasil/terbitkan")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $event->refresh();

    // Publikasi adalah satu aksi untuk SELURUH event - dua hasil, satu
    // tombol, dan dua-duanya terbit.
    expect($event->hasilTerbit())->toBeTrue()
        ->and($event->koreksiTerkunci())->toBeTrue()
        ->and($event->results_published_by)->toBe($this->admin->id);

    foreach ([$data['peserta'][0], $data['peserta'][1]] as $peserta) {
        $hasil = hasilPeserta($peserta)->refresh();

        // Keadaan TERSIMPANNYA tetap `dinilai_penuh`, dan itu disengaja:
        // `terbit` bukan langkah berikutnya dalam rantai penilaian, melainkan
        // fakta yang dibaca dari `published_at`. Yang membuktikan publikasi
        // adalah kolom tanggalnya, bukan nilai enumnya - lihat catatan kepala
        // `StatusNilai`.
        expect($hasil->published_at)->not->toBeNull()
            ->and($hasil->grading_status)->toBe(StatusNilai::DinilaiPenuh)
            // Dan `final()` tetap benar: nilai yang sudah dibaca 300 siswa
            // tidak berhenti menjadi nilai akhir karena Admin membuka kembali
            // satu kunci.
            ->and($hasil->final())->toBeTrue();
    }

    // Satu baris log, dan isinya menyebut berapa hasil yang ikut terbit -
    // catatan yang menjawab "siapa menerbitkan apa, kapan".
    $log = ActivityLog::query()->where('action', 'hasil_dipublikasikan')->firstOrFail();

    expect($log->entity_id)->toBe($event->id)
        ->and($log->metadata_json['hasil'])->toBe(2);
});

it('mengunci koreksi begitu hasilnya terbit, dan menolak koreksi sesudahnya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    $attempt = kerjakanUjian($this, $data, 0);
    $jawaban = jawabanUraian($attempt);

    masukUji($this, $guru)
        ->post("/guru/koreksi/jawaban/{$jawaban->id}", [
            'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 4]],
        ])
        ->assertRedirect();

    masukUji($this, $this->admin)
        ->post("/admin/events/{$data['event']->id}/hasil/terbitkan")
        ->assertRedirect();

    // Guru mencoba memperbaiki poinnya sesudah siswa melihat nilainya.
    // Penolakannya di SERVICE, bukan di tombol: satu permintaan langsung
    // tidak boleh bisa menimpa nilai yang sudah dibaca seluruh angkatan.
    masukUji($this, $guru)
        ->post("/guru/koreksi/jawaban/{$jawaban->id}", [
            'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 5]],
        ])
        ->assertSessionHasErrors('koreksi');

    expect((float) hasilPeserta($data['peserta'][0])->refresh()->essay_score)->toBe(4.0);
});

it('membuka laman koreksi satu jawaban sebelum dan sesudah publikasi', function () {
    // Halaman panel koreksi TIDAK PERNAH dibuka test mana pun sebelum ini,
    // dan justru di sanalah satu galat produksi lahir: `UjianGuru` memuat
    // event dengan select sempit (`event:id,name`), sehingga
    // `SchoolEvent::koreksiTerkunci()` mengembalikan null dan melempar
    // TypeError - halaman koreksi rusak total, padahal datanya benar.
    //
    // Test ini membuka kedua keadaannya. Yang pertama menjaga "bisa dibuka",
    // yang kedua menjaga bahwa nilai `terkunci` benar-benar mengikuti event
    // dan bukan sekadar tidak meledak.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    $attempt = kerjakanUjian($this, $data, 0);
    $jawaban = jawabanUraian($attempt);

    masukUji($this, $guru)
        ->post("/guru/koreksi/jawaban/{$jawaban->id}", [
            'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 4]],
        ])
        ->assertRedirect();

    masukUji($this, $guru)
        ->get("/guru/koreksi/jawaban/{$jawaban->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guru/Koreksi/Jawaban')
            ->where('terkunci', false)
            ->where('jawaban.id', $jawaban->id)
            ->where('koreksi.score', 4)
            // BENTUKNYA, bukan sekadar isinya: `soal_json` adalah
            // `['teks' => '...']`, dan mengirim array itu apa adanya membuat
            // React melempar "Objects are not valid as a React child" -
            // halaman koreksi BLANK, sementara server membalas 200.
            ->where('soal.teks', fn ($teks) => is_string($teks))
        );

    masukUji($this, $this->admin)
        ->post("/admin/events/{$data['event']->id}/hasil/terbitkan")
        ->assertRedirect();

    // Sesudah publikasi panelnya TETAP terbuka - Guru masih boleh membacanya,
    // yang tertutup hanya penyuntingannya. Membalas 403 di sini akan
    // menyembunyikan nilai yang menjadi dasar anaknya, dan itu bukan kunci.
    masukUji($this, $guru)
        ->get("/guru/koreksi/jawaban/{$jawaban->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guru/Koreksi/Jawaban')
            ->where('terkunci', true)
        );
});

it('membuka kembali koreksi tanpa mencabut publikasinya, dengan alasan yang tercatat', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    $attempt = kerjakanUjian($this, $data, 0);
    $jawaban = jawabanUraian($attempt);

    masukUji($this, $guru)
        ->post("/guru/koreksi/jawaban/{$jawaban->id}", [
            'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 4]],
        ])
        ->assertRedirect();

    masukUji($this, $this->admin)
        ->post("/admin/events/{$data['event']->id}/hasil/terbitkan")
        ->assertRedirect();

    // Alasan wajib. Yang membedakan "salah input" dari "kunci soalnya memang
    // keliru" hanya catatannya - dan keduanya mengubah nilai anak yang sama.
    masukUji($this, $this->admin)
        ->post("/admin/events/{$data['event']->id}/hasil/buka-koreksi", ['alasan' => ''])
        ->assertSessionHasErrors('alasan');

    masukUji($this, $this->admin)
        ->post("/admin/events/{$data['event']->id}/hasil/buka-koreksi", [
            'alasan' => 'Kunci soal nomor 3 tertukar; hasilnya dihitung ulang.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $event = $data['event']->refresh();

    // Yang berubah HANYA kuncinya. Hasil yang sudah dibaca 300 siswa tetap
    // terbaca - keputusan blueprint #10.
    expect($event->koreksiTerkunci())->toBeFalse()
        ->and($event->hasilTerbit())->toBeTrue()
        ->and(hasilPeserta($data['peserta'][0])->published_at)->not->toBeNull();

    $log = ActivityLog::query()->where('action', 'koreksi_dibuka')->firstOrFail();

    expect($log->metadata_json['alasan'])->toContain('tertukar')
        ->and($log->metadata_json['hasil_terbit'])->toBeTrue();

    // Dan Guru benar-benar bisa mengoreksi lagi setelahnya.
    masukUji($this, $guru)
        ->post("/guru/koreksi/jawaban/{$jawaban->id}", [
            'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 5]],
        ])
        ->assertSessionHasNoErrors();

    expect((float) hasilPeserta($data['peserta'][0])->refresh()->essay_score)->toBe(5.0);
});

it('menarik publikasi beserta kuncinya, dan mencatat alasannya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    $attempt = kerjakanUjian($this, $data, 0);

    masukUji($this, $guru)
        ->post('/guru/koreksi/jawaban/'.jawabanUraian($attempt)->id, [
            'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 5]],
        ])
        ->assertRedirect();

    masukUji($this, $this->admin)->post("/admin/events/{$data['event']->id}/hasil/terbitkan");

    masukUji($this, $this->admin)
        ->post("/admin/events/{$data['event']->id}/hasil/tarik", [
            'alasan' => 'Satu kelas belum menyelesaikan ujiannya.',
        ])
        ->assertRedirect();

    $event = $data['event']->refresh();

    // Hasil yang tidak terbit tidak punya alasan untuk tetap terkunci -
    // keduanya berubah bersama, dan itu bukan kerapian.
    expect($event->hasilTerbit())->toBeFalse()
        ->and($event->koreksiTerkunci())->toBeFalse()
        ->and(hasilPeserta($data['peserta'][0])->refresh()->published_at)->toBeNull();
});

it('menolak mengumumkan hasil yang belum diterbitkan', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    // Publikasi dan pengumuman adalah DUA tombol, dan pemisahannya bukan
    // pilihan desain: hasil bisa sudah dibuka sementara pengumumannya
    // ditahan sampai rapat guru selesai.
    expect(fn () => app(PublikasiHasil::class)->umumkan($data['event']->refresh(), $this->admin))
        ->toThrow(ValidationException::class);

    expect($data['event']->refresh()->results_announced_at)->toBeNull();
});

/* =====================================================================
 | Plate 28 - "siswa hanya melihat nilainya sendiri"
 |===================================================================== */

it('menyembunyikan seluruh nilai selama hasil eventnya belum terbit', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    $attempt = kerjakanUjian($this, $data, 0);

    masukUji($this, $guru)
        ->post('/guru/koreksi/jawaban/'.jawabanUraian($attempt)->id, [
            'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 5]],
        ])
        ->assertRedirect();

    // Nilainya SUDAH ada dan sudah final - yang belum ada adalah izinnya.
    expect(hasilPeserta($data['peserta'][0])->refresh()->final())->toBeTrue();

    masukUji($this, $data['siswa'][0])
        ->get('/siswa/nilai')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Siswa/Nilai')
            ->where('ada_nilai', false)
            ->where('menunggu_terbit', true)
            ->where('daftar.0.nilai', null)
            ->where('daftar.0.final', false)
            // Null, bukan 0: baris berisi nol di bawah judul "Nilai" terbaca
            // sebagai nilai yang benar-benar nol.
            ->where('daftar.0.lulus', null)
            ->where('daftar.0.ujian', $data['ujian']->title)
        );
});

it('menampilkan nilai sendiri dan TIDAK menampilkan baris siswa lain', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    // Dua siswa, dua nilai yang berbeda: yang pertama menjawab semuanya
    // benar, yang kedua salah pada pilihan gandanya. Tanpa perbedaan angka,
    // test kebocoran tidak bisa membedakan nilai siapa yang terlihat.
    $attempt0 = kerjakanUjian($this, $data, 0);
    $attempt1 = kerjakanUjian($this, $data, 1, JenisSoal::PilihanGanda);

    foreach ([$attempt0, $attempt1] as $attempt) {
        masukUji($this, $guru)
            ->post('/guru/koreksi/jawaban/'.jawabanUraian($attempt)->id, [
                'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 5]],
            ])
            ->assertRedirect();
    }

    masukUji($this, $this->admin)
        ->post("/admin/events/{$data['event']->id}/hasil/terbitkan")
        ->assertRedirect();

    masukUji($this, $data['siswa'][0])
        ->get('/siswa/nilai')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Siswa/Nilai')
            ->where('ada_nilai', true)
            ->where('menunggu_terbit', false)
            ->where('nama', $data['siswa'][0]->name)
            // SATU baris, milik anak yang sedang masuk. Sistem yang
            // mengirim seluruh penempatan sekolah akan lulus setiap
            // pemeriksaan "siswa bisa membuka lamannya".
            ->has('daftar', 1)
            ->where('daftar.0.peserta_id', $data['peserta'][0]->id)
            // Nama event IKUT dikirim - ditambahkan 2026-11-14. Anak yang
            // punya Informatika di dua periode melihat dua baris yang
            // mapelnya sama persis, dan hanya nama event yang membedakannya.
            ->where('daftar.0.event', $data['event']->name)
            // JSON menulis 100.0 sebagai 100 - bilangan bulat tidak punya
            // bagian desimal untuk dipertahankan.
            ->where('daftar.0.nilai', 100)
            ->where('daftar.0.final', true)
            ->where('daftar.0.lulus', true)
            ->where('remedial', [])
        );

    // Dan anak yang nilainya lebih rendah memang TIDAK melihat 100.
    masukUji($this, $data['siswa'][1])
        ->get('/siswa/nilai')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('daftar', 1)
            ->where('daftar.0.peserta_id', $data['peserta'][1]->id)
            // 9 / 11 x 100 = 81.82
            ->where('daftar.0.nilai', 81.82)
        );
});

it('menyembunyikan rincian objektif saat Admin mematikannya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    $attempt = kerjakanUjian($this, $data, 0);

    masukUji($this, $guru)
        ->post('/guru/koreksi/jawaban/'.jawabanUraian($attempt)->id, [
            'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 5]],
        ])
        ->assertRedirect();

    masukUji($this, $this->admin)
        ->post("/admin/events/{$data['event']->id}/hasil/terbitkan")
        ->assertRedirect();

    masukUji($this, $this->admin)
        ->post("/admin/events/{$data['event']->id}/hasil/tampilan", [
            'tampilkan_objektif' => false,
            'tampilkan_pembahasan' => false,
        ])
        ->assertRedirect();

    // `show_objectives` mengatur RINCIAN, bukan boleh-tidaknya angka:
    // menyatukan keduanya berarti satu centang menyembunyikan nilai akhir.
    masukUji($this, $data['siswa'][0])
        ->get('/siswa/nilai')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('daftar.0.objektif', null)
            ->where('daftar.0.nilai', 100)
        );
});

/* =====================================================================
 | Otorisasi - Admin tidak mengoreksi, Guru tidak mengekspor
 |===================================================================== */

it('menolak Admin membuka antrean koreksi, dan Guru membuka laporan', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    // Udara di antara keduanya disengaja: Guru yang bisa menerbitkan sendiri
    // akan menerbitkan sebelum uraiannya selesai dikoreksi, dan Admin yang
    // bisa mengoreksi akan menjadi pengoreksi cadangan yang tidak mengajar.
    masukUji($this, $this->admin)->get('/guru/koreksi')->assertForbidden();
    masukUji($this, $guru)->get('/admin/laporan')->assertForbidden();
});

it('menjawab 404 untuk ujian milik Guru lain, bukan 403', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $lain = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'guru.lain',
    ]);

    // 403 akan memberi tahu bahwa id itu menunjuk ujian yang benar-benar ada.
    masukUji($this, $lain)->get("/guru/koreksi/{$data['ujian']->id}")->assertNotFound();
    masukUji($this, $lain)->get("/guru/nilai/{$data['ujian']->id}")->assertNotFound();
});

it('menjawab 404 untuk ujian sekolah lain', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $penyusup = User::factory()->guru()->untukSekolah($this->lain)->create([
        'username' => 'guru.penyusup',
    ]);

    masukUji($this, $penyusup)->get("/guru/koreksi/{$data['ujian']->id}")->assertNotFound();
    masukUji($this, $penyusup)->get("/admin/events/{$data['event']->id}/hasil")->assertNotFound();
});

/* =====================================================================
 | Plate 07 - nilai kelas
 |===================================================================== */

it('menandai uraian yang belum dikoreksi sebagai belum, bukan sebagai nol', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    kerjakanUjian($this, $data, 0);

    masukUji($this, $guru)
        ->get("/guru/nilai/{$data['ujian']->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guru/Nilai/Ujian')
            ->where('kkm', 70)
            // `baris` adalah paginator, bukan larik: `TabelQuery::buat()`
            // mengembalikan `LengthAwarePaginator`, dan `baris.data` yang
            // memuat hasilnya.
            ->where('baris.total', 1)
            ->where('baris.data.0.nama', $data['siswa'][0]->name)
            // Nilai uraian yang belum dikoreksi tidak ikut dihitung sebagai
            // nol - menampilkan 0 akan menurunkan rata-rata kelas karena
            // pekerjaan Guru yang belum selesai.
            ->where('baris.data.0.uraian', null)
            ->where('baris.data.0.final', false)
            ->where('baris.data.0.lulus', null)
            ->where('baris.data.0.status', 'belum_dinilai')
            ->where('baris.data.0.nilai', 54.55)
        );
});

it('menghitung ulang seluruh hasil satu ujian dari tombolnya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    kerjakanUjian($this, $data, 0);

    // Kunci soal diperbaiki Admin di plate 15 - nilai yang tersimpan dihitung
    // dari kunci LAMA, dan tanpa tombol ini satu-satunya jalan memperbaikinya
    // adalah menjalankan artisan dari server.
    $dihitung = app(PenghitungHasil::class)->hitungUlangUjian($data['ujian']->id);

    expect($dihitung)->toBe(1)
        ->and((float) hasilPeserta($data['peserta'][0])->refresh()->final_percentage)->toBe(54.55);

    masukUji($this, $this->admin)
        ->post("/admin/events/{$data['event']->id}/hasil/hitung-ulang")
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

/* =====================================================================
 | Plate 08 - analisis butir
 |===================================================================== */

it('menyimpan analisis setiap butir dan memutuskan nasibnya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    kerjakanUjian($this, $data, 0);
    kerjakanUjian($this, $data, 1, JenisSoal::PilihanGanda);

    $jumlah = app(PenghitungAnalisis::class)->hitungUlang($data['ujian']->id);

    // EmpAT butir diperiksa, TIGA baris ditulis: uraian punya `is_correct`
    // yang selalu null - nilainya parsial, dan "benar" bukan pertanyaan yang
    // bisa dijawab untuk sebuah rubrik. Menganalisisnya akan menghasilkan
    // persen benar yang selalu nol dan putusan "sulit" untuk setiap soal
    // uraian, yang menyesatkan Guru yang membacanya.
    expect($jumlah)->toBe(4)
        ->and(QuestionAnalysis::query()->count())->toBe(3);

    $uraian = ExamQuestion::query()
        ->where('exam_id', $data['ujian']->id)
        ->where('jenis', JenisSoal::Uraian->value)
        ->firstOrFail();

    expect(QuestionAnalysis::query()->where('exam_question_id', $uraian->id)->exists())->toBeFalse();

    // Butir pilihan ganda dijawab benar oleh satu dari dua peserta: 50% -
    // di antara dua ambang, jadi putusannya "baik". Daya bedanya null karena
    // dua peserta tidak membentuk kelompok yang bermakna.
    $pg = ExamQuestion::query()
        ->where('exam_id', $data['ujian']->id)
        ->where('jenis', JenisSoal::PilihanGanda->value)
        ->firstOrFail();

    $analisis = QuestionAnalysis::query()->where('exam_question_id', $pg->id)->firstOrFail();

    expect($analisis->answered_count)->toBe(2)
        ->and($analisis->correct_count)->toBe(1)
        ->and((float) $analisis->percent_correct)->toBe(50.0)
        ->and($analisis->discrimination)->toBeNull()
        ->and($analisis->verdict)->toBe(QuestionAnalysis::PUTUSAN_BAIK);

    // Dan tombolnya hidup untuk Guru yang mengampu.
    masukUji($this, $guru)
        ->post("/guru/analisis/{$data['ujian']->id}/hitung-ulang")
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

/**
 * Ambang putusannya, dan urutannya.
 *
 * `periksa kunci` diperiksa LEBIH DULU, dan itu satu-satunya bagian yang
 * tidak bisa dibuktikan lewat laman: butir dengan daya beda negatif dan
 * persentase benar 90% memenuhi syarat "terlalu mudah", tetapi sebabnya
 * adalah kunci yang tertukar. Menampilkan "terlalu mudah" di sana
 * menyarankan Guru membuang soal yang sebenarnya hanya salah kunci.
 */
it('memutuskan periksa kunci lebih dulu daripada terlalu mudah', function () {
    expect(QuestionAnalysis::putuskan(90.0, -0.30))->toBe(QuestionAnalysis::PUTUSAN_PERIKSA_KUNCI)
        ->and(QuestionAnalysis::putuskan(90.0, 0.10))->toBe(QuestionAnalysis::PUTUSAN_PERIKSA_KUNCI)
        ->and(QuestionAnalysis::putuskan(90.0, 0.50))->toBe(QuestionAnalysis::PUTUSAN_TERLALU_MUDAH)
        ->and(QuestionAnalysis::putuskan(20.0, 0.50))->toBe(QuestionAnalysis::PUTUSAN_SULIT)
        ->and(QuestionAnalysis::putuskan(60.0, 0.50))->toBe(QuestionAnalysis::PUTUSAN_BAIK)
        // Daya beda yang tidak bisa dihitung BUKAN nol: butir yang baru
        // dikerjakan dua orang tidak boleh muncul sebagai peringatan merah.
        ->and(QuestionAnalysis::putuskan(90.0, null))->toBe(QuestionAnalysis::PUTUSAN_TERLALU_MUDAH);
});

/* =====================================================================
 | Plate 22 - laporan & ekspor
 |===================================================================== */

it('menyajikan kelima jenis laporan dari satu sumber data', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    $attempt = kerjakanUjian($this, $data, 0);

    masukUji($this, $guru)
        ->post('/guru/koreksi/jawaban/'.jawabanUraian($attempt)->id, [
            'kriteria' => [['kriteria' => 'Ketepatan konsep', 'poin' => 5]],
        ])
        ->assertRedirect();

    $event = $data['event']->refresh();
    app(PublikasiHasil::class)->terbitkan($event, $this->admin);

    foreach (array_keys(LaporanData::JENIS) as $jenis) {
        $baris = LaporanData::ambil($jenis, $event->id, []);
        $kolom = LaporanData::kolom($jenis);

        // Berkas kosong tanpa kepala adalah berkas yang dibuka Excel lalu
        // ditutup - Admin tidak tahu apakah ekspornya gagal atau datanya
        // memang kosong.
        expect($kolom)->not->toBeEmpty();

        foreach ($baris as $satu) {
            expect(array_keys($satu))->toBe(array_keys($kolom));
        }
    }

    // Jenis yang benar-benar punya baris: satu hasil, satu peserta.
    $nilai = LaporanData::ambil('nilai', $event->id, []);

    expect($nilai)->toHaveCount(1)
        ->and($nilai->first()['nama'])->toBe($data['siswa'][0]->name)
        ->and($nilai->first()['nilai'])->toBe(100.0)
        ->and($nilai->first()['status'])->toBe('tuntas');

    // Penyaring kelas dan mapel menyaring, bukan mengabaikan.
    expect(LaporanData::ambil('nilai', $event->id, ['mapel' => 999999]))->toHaveCount(0);
});

it('menolak permintaan ekspor saat belum ada event aktif', function () {
    masukUji($this, $this->admin)
        ->post('/admin/laporan', ['jenis' => 'nilai', 'format' => 'xlsx'])
        ->assertRedirect()
        ->assertSessionHas('galat');

    // Laporan selalu milik SATU event. Menebak eventnya berarti mengekspor
    // laporan yang tidak diminta siapa pun.
    expect(ReportExport::query()->count())->toBe(0);
});

it('menulis berkas ekspor ke disk privat, dalam tiga format', function () {
    Storage::fake('local');

    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    kerjakanUjian($this, $data, 0);

    $event = $data['event']->refresh();

    foreach (['xlsx', 'csv', 'pdf'] as $format) {
        masukUji($this, $this->admin)
            ->withSession(['event_aktif' => $event->id])
            ->post('/admin/laporan', ['jenis' => 'nilai', 'format' => $format])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $ekspor = ReportExport::query()->orderByDesc('id')->firstOrFail();

        // Antreannya `sync` di test, jadi barisnya sudah selesai saat
        // permintaannya kembali - tetapi jalurnya tetap satu, dan itu yang
        // membuat ekspor yang lambat tidak butuh kode kedua.
        expect($ekspor->status)->toBe(ReportExport::STATUS_SELESAI)
            ->and($ekspor->jenis)->toBe('nilai')
            ->and($ekspor->format)->toBe($format)
            ->and($ekspor->total_baris)->toBe(1)
            ->and($ekspor->ukuran)->toBeGreaterThan(0)
            ->and($ekspor->pesan_gagal)->toBeNull()
            // Nama acak di DEPAN: berkas di disk privat tidak bisa ditebak.
            ->and($ekspor->nama_berkas)->toEndWith('-nilai-per-siswa.'.$format);

        Storage::disk('local')->assertExists($ekspor->path);

        masukUji($this, $this->admin)
            ->get("/admin/laporan/berkas/{$ekspor->id}")
            ->assertOk();
    }
});

it('menulis kepala kolom meski laporannya kosong', function () {
    Storage::fake('local');

    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $event = $data['event']->refresh();

    masukUji($this, $this->admin)
        ->withSession(['event_aktif' => $event->id])
        ->post('/admin/laporan', ['jenis' => 'kehadiran', 'format' => 'csv'])
        ->assertRedirect();

    $ekspor = ReportExport::query()->firstOrFail();

    expect($ekspor->status)->toBe(ReportExport::STATUS_SELESAI);

    $isi = Storage::disk('local')->get($ekspor->path);

    // BOM UTF-8 lebih dulu, lalu kepala kolom - tanpa BOM, Excel di Windows
    // membaca nama berhuruf beraksen sebagai karakter rusak.
    expect($isi)->toStartWith("\xEF\xBB\xBF")
        ->and($isi)->toContain('Nama');
});

it('menolak ekspor milik permintaan sekolah lain', function () {
    Storage::fake('local');

    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $event = $data['event']->refresh();

    masukUji($this, $this->admin)
        ->withSession(['event_aktif' => $event->id])
        ->post('/admin/laporan', ['jenis' => 'nilai', 'format' => 'csv']);

    $ekspor = ReportExport::query()->firstOrFail();

    $adminLain = User::factory()->admin()->untukSekolah($this->lain)->create([
        'username' => 'admin.lain',
    ]);

    // Scope tenant menyembunyikan barisnya, sehingga binding melempar 404 -
    // bukan 403 yang membuktikan barisnya ada.
    masukUji($this, $adminLain)->get("/admin/laporan/berkas/{$ekspor->id}")->assertNotFound();
});

it('menyimpan penyaring ekspor, bukan hasilnya', function () {
    Storage::fake('local');

    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $event = $data['event']->refresh();

    masukUji($this, $this->admin)
        ->withSession(['event_aktif' => $event->id])
        ->post('/admin/laporan', [
            'jenis' => 'nilai',
            'format' => 'csv',
            'mapel' => $data['ujian']->subject_id,
        ])
        ->assertRedirect();

    $ekspor = ReportExport::query()->firstOrFail();

    // Ekspor yang gagal harus bisa diulang dengan penyaring yang PERSIS sama,
    // dan itu menuntut penyaringnya yang tersimpan.
    expect($ekspor->parameter_json['event_id'])->toBe($event->id)
        ->and($ekspor->parameter_json['mapel'])->toBe($data['ujian']->subject_id);
});

it('menandai ekspor yang gagal alih-alih membiarkannya menggantung', function () {
    Storage::fake('local');

    $ekspor = new ReportExport;
    $ekspor->forceFill([
        'school_id' => $this->sekolah->id,
        'requested_by' => $this->admin->id,
        'jenis' => 'entah',
        // Jenis yang tidak dikenal: `LaporanData::ambil()` mengembalikan
        // koleksi kosong, tetapi `kolom()` tidak punya cabang untuknya dan
        // mengembalikan null - yang meledak saat kepala kolomnya ditulis.
        'format' => 'csv',
        'parameter_json' => ['event_id' => 999999],
        'status' => ReportExport::STATUS_MENUNGGU,
        'created_at' => now(),
    ])->save();

    (new BuatEksporLaporan($ekspor->id))->handle();

    $ekspor->refresh();

    // Admin melihat "Gagal" beserta pesannya - bukan "Diproses" selamanya,
    // yang adalah keadaan yang tidak bisa ia bedakan dari "antreannya macet".
    expect($ekspor->status)->toBe(ReportExport::STATUS_GAGAL)
        ->and($ekspor->pesan_gagal)->not->toBeNull()
        ->and($ekspor->finished_at)->not->toBeNull();
});

/* =====================================================================
 | Peta rute Fase 8 - keenam lamannya hidup
 |===================================================================== */

it('membuka keenam laman Fase 8 dengan komponen yang benar', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $guru = guruPenilai($data['ujian']);

    // Tanpa satu baris ini, satu rute yang hilang dari berkas rute akan
    // menjadi 404 yang tidak diperiksa siapa pun - dan laman yang tidak
    // diperiksa adalah laman yang bisa mati tanpa ada yang tahu.
    masukUji($this, $guru)->get('/guru/koreksi')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Guru/Koreksi/Index'));

    masukUji($this, $guru)->get('/guru/nilai')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Guru/Nilai/Index'));

    masukUji($this, $guru)->get('/guru/analisis')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Guru/Analisis/Index'));

    masukUji($this, $this->admin)->get("/admin/events/{$data['event']->id}/hasil")
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Admin/Event/Hasil')
            // Tombolnya mati karena masih ada uraian menunggu - dan
            // sebabnya ikut dikirim, supaya laman bisa menulis kalimatnya
            // alih-alih menampilkan tombol mati tanpa penjelasan.
        ->where('publikasi.boleh_terbit', true)
        ->where('publikasi.terbit', false)
        ->where('publikasi.terkunci', false)
        );

    masukUji($this, $this->admin)->get('/admin/laporan')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Admin/Laporan')
        ->where('event', null)
        );

    masukUji($this, $data['siswa'][0])->get('/siswa/nilai')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Siswa/Nilai'));
});

it('memadamkan tombol publikasi saat masih ada uraian menunggu', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    kerjakanUjian($this, $data, 0);
    kerjakanUjian($this, $data, 1);

    masukUji($this, $this->admin)
        ->get("/admin/events/{$data['event']->id}/hasil")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('publikasi.boleh_terbit', false)
            ->where('publikasi.sisa_koreksi', 2)
            ->where('publikasi.ujian_tertahan', 1)
            ->where('publikasi.total_ujian', 1)
            ->where('ujian.0.sisa_uraian', 2)
            ->where('ujian.0.uraian_dinilai', 0)
            ->where('ujian.0.uraian_total', 2)
        );
});

it('mengalihkan pengunjung yang belum masuk dari keenam laman Fase 8', function () {
    app(SchoolResolver::class)->forget();

    $this->get('/guru/koreksi')->assertRedirect('/login');
    $this->get('/guru/nilai')->assertRedirect('/login');
    $this->get('/guru/analisis')->assertRedirect('/login');
    $this->get('/admin/laporan')->assertRedirect('/login');
    $this->get('/siswa/nilai')->assertRedirect('/login');
});
