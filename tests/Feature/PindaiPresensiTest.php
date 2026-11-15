<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Enums\StatusPengakuan;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceAcknowledgement;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceScanCode;
use App\Domain\Attendance\Services\KehadiranService;
use App\Domain\Attendance\Services\KodePindai;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * Pindai presensi - wireframe-presensi-qr.html plate A1-B6.
 *
 * =====================================================================
 * Berkas tersendiri, dan pemisahannya bukan kerapian
 * =====================================================================
 * AttendanceTest menguji presensi yang ditulis PENGAWAS. Berkas ini menguji
 * satu-satunya jalur di aplikasi ini yang menerima tulisan dari SISWA - dan
 * jalur itu menulis ke tabel yang berbeda. Kalau keduanya tercampur, satu
 * test yang hijau tidak lagi membuktikan yang mana.
 *
 * Empat hal yang paling perlu dibuktikan, dan masing-masing punya testnya:
 *
 *   1. URUTAN penolakan - kode, penempatan, sesi, presensi. Urutan itu
 *      menentukan kalimat mana yang dibaca siswa, dan urutan yang salah
 *      mengirim anak berjalan ke ruang yang benar hanya untuk ditolak lagi.
 *   2. Siswa TIDAK menulis presensi - yang ditulisnya pengakuan.
 *   3. Hadir/terlambat turun dari jam PINDAI, bukan jam konfirmasi.
 *   4. Pindai ganda idempoten: satu baris, satu log.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.pindai',
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
 * Menyalakan sesinya lewat jalur yang sama dengan Pengawas.
 *
 * `forceFill` akan lebih pendek dan juga akan melewati satu-satunya hal yang
 * dibaca `KodePindai::periksa()` - status sesi. Test yang memalsukan kolom
 * itu membuktikan laman terbuka, bukan bahwa laman terhubung ke sesi.
 */
function bukaSesi(ExamSessionRoom $ruang): void
{
    $sesi = $ruang->session;

    if ($sesi->status === StatusSesi::Siap) {
        app(SesiService::class)->mulai($sesi);
    }
}

/** Kode pindai mentah untuk satu ruang-sesi. */
function kodeUntuk(ExamSessionRoom $ruang): string
{
    return app(KodePindai::class)->terbitkan($ruang)['kode'];
}

/** Props halaman `/hadir/{kode}` yang benar-benar dikirim ke peramban. */
function propsHadir(object $test, string $kode): array
{
    $respon = $test->get("/hadir/{$kode}");

    $respon->assertOk();

    return $respon->viewData('page')['props'];
}

/* ================================================================ Kode */

it('menerbitkan kode yang hanya bisa dicari lewat hash-nya', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);
    $ruang = $data['ruang'][0];

    $kode = app(KodePindai::class)->terbitkan($ruang)['kode'];

    expect(strlen($kode))->toBe(KodePindai::PANJANG_KODE);

    // Kode MENTAH tidak tersimpan di mana pun - itu tujuan hash-nya.
    expect(AttendanceScanCode::query()->where('code_hash', $kode)->exists())->toBeFalse();

    expect(
        AttendanceScanCode::query()
            ->where('code_hash', app(KodePindai::class)->hash($kode))
            ->exists(),
    )->toBeTrue();
});

it('menerbitkan baris baru setiap kali, dan yang lama mati sendiri', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);
    $ruang = $data['ruang'][0];

    $pertama = app(KodePindai::class)->terbitkan($ruang);
    $kedua = app(KodePindai::class)->terbitkan($ruang);

    expect($kedua['baris']->id)->not->toBe($pertama['baris']->id);
    expect(AttendanceScanCode::query()->count())->toBe(2);
});

/* ============================================================= Penolakan */

it('menolak kode yang tidak dikenal sebelum memeriksa apa pun', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);
    $siswa = $data['siswa'][0];

    bukaSesi($data['ruang'][0]);

    masukSebagai($this, $siswa);

    $respon = $this->get('/hadir/abcdefghjkmnpqrs');

    $respon->assertOk();

    $props = $respon->viewData('page')['props'];

    expect($props['keadaan'])->toBe('tolak');
    expect($props['penolakan']['judul'])->toBe('Kode tidak dikenal');
});

it('menolak kode yang sudah lewat batas, dan menyebut jamnya', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);
    $ruang = $data['ruang'][0];

    bukaSesi($ruang);

    $kode = kodeUntuk($ruang);

    $this->travel(KodePindai::UMUR_DETIK + 5)->seconds();

    masukSebagai($this, $data['siswa'][0]);

    $props = propsHadir($this, $kode);

    expect($props['keadaan'])->toBe('tolak');
    expect($props['penolakan']['judul'])->toBe('Kode sudah kedaluwarsa');
    // Kalimatnya menyebut ANGKA yang juga dilihat siswa di layar pengawas.
    expect($props['penolakan']['pesan'])->toContain(':');
});

it('menolak siswa yang ditempatkan di ruang lain, dan menyebut ruang yang benar', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01', 'R-02'], 4);

    foreach ($data['ruang'] as $r) {
        bukaSesi($r);
    }

    $ruangSatu = $data['ruang'][0];

    // Siswa yang benar-benar duduk di ruang KEDUA memindai kode ruang
    // pertama - dan yang harus ia baca adalah nama ruangnya sendiri.
    $lain = ExamParticipant::query()
        ->where('exam_session_room_id', $data['ruang'][1]->id)
        ->firstOrFail();

    $siswa = User::query()->findOrFail($lain->student_id);
    $kodeRuangSatu = kodeUntuk($ruangSatu);

    masukSebagai($this, $siswa);

    $props = propsHadir($this, $kodeRuangSatu);

    expect($props['keadaan'])->toBe('tolak');
    expect($props['penolakan']['judul'])->toBe('Bukan ruang Anda');
    // Kode ruang yang BENAR ikut disebut - "hubungi pengawas" bukan
    // instruksi yang bisa dijalankan anak yang baru tahu ia salah pintu.
    expect($props['penolakan']['pesan'])->toContain($data['ruang'][1]->eventRoom->room->code);
});

it('menolak saat sesinya belum dibuka', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);
    $ruang = $data['ruang'][0];

    // Masih `Siap`, belum `Berjalan`.
    $kode = kodeUntuk($ruang);

    masukSebagai($this, $data['siswa'][0]);

    $props = propsHadir($this, $kode);

    expect($props['keadaan'])->toBe('tolak');
    expect($props['penolakan']['judul'])->toBe('Sesi belum dibuka');
});

it('menjawab pindai kedua dengan tenang, bukan galat merah', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);
    $ruang = $data['ruang'][0];
    $siswa = $data['siswa'][0];

    bukaSesi($ruang);

    $kode = kodeUntuk($ruang);

    masukSebagai($this, $siswa);
    $this->post("/hadir/{$kode}")->assertRedirect();

    // Pengawas sudah mencatatnya lewat daftar kursi - jalur yang tetap
    // terbuka meski siswa tidak pernah memindai.
    $peserta = ExamParticipant::query()->where('student_id', $siswa->id)->firstOrFail();
    $record = AttendanceRecord::query()->where('exam_participant_id', $peserta->id)->firstOrFail();

    // `KonteksRuang::dari()` membaca Auth, bukan ruangnya: konteks ruang
    // adalah BUKTI siapa yang mencatat, dan siswa tidak bisa menjadi pelaku
    // presensinya sendiri.
    masukSebagai($this, $this->admin);

    app(KehadiranService::class)
        ->catat($record, StatusPresensi::Hadir, KonteksRuang::dari($ruang));

    masukSebagai($this, $siswa);

    $props = propsHadir($this, $kode);

    expect($props['keadaan'])->toBe('tolak');
    // Plate B6, penolakan keempat: idempoten, dan ditandai `tenang` supaya
    // lamannya tidak memerahkannya.
    expect($props['penolakan']['tenang'])->toBeTrue();
    expect($props['penolakan']['judul'])->toContain('Sudah tercatat');
});

/* ============================================================== Pengakuan */

it('pengakuan siswa tidak menulis presensi', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);
    $ruang = $data['ruang'][0];
    $siswa = $data['siswa'][0];

    bukaSesi($ruang);

    $kode = kodeUntuk($ruang);

    masukSebagai($this, $siswa);

    $props = propsHadir($this, $kode);
    expect($props['keadaan'])->toBe('akui');

    $this->post("/hadir/{$kode}")->assertRedirect();

    expect(AttendanceAcknowledgement::query()->count())->toBe(1);

    $peserta = ExamParticipant::query()->where('student_id', $siswa->id)->firstOrFail();
    $record = AttendanceRecord::query()->where('exam_participant_id', $peserta->id)->firstOrFail();

    // Presensi TETAP `pending` sampai pengawas menekan tombolnya.
    expect($record->attendance_status->value)->toBe('pending');
    expect($record->verified_at)->toBeNull();
});

it('pindai ganda tidak menulis baris kedua', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);
    $ruang = $data['ruang'][0];
    $siswa = $data['siswa'][0];

    bukaSesi($ruang);

    $kode = kodeUntuk($ruang);

    masukSebagai($this, $siswa);

    $this->post("/hadir/{$kode}")->assertRedirect();
    $this->post("/hadir/{$kode}")->assertRedirect();

    expect(AttendanceAcknowledgement::query()->count())->toBe(1);
});

it('halaman tunggu tetap terbuka sesudah kodenya mati', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);
    $ruang = $data['ruang'][0];
    $siswa = $data['siswa'][0];

    bukaSesi($ruang);

    $kode = kodeUntuk($ruang);

    masukSebagai($this, $siswa);
    $this->post("/hadir/{$kode}")->assertRedirect();

    // Kode mati 60 detik kemudian, dan laman itu dimuat ulang justru untuk
    // melihat apakah pengawasnya sudah mengonfirmasi.
    $this->travel(KodePindai::UMUR_DETIK + 10)->seconds();

    masukSebagai($this, $siswa);

    $props = propsHadir($this, $kode);

    expect($props['keadaan'])->toBe('tunggu');
    expect($props['tunggu']['dikonfirmasi'])->toBeFalse();
});

/* ============================================================ Konfirmasi */

it('konfirmasi mencatat hadir dari jam pindai, bukan jam konfirmasi', function () {
    // Jendela dibuka DUA MENIT lalu: pindai sekarang masih di dalam jendela
    // "tepat" (`MENIT_TEPAT` menit pertama), jadi statusnya `hadir` - dan
    // itulah yang membuat test ini bisa membedakan jam pindai dari jam
    // konfirmasi. Dengan jendela bawaan minus satu jam, SEMUA pindai
    // terlambat dan test ini akan hijau meski konfirmasi memakai jamnya
    // sendiri.
    $data = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['R-01'], 2, null, buka: '-2 minutes',
    );
    $ruang = $data['ruang'][0];
    $siswa = $data['siswa'][0];

    bukaSesi($ruang);

    $kode = kodeUntuk($ruang);

    masukSebagai($this, $siswa);
    $this->post("/hadir/{$kode}")->assertRedirect();

    $pengakuan = AttendanceAcknowledgement::query()->firstOrFail();

    $peserta = ExamParticipant::query()->where('student_id', $siswa->id)->firstOrFail();
    $record = AttendanceRecord::query()->where('exam_participant_id', $peserta->id)->firstOrFail();

    // Pengawas baru menekan tombolnya DUA JAM kemudian. Jam konfirmasinya
    // jauh lewat batas masuk, tetapi siswa itu memindai di dalamnya.
    $this->travel(2)->hours();

    // Konteks ruang dibangun sebagai ADMIN: `KonteksRuang::dari()` membaca
    // Auth, dan siapa pun yang memegang akun siswa tidak bisa menjadi pelaku
    // presensinya sendiri.
    masukSebagai($this, $this->admin);

    app(KodePindai::class)->konfirmasi($ruang, [$pengakuan->id], KonteksRuang::dari($ruang));

    expect($record->refresh()->attendance_status->value)->toBe('hadir');
});

it('konfirmasi menurunkan terlambat untuk pindai di luar jendela tepat', function () {
    $data = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['R-01'], 2, null, buka: '-2 minutes',
    );
    $ruang = $data['ruang'][0];
    $siswa = $data['siswa'][0];

    bukaSesi($ruang);

    $kode = kodeUntuk($ruang);

    masukSebagai($this, $siswa);
    $this->post("/hadir/{$kode}")->assertRedirect();

    $pengakuan = AttendanceAcknowledgement::query()->firstOrFail();

    // Jendela "tepat" lewat 15 menit kemudian, dan JAM PINDAI-nya yang
    // digeser - pindai ulang tidak mungkin: kodenya mati, dan pengakuannya
    // sudah ada sehingga `akui()` tidak menulis apa pun lagi.
    $this->travel(KodePindai::MENIT_TEPAT + 5)->minutes();

    $pengakuan->forceFill(['scanned_at' => now()])->save();

    $peserta = ExamParticipant::query()->where('student_id', $siswa->id)->firstOrFail();
    $record = AttendanceRecord::query()->where('exam_participant_id', $peserta->id)->firstOrFail();

    masukSebagai($this, $this->admin);

    app(KodePindai::class)->konfirmasi($ruang, [$pengakuan->id], KonteksRuang::dari($ruang));

    expect($record->refresh()->attendance_status->value)->toBe('terlambat');
});

it('konfirmasi menulis satu baris log per siswa', function () {
    $data = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['R-01'], 3, null, buka: '-2 minutes',
    );
    $ruang = $data['ruang'][0];

    bukaSesi($ruang);

    $kode = kodeUntuk($ruang);

    foreach ($data['siswa'] as $siswa) {
        masukSebagai($this, $siswa);
        $this->post("/hadir/{$kode}")->assertRedirect();
    }

    $ids = AttendanceAcknowledgement::query()->pluck('id')->all();

    masukSebagai($this, $this->admin);

    app(KodePindai::class)->konfirmasi($ruang, $ids, KonteksRuang::dari($ruang));

    expect(AttendanceAcknowledgement::query()->where('status', StatusPengakuan::Menunggu)->count())->toBe(0);
    expect(AttendanceRecord::query()->where('attendance_status', 'hadir')->count())->toBe(3);
    // Satu tombol, tiga baris log - janji `verifikasiMassal()` ditepati di
    // jalur ini juga.
    expect(AttendanceLog::query()->count())->toBe(3);
});

it('konfirmasi tidak menyentuh pengakuan ruang lain', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01', 'R-02'], 4);

    foreach ($data['ruang'] as $r) {
        bukaSesi($r);
    }

    $ruangSatu = $data['ruang'][0];
    $ruangDua = $data['ruang'][1];

    $pesertaDua = ExamParticipant::query()
        ->where('exam_session_room_id', $ruangDua->id)
        ->firstOrFail();

    $siswaDua = User::query()->findOrFail($pesertaDua->student_id);

    $kodeDua = kodeUntuk($ruangDua);

    masukSebagai($this, $siswaDua);
    $this->post("/hadir/{$kodeDua}")->assertRedirect();

    $pengakuan = AttendanceAcknowledgement::query()->firstOrFail();

    // Ruang SATU mencoba mengonfirmasi pengakuan milik ruang DUA.
    masukSebagai($this, $this->admin);

    $hasil = app(KodePindai::class)->konfirmasi($ruangSatu, [$pengakuan->id], KonteksRuang::dari($ruangSatu));

    expect($hasil['berubah'])->toBe(0);
    expect($pengakuan->refresh()->status)->toBe(StatusPengakuan::Menunggu);
});

/* ====================================== Jajak pengakuan (laman pengawas) */

/*
 * Kotak "N siswa memindai" di laman presensi pengawas disegarkan berkala
 * lewat endpoint JSON sendiri, bukan `router.reload` Inertia. Dua hal yang
 * dibuktikan di sini, dan keduanya adalah kegagalan yang tidak terlihat dari
 * layar:
 *
 *   1. Reload Inertia menjalankan `index()` utuh, dan `payloadPindai()` di
 *      dalamnya MENERBITKAN kode pindai baru. Menyegarkan tiap 15 detik akan
 *      mengganti QR yang sah 60 detik tepat saat siswa mengangkat kameranya.
 *      Jadi endpoint ini tidak boleh menulis baris kode apa pun.
 *   2. Isinya hanya pengakuan `menunggu` milik ruang itu - bukan milik ruang
 *      lain, dan bukan yang sudah dikonfirmasi.
 */

it('jajak pengakuan tidak menerbitkan kode pindai baru', function () {
    $pengawas = User::factory()->pengawas()->untukSekolah($this->sekolah)->create([
        'username' => 'pengawas.jajak.kode',
    ]);

    $data = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['R-01'], 2, [$pengawas],
    );

    $ruang = $data['ruang'][0];

    bukaSesi($ruang);

    $sebelum = AttendanceScanCode::query()->count();

    masukSebagai($this, $pengawas);

    // Lima kali, seperti lima menit laman pengawas dibiarkan terbuka.
    foreach (range(1, 5) as $_) {
        $this->getJson("/pengawas/ruang/{$ruang->id}/presensi/pengakuan")->assertOk();
    }

    expect(AttendanceScanCode::query()->count())->toBe($sebelum);
});

it('jajak pengakuan mengembalikan yang menunggu di ruang itu saja', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01', 'R-02'], 4);

    foreach ($data['ruang'] as $r) {
        bukaSesi($r);
    }

    $ruangSatu = $data['ruang'][0];
    $ruangDua = $data['ruang'][1];

    // Dua siswa memindai di ruang SATU, satu di ruang DUA.
    foreach ($data['ruang'] as $r) {
        $peserta = ExamParticipant::query()
            ->where('exam_session_room_id', $r->id)
            ->pluck('student_id')
            ->take($r->id === $ruangSatu->id ? 2 : 1);

        $kode = kodeUntuk($r);

        foreach ($peserta as $id) {
            masukSebagai($this, User::query()->findOrFail($id));
            $this->post("/hadir/{$kode}")->assertRedirect();
        }
    }

    masukSebagai($this, $this->admin);

    $isi = $this->getJson("/pengawas/ruang/{$ruangSatu->id}/presensi/pengakuan")
        ->assertOk()
        ->json('pengakuan');

    expect($isi)->toHaveCount(2);

    // Yang dikirim hanya yang dibutuhkan kotaknya - dan `akan` dihitung dari
    // jam PINDAI, bukan jam konfirmasi.
    expect(array_keys($isi[0]))->toContain('nama', 'kursi', 'jam', 'akan_label', 'tepat');

    // Baris yang sudah dikonfirmasi tidak lagi muncul di lembar ini.
    $satu = AttendanceAcknowledgement::query()->firstOrFail();
    app(KodePindai::class)->konfirmasi($ruangSatu, [$satu->id], KonteksRuang::dari($ruangSatu));

    $sesudah = $this->getJson("/pengawas/ruang/{$ruangSatu->id}/presensi/pengakuan")
        ->json('pengakuan');

    expect($sesudah)->toHaveCount(1);
});

it('pengawas ruang lain tidak bisa menjajak pengakuan ruang ini', function () {
    $pengawas = collect(range(1, 2))->map(
        fn (int $i) => User::factory()->pengawas()->untukSekolah($this->sekolah)->create([
            'username' => 'pengawas.jajak'.$i,
        ]),
    );

    $data = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['R-01', 'R-02'], 4, $pengawas->all(),
    );

    [$r01, $r02] = [$data['ruang'][0], $data['ruang'][1]];

    masukSebagai($this, $pengawas[1]);

    $this->getJson("/pengawas/ruang/{$r01->id}/presensi/pengakuan")->assertNotFound();
    $this->getJson("/pengawas/ruang/{$r02->id}/presensi/pengakuan")->assertOk();
});
