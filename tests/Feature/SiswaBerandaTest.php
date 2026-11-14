<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\Subject;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\KehadiranService;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Attendance\Services\MesinUjian;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Tests\TestCase;

/**
 * Tiga keadaan di beranda siswa - ditambahkan 2026-11-14.
 *
 *   1. Kemajuan  - "2 dari 5 ujian selesai"
 *   2. Hitung mundur - detik sampai sesi dibuka, dihitung SERVER
 *   3. Presensi  - keadaan yang sampai sekarang hanya bisa dibaca gerbang
 *
 * Semuanya dikirim dari baris yang SAMA dengan daftar jadwal, sehingga
 * angka di atas dan isi di bawahnya tidak bisa berselisih.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->admin = User::factory()->admin()
        ->untukSekolah($this->sekolah)->create(['username' => 'admin.beranda']);

    $this->tahun = AcademicYear::factory()
        ->untukSekolah($this->sekolah)->aktif()->create(['name' => '2026/2027']);

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'Ganjil',
    ]);
});

/** Props beranda siswa. */
function propsBeranda(TestCase $test, User $siswa): array
{
    return masukUji($test, $siswa)
        ->get('/siswa')
        ->assertOk()
        ->viewData('page')['props'];
}

it('mengirim jumlah ujian yang sudah dan belum dituntaskan', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $props = propsBeranda($this, $data['siswa'][0]);

    // Satu ujian, belum dikerjakan.
    expect($props['kemajuan'])
        ->toMatchArray(['total' => 1, 'selesai' => 0, 'terlewat' => 0, 'menunggu' => 1]);
});

it('menghitung attempt yang dikirim sebagai selesai, dan yang batal tidak', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    $attempt = attemptBerjalan($peserta);
    app(MesinUjian::class)->kumpulkan($attempt);

    expect(propsBeranda($this, $data['siswa'][0])['kemajuan'])
        ->toMatchArray(['total' => 1, 'selesai' => 1, 'terlewat' => 0, 'menunggu' => 0]);

    // Attempt yang DIBATALKAN bukan "selesai": anaknya harus mengulang, dan
    // menghitungnya sebagai tuntas membuat ia melihat "1 dari 1 selesai"
    // untuk ujian yang justru harus ia kerjakan lagi.
    masukUji($this, $this->admin)
        ->post("/admin/events/{$data['event']->id}/monitoring/peserta/{$peserta->id}/reset", [
            'alasan' => 'Perangkat mati di menit pertama; ujian diulang.',
        ]);

    expect(propsBeranda($this, $data['siswa'][0])['kemajuan'])
        ->toMatchArray(['total' => 1, 'selesai' => 0, 'terlewat' => 0, 'menunggu' => 1]);
});

it('tidak menghitung peserta yang dikecualikan sebagai ujian yang menunggu', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    ExamParticipant::query()->where('id', $data['peserta'][0]->id)->update([
        'status' => ExamParticipant::STATUS_DIKECUALIKAN,
    ]);

    // Ujian yang tidak akan pernah datang bukan ujian yang menunggu, dan
    // memasukkannya ke penyebut membuat anak mengejar sesuatu yang tidak ada.
    expect(propsBeranda($this, $data['siswa'][0])['kemajuan'])
        ->toMatchArray(['total' => 0, 'selesai' => 0, 'terlewat' => 0, 'menunggu' => 0]);
});

it('menghitung ujian yang jendelanya lewat sebagai terlewat, bukan menunggu', function () {
    // Sesi yang jendelanya sudah berakhir dan TIDAK pernah dikerjakan. Ia
    // tetap tampil di daftar sebagai riwayat, tetapi sampai 2026-11-14 ia
    // ikut terhitung "masih menunggu" - sehingga anak yang sudah melewati
    // jendelanya mengejar ujian yang tidak akan pernah datang.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin, [
        'buka' => '-4 hours',
        'tutup' => '-3 hours',
        'akhir' => '-2 hours',
    ]);

    $props = propsBeranda($this, $data['siswa'][0]);

    $kemajuan = $props['kemajuan'];

    expect($kemajuan)
        ->toMatchArray(['total' => 1, 'selesai' => 0, 'terlewat' => 1, 'menunggu' => 0])
        // Barisnya TETAP ada: yang berubah hanya artinya, bukan tampilnya.
        ->and($props['jadwal'])->toHaveCount(1)
        ->and($props['jadwal'][0]['status'])->toBe('terlewat')
        // Ketiga angka harus menjumlah ke totalnya. Inilah yang menangkap
        // penghitungan ganda: baris terlewat yang juga dihitung "menunggu"
        // akan membuat jumlahnya lebih besar dari totalnya, dan tidak ada
        // satu pun dari ketiganya yang terlihat salah sendirian.
        ->and($kemajuan['selesai'] + $kemajuan['terlewat'] + $kemajuan['menunggu'])
        ->toBe($kemajuan['total']);
});

it('menghitung mundur ke pembukaan sesi, dan nol sesudahnya', function () {
    // Sesi dibuka 10 menit dari sekarang: hitung mundurnya harus mendekati
    // 600 detik, bukan tanggal apa pun.
    $data = siapkanUjianSiswa(
        $this->sekolah,
        $this->tahun,
        $this->semester,
        $this->admin,
        ['buka' => '+10 minutes'],
    );

    $baris = collect(propsBeranda($this, $data['siswa'][0])['jadwal'])->first();

    expect($baris['hitung_mundur'])->toBeInt()
        ->and($baris['hitung_mundur'])->toBeGreaterThan(500)
        ->and($baris['hitung_mundur'])->toBeLessThanOrEqual(600);
});

it('tidak menghitung mundur pada sesi yang sudah dibuka', function () {
    // Sesi yang sudah lewat jendela bukanya bukan "menunggu", dan hitung
    // mundur ke waktu yang tidak akan datang adalah janji yang tidak
    // ditepati.
    $data = siapkanUjianSiswa(
        $this->sekolah,
        $this->tahun,
        $this->semester,
        $this->admin,
        ['buka' => '-30 minutes'],
    );

    $baris = collect(propsBeranda($this, $data['siswa'][0])['jadwal'])->first();

    expect($baris['hitung_mundur'])->toBeNull();
});

it('mengirim keadaan presensi anak, termasuk apakah ia membuka gerbang', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    // Belum diverifikasi.
    $baris = collect(propsBeranda($this, $data['siswa'][0])['jadwal'])
        ->firstWhere('peserta_id', $peserta->id);

    expect($baris['presensi']['status'])->toBe(StatusPresensi::Pending->value)
        ->and($baris['presensi']['label'])->toBe('Belum diverifikasi')
        ->and($baris['presensi']['membuka_gerbang'])->toBeFalse()
        ->and($baris['presensi']['jam'])->toBeNull();

    // Diverifikasi pengawas lewat jalur yang sama dengan produksi.
    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);

    $baris = collect(propsBeranda($this, $data['siswa'][0])['jadwal'])
        ->firstWhere('peserta_id', $peserta->id);

    expect($baris['presensi']['status'])->toBe(StatusPresensi::Hadir->value)
        ->and($baris['presensi']['label'])->toBe('Hadir')
        ->and($baris['presensi']['membuka_gerbang'])->toBeTrue()
        ->and($baris['presensi']['jam'])->not->toBeNull();
});

it('menandai presensi terlambat yang tidak membuka gerbang', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    $record = AttendanceRecord::query()->where('exam_participant_id', $peserta->id)->firstOrFail();

    // Lewat jalur yang SAMA dengan pengawas di ruang: `KonteksRuang::dari()`
    // membaca penugasan pengguna yang sedang masuk, jadi tanpa `masukUji()`
    // sebagai pengawas ia tidak menemukan ruangnya - dan test ini akan gagal
    // pada sebab yang tidak ada hubungannya dengan yang diuji.
    masukUji($this, $data['pengawas']);

    app(KehadiranService::class)->catat(
        $record,
        StatusPresensi::Terlambat,
        KonteksRuang::dari($data['ruang'][0]),
    );

    // Ujian ini dibuat dengan `allow_late_entry` false - lihat
    // `siapkanUjianSiswa()`. Terlambat TETAP terlambat; yang menentukan
    // gerbangnya dibuka atau tidak adalah ujiannya, bukan statusnya.
    $baris = collect(propsBeranda($this, $data['siswa'][0])['jadwal'])
        ->firstWhere('peserta_id', $peserta->id);

    expect($baris['presensi']['status'])->toBe(StatusPresensi::Terlambat->value)
        ->and($baris['presensi']['membuka_gerbang'])->toBeFalse();
});

it('menghitung kemajuan dari beberapa ujian dalam satu event', function () {
    // Dua ujian: satu sudah dikirim, satu belum. Keadaan ini yang membuat
    // ringkasan kemajuan berarti - dengan satu ujian ia hanya mengulang
    // barisnya sendiri.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    $mapelKedua = Subject::factory()->create([
        'school_id' => $this->sekolah->id,
        'code' => 'BIN-'.$data['event']->id,
        'name' => 'Bahasa Indonesia '.$data['event']->id,
    ]);

    EventSubject::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $data['event']->id,
        'subject_id' => $mapelKedua->id,
    ]);

    $ujianKedua = app(ExamRegistrar::class)->buat($data['event'], [
        'subject_id' => $mapelKedua->id,
        'question_package_id' => paketUntukUjian($this->admin, $mapelKedua)->id,
        'title' => 'Ujian Kedua Bahasa Indonesia',
        'exam_type' => 'Ujian akhir semester',
        'duration_minutes' => 60,
        'class_id' => [],
    ], $this->admin->id);

    $sesiKedua = app(SesiService::class)->buat($ujianKedua, [
        'access_opens_at' => now()->addDay(),
        'access_closes_at' => now()->addDay()->addHour(),
        'hard_end_at' => now()->addDay()->addHours(2),
        'event_room_id' => [$data['event']->rooms()->first()->id],
    ]);

    app(PenempatanService::class)->tarikPeserta($ujianKedua);
    app(PenempatanService::class)->sebar($ujianKedua, $sesiKedua);

    // Ujian pertama dituntaskan.
    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);
    app(MesinUjian::class)->kumpulkan(attemptBerjalan($peserta));

    expect(propsBeranda($this, $data['siswa'][0])['kemajuan'])
        ->toMatchArray(['total' => 2, 'selesai' => 1, 'terlewat' => 0, 'menunggu' => 1]);
});

it('mengirim denah kursi pada kartu utama, dan hanya pada kartu utama', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    // Sesi dibuka sekarang, sehingga ujiannya menjadi kartu utama.
    $props = propsBeranda($this, $data['siswa'][0]);

    expect($props['utama'])->not->toBeNull()
        ->and($props['utama']['tempat'])->not->toBeNull()
        ->and($props['utama']['tempat']['kursi'])->toBeInt()
        // Geometrinya dari server: dua kursi per meja, empat meja per baris.
        ->and($props['utama']['tempat']['denah']['kursi_per_meja'])->toBe(2)
        ->and($props['utama']['tempat']['denah']['meja_per_baris'])->toBe(4)
        ->and($props['utama']['tempat']['denah']['sel'])->not->toBeEmpty();

    // Nama penghuni lain tidak ikut keluar - panel ini untuk mencari kursi
    // sendiri, dan nama anak lain di layar anak lain adalah kebocoran.
    $sel = $props['utama']['tempat']['denah']['sel'][0];
    expect($sel)->toHaveKeys(['kursi', 'meja', 'baris', 'kolom', 'terisi', 'saya'])
        ->and($sel)->not->toHaveKey('peserta');

    // Baris daftar TIDAK membawa denah: setiap denah berarti satu
    // pemanggilan `PetaKursiEvent::sel()` untuk seluruh ruangan.
    foreach ($props['jadwal'] as $baris) {
        expect($baris)->not->toHaveKey('tempat');
    }
});

it('mengirim denah pada kartu yang sesinya belum dibuka', function () {
    // Sesi baru dibuka satu jam lagi. Kartunya tetap "utama" - lihat
    // `bentukBaris()`: yang menentukan adalah belum selesai dan jendelanya
    // belum lewat - dan anak yang sedang berjalan ke ruangnya justru
    // membutuhkan denahnya SEKARANG, bukan sesudah gerbangnya terbuka.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin, [
        'buka' => '+1 hour',
        'tutup' => '+3 hours',
        'akhir' => '+4 hours',
    ]);

    $props = propsBeranda($this, $data['siswa'][0]);

    expect($props['utama'])->not->toBeNull()
        ->and($props['utama']['tempat'])->not->toBeNull()
        ->and($props['utama']['tempat']['denah']['sel'])->not->toBeEmpty()
        ->and($props['utama']['tempat']['selesai'])->toBeFalse();
});
