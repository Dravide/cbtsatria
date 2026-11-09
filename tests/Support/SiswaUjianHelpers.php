<?php

/*
| Helper bersama untuk test.
|
| Berkas ini dulunya tinggal DI DALAM berkas test-nya sendiri - `masukSebagai`
| di ExamTest, `siapkanPresensi` di AttendanceTest, dan seterusnya. Fungsi PHP
| bersifat global, jadi selama suite dijalankan SELURUHNYA, helper itu saling
| terlihat dan semua hijau.
|
| Yang rusak adalah menjalankan satu berkas sendirian. `php artisan test
| tests/Feature/PenjadwalTest.php` menjawab "Call to undefined function
| siapkanUjianSiswa()" - bukan karena ada yang salah dengan PenjadwalTest,
| tetapi karena helper yang ia pakai kebetulan didefinisikan di berkas lain
| yang tidak ikut dimuat. Tujuh berkas terkena, dan itu menjebak justru saat
| orang paling butuh jawabannya: suite penuh hijau, sementara menunjuk satu
| berkas yang sedang diperiksa hasilnya galat yang menyesatkan.
|
| Karena itu helper-nya pindah ke sini. `tests/Pest.php` memuat seluruh isi
| direktori ini lebih dulu, jadi setiap berkas test melihatnya - dijalankan
| sendiri maupun bersama-sama.
*/

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\ClassEnrollment;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\Subject;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Services\KehadiranService;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Event\Models\EventClass;
use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Services\EventParticipantService;
use App\Domain\Event\Services\EventRegistrar;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Exam\Services\PengawasService;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Exam\Services\TokenSesi;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionMedia;
use App\Domain\Question\Models\QuestionOption;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Satu ujian lengkap siap dikerjakan: paket berisi empat soal, sesi, ruang,
 * penempatan, pengawas, dan token.
 *
 * ---------------------------------------------------------------------
 * Paketnya EMPAT jenis, bukan satu
 * ---------------------------------------------------------------------
 * Pilihan ganda saja akan lulus setiap pemeriksaan `opsi` sambil diam-diam
 * mengirim `kunci_json` lewat jalur lain. Uraian membawa rubrik (yang tidak
 * boleh sampai ke siswa) dan media membawa berkas (yang rutenya tersendiri) -
 * keduanya jalur kebocoran yang berbeda, dan keduanya harus ada di payload
 * untuk bisa dibuktikan TIDAK bocor.
 *
 * ---------------------------------------------------------------------
 * Presensi sengaja TIDAK diverifikasi di sini
 * ---------------------------------------------------------------------
 * Baris presensinya lahir bersama kursinya - lihat
 * `PencatatKehadiranService` - dengan status `pending`. Membedakan "belum
 * dipresensi" dari "sudah" adalah inti plate 20, jadi yang memverifikasi
 * adalah testnya sendiri lewat `verifikasiHadir()`.
 *
 * @param  array<string, string>  $jendela  overrides: buka, tutup, akhir
 * @return array{event: SchoolEvent, ujian: Exam, sesi: ExamSession, ruang: Collection<int, ExamSessionRoom>, siswa: array<int, User>, peserta: array<int, ExamParticipant>, pengawas: User, token: string}
 */
function siapkanUjianSiswa(
    School $sekolah,
    AcademicYear $tahun,
    Semester $semester,
    User $admin,
    array $jendela = [],
    int $jumlahSiswa = 2,
    int $durasiMenit = 90,
): array {
    $event = app(EventRegistrar::class)->buat([
        'academic_year_id' => $tahun->id,
        'semester_id' => $semester->id,
        'name' => 'UAS Ganjil',
        'event_type' => 'Ujian akhir semester',
    ], $sekolah->id, $admin->id, []);

    $kelas = SchoolClass::factory()->create([
        'school_id' => $sekolah->id,
        'academic_year_id' => $tahun->id,
        'name' => 'X-1-'.$event->id,
    ]);

    $mapel = Subject::factory()->create([
        'school_id' => $sekolah->id,
        'code' => 'MTK-'.$event->id,
        'name' => 'Matematika '.$event->id,
    ]);

    EventClass::create([
        'school_id' => $sekolah->id,
        'event_id' => $event->id,
        'class_id' => $kelas->id,
    ]);

    EventSubject::create([
        'school_id' => $sekolah->id,
        'event_id' => $event->id,
        'subject_id' => $mapel->id,
    ]);

    $ruang = Room::factory()->create([
        'school_id' => $sekolah->id,
        'code' => 'R-01-'.$event->id,
        'name' => 'Ruang R-01',
        'capacity' => max(1, $jumlahSiswa),
    ]);

    $ruangEvent = EventRoom::create([
        'school_id' => $sekolah->id,
        'event_id' => $event->id,
        'room_id' => $ruang->id,
        'status' => 'aktif',
    ]);

    $siswa = [];

    foreach (range(1, $jumlahSiswa) as $i) {
        $user = User::factory()->siswa()->untukSekolah($sekolah)->create([
            'name' => 'Siswa '.$i,
            'username' => 'siswa.u'.$i.'.'.$event->id,
        ]);

        ClassEnrollment::create([
            'school_id' => $sekolah->id,
            'class_id' => $kelas->id,
            'student_id' => $user->id,
            'academic_year_id' => $tahun->id,
            'status' => 'aktif',
        ]);

        $siswa[] = $user;
    }

    app(EventParticipantService::class)->tarikDariKelas($event);

    $paket = paketUntukUjian($admin, $mapel);

    $ujian = app(ExamRegistrar::class)->buat($event, [
        'subject_id' => $mapel->id,
        'question_package_id' => $paket->id,
        'title' => 'Ujian Akhir Semester Matematika',
        'exam_type' => 'Ujian akhir semester',
        'duration_minutes' => $durasiMenit,
        'allow_late_entry' => false,
        'class_id' => [],
    ], $admin->id);

    $sesi = app(SesiService::class)->buat($ujian, [
        'session_number' => 1,
        'access_opens_at' => now()->modify($jendela['buka'] ?? '-10 minutes'),
        'access_closes_at' => now()->modify($jendela['tutup'] ?? '+2 hours'),
        'hard_end_at' => now()->modify($jendela['akhir'] ?? '+3 hours'),
        'duration_minutes' => $durasiMenit,
        'event_room_id' => [$ruangEvent->id],
    ]);

    $penempatan = app(PenempatanService::class);
    $penempatan->tarikPeserta($ujian);
    $penempatan->sebar($ujian, $sesi);

    $ruangSesi = $sesi->rooms()->orderBy('id')->get()->firstOrFail();

    $pengawas = User::factory()->pengawas()->untukSekolah($sekolah)->create([
        'username' => 'pengawas.u.'.$event->id,
    ]);

    EventCommitteeMember::create([
        'school_id' => $sekolah->id,
        'event_id' => $event->id,
        'user_id' => $pengawas->id,
        'name' => $pengawas->name,
        'position' => 'pengawas',
        'scope' => 'event',
        'status' => 'aktif',
    ]);

    app(PengawasService::class)->tugaskan($ruangSesi, $pengawas, $admin->id);

    ['kode' => $kode] = app(TokenSesi::class)->terbitkan($sesi, $admin->id);

    // Draft -> Siap lewat `SesiService::siapkan()`, jalur yang sama dengan
    // Admin. Draft -> Berjalan DITOLAK graf status sesi - lihat catatan yang
    // sama di AttendanceTest.
    app(SesiService::class)->siapkan($sesi);

    $peserta = ExamParticipant::query()
        ->where('exam_id', $ujian->id)
        ->orderBy('student_id')
        ->get()
        ->values();

    return [
        'event' => $event->refresh(),
        'ujian' => $ujian,
        'sesi' => $sesi,
        'ruang' => $sesi->rooms()->orderBy('id')->get(),
        'siswa' => $siswa,
        'peserta' => $peserta,
        'pengawas' => $pengawas,
        'token' => $kode,
    ];
}

/**
 * Paket berisi empat jenis soal; yang keempat membawa berkas media.
 *
 * Rubrik uraian sengaja diberi teks yang mudah dicari ('Ketepatan konsep'),
 * supaya test kebocoran bisa memeriksa bahwa KATA ITU tidak ada di payload -
 * bukan sekadar bahwa kuncinya tidak ada. Kolom yang tidak dikirim tetapi
 * isinya ikut lewat jalur lain adalah kebocoran yang paling sulit terlihat.
 */
function paketUntukUjian(User $admin, Subject $mapel): QuestionPackage
{
    $guru = User::factory()->guru()->untukSekolah(School::find($mapel->school_id))->create();

    $paket = QuestionPackage::factory()->oleh($guru, $mapel)->siap()->create([
        'name' => 'Paket Ujian '.$mapel->id,
    ]);

    $pg = Question::factory()->oleh($guru, $mapel)->berjenis(JenisSoal::PilihanGanda)->create([
        'scoring_config_json' => ['bobot' => 2],
    ]);

    foreach ([['a', '<p>Empat</p>', true], ['b', '<p>Lima</p>', false]] as [$kunci, $teks, $benar]) {
        QuestionOption::create([
            'school_id' => $pg->school_id,
            'question_id' => $pg->id,
            'option_key' => $kunci,
            'content_json' => ['teks' => $teks],
            'is_correct' => $benar,
            'position' => $kunci === 'a' ? 0 : 1,
        ]);
    }

    $isian = Question::factory()->oleh($guru, $mapel)->berjenis(JenisSoal::IsianSingkat)->create([
        'scoring_config_json' => ['bobot' => 3],
    ]);

    $uraian = Question::factory()->oleh($guru, $mapel)->berjenis(JenisSoal::Uraian)->create([
        'scoring_config_json' => ['bobot' => 5],
        'explanation_json' => ['teks' => '<p>Pembahasan yang tidak boleh dibaca siswa.</p>'],
    ]);

    $uraian->rubrik()->create([
        'school_id' => $uraian->school_id,
        'criterion' => 'Ketepatan konsep',
        'description' => 'Jawaban menyebut ketiga syarat.',
        'max_score' => 5,
        'position' => 0,
    ]);

    $media = Question::factory()->oleh($guru, $mapel)->berjenis(JenisSoal::Media)->create([
        'scoring_config_json' => ['bobot' => 1, 'mekanisme' => 'pilihan'],
    ]);

    QuestionMedia::create([
        'school_id' => $media->school_id,
        'question_id' => $media->id,
        'option_id' => null,
        'media_type' => 'gambar',
        'mime_type' => 'image/png',
        'storage_path' => 'soal/uji-'.uniqid().'.png',
        'nama_asli' => 'grafik.png',
        'ukuran' => 2048,
        'metadata_json' => null,
    ]);

    foreach ([$pg, $isian, $uraian, $media] as $i => $soal) {
        QuestionPackageItem::create([
            'school_id' => $paket->school_id,
            'question_package_id' => $paket->id,
            'question_id' => $soal->id,
            'points_override' => null,
            'position' => $i + 1,
        ]);
    }

    return $paket;
}

/**
 * Verifikasi presensi lewat jalur yang SUNGGUH dipakai pengawas.
 *
 * `forceFill` akan lebih pendek, tetapi ia juga akan melewati satu-satunya
 * hal yang diperiksa `KelayakanUjian` - `verified_at` diisi oleh orang yang
 * benar-benar ada di ruang ujian. Test yang memalsukan kolom itu membuktikan
 * gerbangnya terbuka, bukan bahwa gerbangnya terhubung ke presensi.
 */
function verifikasiHadir(TestCase $test, User $pengawas, ExamSessionRoom $ruang, ExamParticipant $peserta): void
{
    masukUji($test, $pengawas);

    $record = AttendanceRecord::query()
        ->where('exam_participant_id', $peserta->id)
        ->firstOrFail();

    app(KehadiranService::class)->catat($record, StatusPresensi::Hadir, KonteksRuang::dari($ruang));
}

/**
 * Masuk sebagai pengguna, SESUDAH melupakan konteks tenant yang tersimpan.
 *
 * ---------------------------------------------------------------------
 * `forget()` wajib, dan tanpa itu seluruh berkas ini berbohong
 * ---------------------------------------------------------------------
 * `SchoolResolver` menyimpan hasilnya selama satu permintaan. Penyiapannya
 * di sini berjalan SEBELUM ada pengguna, sehingga ia menyimpan `null` - dan
 * `null` pada resolver ini bukan "tidak ada sekolah" melainkan "jangan
 * saring": `SchoolScope` menjadi no-op.
 *
 * Akibatnya berbahaya justru karena tidak berbunyi: seluruh test isolasi
 * tetap hijau, sebab `SiswaDasar::milikSaya()` memeriksa `student_id`, bukan
 * scope. Yang tidak lagi dibuktikan adalah bahwa SCOPE-nya bekerja - dan
 * itulah lapisan yang dimaksud kriteria PRD.
 */
function masukUji(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/** Mulai (atau lanjutkan) attempt lewat endpoint yang sama dengan produksi. */
function mulaiAttempt(TestCase $test, User $siswa, ExamParticipant $peserta, ?string $token = null)
{
    return masukUji($test, $siswa)->post("/siswa/ujian/{$peserta->id}/gerbang", array_filter([
        'token' => $token,
    ]));
}

/** Attempt berjalan untuk satu peserta, sesudah gerbangnya dibuka. */
function attemptBerjalan(ExamParticipant $peserta): ExamAttempt
{
    return ExamAttempt::query()
        ->where('exam_participant_id', $peserta->id)
        ->orderByDesc('attempt_number')
        ->firstOrFail();
}
