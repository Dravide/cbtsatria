<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Services\PengawasService;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Grading\Models\ExamResult;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * Penjadwal Fase 9 - penutupan attempt kedaluwarsa dan pemangkasan ekspor.
 *
 * =====================================================================
 * Yang dibuktikan di sini bukan "perintahnya jalan", melainkan AKIBATNYA
 * =====================================================================
 * `UPDATE exam_attempts SET status = 'kedaluwarsa'` akan membuat seluruh
 * test yang hanya memeriksa status HIJAU - dan tetap meninggalkan siswa tanpa
 * nilai. Karena itu setiap test penutupan memeriksa tiga hal sekaligus:
 *
 *   1. `status` menjadi `kedaluwarsa`;
 *   2. `submitted_at` SAMA DENGAN `deadline_at` - waktu habisnya, bukan waktu
 *      perintah ini kebetulan berjalan;
 *   3. baris `exam_results` LAHIR.
 *
 * Poin ketiga yang menentukan. Ia bukti bahwa `tutupBilaKedaluwarsa()` benar-
 * benar dipanggil, dan hanya method itulah yang menilai.
 *
 * =====================================================================
 * Mengapa waktunya digeser, bukan `forceFill('deadline_at')`
 * =====================================================================
 * Memalsukan kolom deadline akan menguji perintah terhadap keadaan yang tidak
 * pernah bisa terjadi di produksi (deadline di masa lalu pada attempt yang
 * baru dimulai). `travel()` menggeser `now()` untuk SELURUH aplikasi, jadi
 * yang diuji adalah jalur yang sama persis dengan yang berjalan pukul tiga
 * pagi di server: deadline sudah lewat, ujian masih `berjalan`.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.penjadwal',
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

/**
 * Mulai satu attempt sungguhan lewat gerbang siswa.
 *
 * `TestCase` diterima sebagai argumen, bukan diambil lewat `test()`:
 * `test()` tanpa deskripsi mengembalikan `HigherOrderTapProxy`, bukan
 * `TestCase` - dan `verifikasiHadir()` menuntut yang kedua. Properti test
 * (`$this->sekolah`) memang bisa dibaca lewat proxy itu, sehingga kesalahan
 * ini hanya muncul pada fungsi yang meneruskan `test()` ke helper lain.
 *
 * Sekolahnya bisa disebut eksplisit supaya test lintas tenant tidak perlu
 * menukar properti test bolak-balik - dan supaya jelas bahwa penyiapannya
 * memang untuk sekolah lain, bukan sekolah bawaan yang kebetulan tertinggal.
 */
function attemptPenjadwal(
    TestCase $test,
    int $jumlahSiswa = 1,
    ?School $sekolah = null,
    ?AcademicYear $tahun = null,
    ?Semester $semester = null,
    ?User $admin = null,
): array {
    // Pengguna sebelumnya dilepas dan konteks tenant dilupakan SEBELUM
    // menyiapkan apa pun.
    //
    // `forget()` saja tidak cukup: `actingAs()` menempel pada guard selama
    // test berjalan, sehingga resolusi ulang akan membaca ulang sekolah
    // pengguna lama. Untuk sekolah kedua itu berarti `academic_years`
    // disaring dengan `school_id` sekolah PERTAMA - dan penyiapannya gagal
    // pada tahun yang baru saja dibuatnya sendiri, dengan pesan yang
    // menunjuk tahun itu seolah-olah tidak pernah ada.
    Auth::logout();

    app(SchoolResolver::class)->forget();

    $data = siapkanUjianSiswa(
        $sekolah ?? $test->sekolah,
        $tahun ?? $test->tahun,
        $semester ?? $test->semester,
        $admin ?? $test->admin,
        jumlahSiswa: $jumlahSiswa,
    );

    verifikasiHadir($test, $data['pengawas'], $data['ruang'][0], $data['peserta'][0]);

    mulaiAttempt($test, $data['siswa'][0], $data['peserta'][0], $data['token']);

    return $data;
}

/* =====================================================================
 | ujian:tutup-kedaluwarsa
 |===================================================================== */

it('menutup attempt yang waktunya habis, mengisi submitted_at, dan menilainya', function () {
    $data = attemptPenjadwal($this);

    $attempt = attemptBerjalan($data['peserta'][0]);

    expect($attempt->status)->toBe(ExamAttempt::STATUS_BERJALAN)
        ->and(ExamResult::tanpaTenant()->where('exam_attempt_id', $attempt->id)->count())->toBe(0);

    // Durasi ujian 90 menit; lewati dengan selisih yang jelas.
    $this->travel(91)->minutes();

    $this->artisan('ujian:tutup-kedaluwarsa')->assertExitCode(0);

    $sesudah = ExamAttempt::tanpaTenant()->findOrFail($attempt->id);

    expect($sesudah->status)->toBe(ExamAttempt::STATUS_KEDALUWARSA)
        // Bukan `now()`. Siswa yang kehabisan waktu pada 09:30 harus tercatat
        // selesai 09:30, sekalipun perintahnya baru menemukannya pukul 09:31 -
        // dan ia akan terlambat berjam-jam kalau penjadwal baru menyala lagi.
        ->and($sesudah->submitted_at->equalTo($sesudah->deadline_at))->toBeTrue();

    // Inilah yang membedakannya dari UPDATE borongan.
    expect(ExamResult::tanpaTenant()->where('exam_attempt_id', $attempt->id)->count())->toBe(1);
});

it('tidak menyentuh attempt yang waktunya belum habis', function () {
    $data = attemptPenjadwal($this);
    $attempt = attemptBerjalan($data['peserta'][0]);

    $this->artisan('ujian:tutup-kedaluwarsa')->assertExitCode(0);

    expect($attempt->fresh()->status)->toBe(ExamAttempt::STATUS_BERJALAN)
        ->and($attempt->fresh()->submitted_at)->toBeNull()
        ->and(ExamResult::tanpaTenant()->where('exam_attempt_id', $attempt->id)->count())->toBe(0);
});

it('menutup attempt milik sekolah LAIN dalam eksekusi yang sama', function () {
    // Dua sekolah, dua ujian, dua attempt - keduanya harus tertutup. Penjadwal
    // berjalan tanpa pengguna, jadi scope tenant sebenarnya sudah diam; test
    // ini yang membuktikan bahwa `tanpaTenant()` benar-benar dipakai dan bukan
    // kebetulan konteks kosong. Tanpa itu, yang tertutup hanya sekolah yang
    // kebetulan ada di konteks - dan sekolah yang siswanya tidak pernah
    // mendapat nilai tidak akan mengadukannya.
    $dataA = attemptPenjadwal($this);
    $attemptA = attemptBerjalan($dataA['peserta'][0]);

    $sekolahB = School::factory()->create(['name' => 'SMA Negeri 2']);

    $adminB = User::factory()->admin()->untukSekolah($sekolahB)->create([
        'username' => 'admin.penjadwal.b',
    ]);

    $tahunB = AcademicYear::factory()->untukSekolah($sekolahB)->aktif()->create([
        'name' => '2026/2027',
    ]);

    $semesterB = Semester::factory()->create([
        'school_id' => $sekolahB->id,
        'academic_year_id' => $tahunB->id,
        'name' => 'Ganjil',
    ]);

    // Penyangga: sekolah, tahun, semester, dan admin sekolah B disebut
    // eksplisit, sehingga penyiapannya tidak menukar properti test bolak-balik
    // dan tidak bergantung pada urutan penulisan.
    $dataB = attemptPenjadwal(
        $this,
        sekolah: $sekolahB,
        tahun: $tahunB,
        semester: $semesterB,
        admin: $adminB,
    );
    $attemptB = attemptBerjalan($dataB['peserta'][0]);

    $this->travel(91)->minutes();

    $this->artisan('ujian:tutup-kedaluwarsa')->assertExitCode(0);

    expect(ExamAttempt::tanpaTenant()->findOrFail($attemptA->id)->status)->toBe(ExamAttempt::STATUS_KEDALUWARSA)
        ->and(ExamAttempt::tanpaTenant()->findOrFail($attemptB->id)->status)->toBe(ExamAttempt::STATUS_KEDALUWARSA);
});

it('tidak menggandakan hasil bila dijalankan dua kali', function () {
    $data = attemptPenjadwal($this);
    $attempt = attemptBerjalan($data['peserta'][0]);

    $this->travel(91)->minutes();

    $this->artisan('ujian:tutup-kedaluwarsa')->assertExitCode(0);
    // Putaran kedua adalah keadaan normal: perintah ini berjalan tiap menit,
    // dan baris yang sudah ditutup keluar dari filternya.
    $this->artisan('ujian:tutup-kedaluwarsa')->assertExitCode(0);

    expect(ExamResult::tanpaTenant()->where('exam_attempt_id', $attempt->id)->count())->toBe(1);
});

it('tidak mengubah apa pun dalam mode kering', function () {
    $data = attemptPenjadwal($this);
    $attempt = attemptBerjalan($data['peserta'][0]);

    $this->travel(91)->minutes();

    $this->artisan('ujian:tutup-kedaluwarsa', ['--kering' => true])
        ->expectsOutputToContain('mode kering')
        ->assertExitCode(0);

    expect($attempt->fresh()->status)->toBe(ExamAttempt::STATUS_BERJALAN)
        ->and($attempt->fresh()->submitted_at)->toBeNull()
        ->and(ExamResult::tanpaTenant()->where('exam_attempt_id', $attempt->id)->count())->toBe(0);
});

/* =====================================================================
 | ujian:tutup-sesi
 |===================================================================== */

/**
 * Sesi yang sudah BERJALAN, pada event yang sudah berjalan.
 *
 * =====================================================================
 * Prasyaratnya ditegakkan `forceFill`, dan itu bukan jalur pintas
 * =====================================================================
 * Yang diuji di sini adalah penutupan sesi DAN pemindahan eventnya dari
 * `berjalan` ke `selesai`. Untuk sampai ke `berjalan` lewat graf, sebuah
 * event harus melewati gerbang kesiapan di `EventStatusService::pindah()` -
 * dan gerbang itu adalah pokok bahasan `EventTest`, bukan berkas ini.
 * Menempuhnya di sini berarti setiap test penutup sesi ikut gagal begitu
 * satu syarat kesiapan berubah, dengan pesan yang menunjuk berkas yang
 * salah.
 *
 * Yang TIDAK dipalsukan: transisi `berjalan -> selesai` yang diperiksa
 * di bawah, dan itu justru transisi yang dilaporkan hilang.
 *
 * `mulai()` tetap dipanggil lewat jalur sungguhan, karena hanya itulah yang
 * memindahkan STATUS SESI ke `berjalan` - dan sesi berstatus `siap` tidak
 * pernah masuk filter perintah ini.
 */
function sesiBerjalanPenjadwal(TestCase $test): array
{
    Auth::logout();

    app(SchoolResolver::class)->forget();

    $data = siapkanUjianSiswa(
        $test->sekolah,
        $test->tahun,
        $test->semester,
        $test->admin,
    );

    app(SesiService::class)->mulai($data['sesi']);

    $data['event']->forceFill(['status' => EventStatus::Ongoing])->save();

    return $data;
}

it('menutup sesi yang jendelanya berakhir, dan memindahkan eventnya ke selesai', function () {
    $data = sesiBerjalanPenjadwal($this);

    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Berjalan)
        ->and($data['event']->refresh()->status)->toBe(EventStatus::Ongoing);

    // Jendela sesi bawaan berakhir `now() + 3 jam`.
    $this->travel(4)->hours();

    $this->artisan('ujian:tutup-sesi')->assertExitCode(0);

    // Inilah yang membedakannya dari UPDATE borongan: sesinya ditutup DAN
    // eventnya ikut pindah. Tanpa yang kedua, tab "Berjalan" di
    // `/admin/events` tetap memuat event yang ujiannya sudah selesai
    // berhari-hari lalu - dan gejala itulah yang melahirkan perintah ini.
    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Selesai)
        ->and($data['event']->refresh()->status)->toBe(EventStatus::Completed);
});

it('tidak menyentuh sesi yang jendelanya belum berakhir', function () {
    $data = sesiBerjalanPenjadwal($this);

    $this->artisan('ujian:tutup-sesi')
        ->expectsOutputToContain('Tidak ada sesi')
        ->assertExitCode(0);

    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Berjalan)
        ->and($data['event']->refresh()->status)->toBe(EventStatus::Ongoing);
});

it('tidak menutup sesi yang dibatalkan', function () {
    $data = sesiBerjalanPenjadwal($this);

    app(SesiService::class)->batalkan($data['sesi'], 'Listrik padam di gedung 1.');

    $this->travel(4)->hours();

    $this->artisan('ujian:tutup-sesi')->assertExitCode(0);

    // Dibatalkan BUKAN "belum tuntas": sesi itu tidak jadi dijalankan, dan
    // menutupnya akan membawa eventnya ke Selesai - padahal yang dibutuhkan
    // sekolah adalah menyusun sesi penggantinya di event yang sama.
    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Batal);
});

it('tidak menggandakan apa pun bila dijalankan dua kali', function () {
    $data = sesiBerjalanPenjadwal($this);

    $this->travel(4)->hours();

    $this->artisan('ujian:tutup-sesi')->assertExitCode(0);
    // Putaran kedua adalah keadaan normal: perintah ini berjalan tiap menit,
    // dan sesi yang sudah ditutup keluar dari filternya.
    $this->artisan('ujian:tutup-sesi')
        ->expectsOutputToContain('Tidak ada sesi')
        ->assertExitCode(0);

    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Selesai)
        ->and($data['event']->refresh()->status)->toBe(EventStatus::Completed);
});

it('tidak mengubah apa pun dalam mode kering, pada sesi', function () {
    $data = sesiBerjalanPenjadwal($this);

    $this->travel(4)->hours();

    $this->artisan('ujian:tutup-sesi', ['--kering' => true])
        ->expectsOutputToContain('mode kering')
        ->assertExitCode(0);

    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Berjalan)
        ->and($data['event']->refresh()->status)->toBe(EventStatus::Ongoing);
});

it('menutup sesi milik sekolah LAIN dalam eksekusi yang sama', function () {
    $dataA = sesiBerjalanPenjadwal($this);

    $sekolahB = School::factory()->create(['name' => 'SMA Negeri 3']);

    $adminB = User::factory()->admin()->untukSekolah($sekolahB)->create([
        'username' => 'admin.penjadwal.c',
    ]);

    $tahunB = AcademicYear::factory()->untukSekolah($sekolahB)->aktif()->create([
        'name' => '2026/2027',
    ]);

    $semesterB = Semester::factory()->create([
        'school_id' => $sekolahB->id,
        'academic_year_id' => $tahunB->id,
        'name' => 'Ganjil',
    ]);

    Auth::logout();
    app(SchoolResolver::class)->forget();

    $dataB = siapkanUjianSiswa($sekolahB, $tahunB, $semesterB, $adminB);

    app(SesiService::class)->mulai($dataB['sesi']);
    $dataB['event']->forceFill(['status' => EventStatus::Ongoing])->save();

    $this->travel(4)->hours();

    $this->artisan('ujian:tutup-sesi')->assertExitCode(0);

    expect($dataA['sesi']->refresh()->status)->toBe(StatusSesi::Selesai)
        ->and($dataB['sesi']->refresh()->status)->toBe(StatusSesi::Selesai)
        ->and($dataA['event']->refresh()->status)->toBe(EventStatus::Completed)
        ->and($dataB['event']->refresh()->status)->toBe(EventStatus::Completed);
});

it('menutup sesi satu per satu, dan eventnya baru selesai saat sesi terakhir', function () {
    $data = sesiBerjalanPenjadwal($this);

    // Sesi KEDUA pada ujian yang sama, jendelanya lebih panjang. "Sesi
    // terakhir" bukan `session_number` terbesar - lihat `semuaTuntas()` -
    // melainkan sesi yang terakhir tuntas, dan urutan penutupan itu yang
    // diuji di sini.
    $sesiKedua = app(SesiService::class)->buat($data['ujian'], [
        'session_number' => 2,
        'access_opens_at' => now()->addHour(),
        'access_closes_at' => now()->addHours(2),
        'hard_end_at' => now()->addHours(9),
        'duration_minutes' => 60,
        'event_room_id' => [$data['event']->rooms()->first()->id],
    ]);

    // Pengawas LAIN, bukan pengawas sesi pertama. `PengawasService::tugaskan()`
    // menolak orang yang sudah memegang ruang lain pada jam yang tumpang
    // tindih - dan sesi kedua dimulai satu jam setelah sesi pertama, jadi
    // pengawas yang sama memang BENTROK. Penolakan itu benar; yang salah
    // adalah test yang memaksanya.
    $pengawasKedua = User::factory()->pengawas()->untukSekolah($this->sekolah)->create([
        'username' => 'pengawas.penjadwal.dua',
    ]);

    app(PengawasService::class)->tugaskan(
        $sesiKedua->rooms()->firstOrFail(),
        $pengawasKedua,
        $this->admin->id,
    );

    app(SesiService::class)->siapkan($sesiKedua);
    app(SesiService::class)->mulai($sesiKedua);

    // Sesi pertama lewat, sesi kedua belum.
    $this->travel(4)->hours();

    $this->artisan('ujian:tutup-sesi')->assertExitCode(0);

    expect($data['sesi']->refresh()->status)->toBe(StatusSesi::Selesai)
        ->and($sesiKedua->refresh()->status)->toBe(StatusSesi::Berjalan)
        // Event BELUM selesai - masih ada sesi yang berjalan.
        ->and($data['event']->refresh()->status)->toBe(EventStatus::Ongoing);

    $this->travel(6)->hours();

    $this->artisan('ujian:tutup-sesi')->assertExitCode(0);

    expect($sesiKedua->refresh()->status)->toBe(StatusSesi::Selesai)
        ->and($data['event']->refresh()->status)->toBe(EventStatus::Completed);
});

/* =====================================================================
 | Jadwal
 |===================================================================== */

it('menjadwalkan kelima tugas, bukan sekadar menuliskannya', function () {
    // Perintah yang tertulis tetapi lupa didaftarkan ke `Schedule` tidak
    // pernah berjalan dan tidak pernah berbunyi - satu-satunya cara
    // menemukannya adalah membuka `routes/console.php` dan membacanya.
    $perintah = collect(Schedule::events())
        ->map(fn ($e) => (string) $e->command)
        ->implode("\n");

    expect($perintah)->toContain('ujian:tutup-kedaluwarsa')
        // Perintah KETUJUH, ditambahkan 2026-11-14. Ia menutup SESI, satu
        // tingkat di atas penutup attempt - dan tanpa baris ini, sesi yang
        // jendelanya berakhir tidak pernah pindah dari "berjalan".
        ->and($perintah)->toContain('ujian:tutup-sesi')
        ->and($perintah)->toContain('laporan:pangkas-ekspor')
        // Perintah KELIMA, dan yang paling mudah terlupa: ekspor pengguna
        // meninggalkan berkas sebesar ekspor laporan, di disk privat yang
        // sama dengan berita acara. Perintah yang ditulis tetapi tidak
        // dijadwalkan tidak pernah berbunyi - disknya hanya penuh setahun
        // kemudian, dan saat itu berita acara yang gagal disimpan.
        ->and($perintah)->toContain('pengguna:pangkas-ekspor')
        ->and($perintah)->toContain('session:garbage-collect')
        ->and($perintah)->toContain('queue:prune-failed');

    // `cache:prune-stale-tags` sengaja TIDAK dijadwalkan: bantuannya sendiri
    // berbunyi "Redis only" dan Redis belum diaktifkan. Menjadwalkannya
    // sekarang berarti satu perintah yang gagal diam-diam setiap malam.
    expect($perintah)->not->toContain('cache:prune-stale-tags');
});
