<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;

/**
 * Tanda air ujian - Fase C.
 *
 * =====================================================================
 * Yang diuji, dan yang TIDAK bisa diuji
 * =====================================================================
 * Screenshot tidak bisa diblokir peramban, jadi yang dijaga di sini bukan
 * pencegahannya melainkan BAHAN tanda airnya: identitas yang dikirim ke
 * laman pengerjaan harus identitas siswa yang SUNGGUH mengerjakan, bukan
 * nama siapa pun yang kebetulan sedang masuk.
 *
 * Test ini juga menjaga satu batas: tanda air memuat nama dan nomor induk,
 * dan TIDAK memuat NISN, nomor HP, atau alamat. Yang dibutuhkan untuk
 * membuktikan pemilik tangkapan layar adalah "ini anak ini di ruang ini",
 * dan NISN adalah nomor yang lebih sensitif daripada yang dituntut
 * keperluan itu.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.tandaair',
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

it('mengirim nama dan nomor induk siswa yang mengerjakan, dan hanya itu', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $peserta = $data['peserta'][0];
    $siswa = $data['siswa'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $siswa, $peserta, $data['token']);

    $props = masukUji($this, $siswa)
        ->get("/siswa/ujian/{$peserta->id}/kerjakan")
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['tanda_air'])->toBe([
        'nama' => $siswa->name,
        'nomor_induk' => $siswa->username,
    ]);

    // Identitas siswa LAIN tidak ikut. `tanda_air` dibaca dari pengguna yang
    // sedang masuk, bukan dari peserta - dan dua hal itu berbeda begitu satu
    // perangkat dipakai bergantian di ruang ujian.
    $lain = $data['siswa'][1];

    expect($props['tanda_air']['nomor_induk'])->not->toBe($lain->username);

    // Yang TIDAK dikirim, dan itu bagian dari rancangannya.
    expect(array_keys($props['tanda_air']))->toBe(['nama', 'nomor_induk']);
});

it('memakai identitas pengguna yang sedang masuk, bukan identitas peserta', function () {
    // Satu perangkat dipakai bergantian adalah keadaan yang biasa di ruang
    // ujian. Tanda air yang membaca nama dari baris peserta akan menulis nama
    // anak lain pada tangkapan layar orang yang sedang mengerjakan - dan itu
    // justru tuduhan palsu yang paling mahal.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $peserta = $data['peserta'][0];
    $siswa = $data['siswa'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $siswa, $peserta, $data['token']);

    // Nama akunnya diubah SESUDAH attempt dimulai: kalau tanda airnya dibaca
    // dari snapshot peserta atau dari attempt, perubahan ini tidak akan
    // terlihat - dan yang terkirim adalah nama basi.
    $siswa->forceFill(['name' => 'Nama Diperbarui'])->save();

    app(SchoolResolver::class)->forget();

    $props = masukUji($this, $siswa->fresh())
        ->get("/siswa/ujian/{$peserta->id}/kerjakan")
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['tanda_air']['nama'])->toBe('Nama Diperbarui');
});

it('mengirim tanda_air_aktif apa adanya dari event ke laman pengerjaan', function () {
    // Tanda air bisa dimatikan per event sejak tombolnya ada di Pengaturan
    // sekolah. Yang diuji di sini KABEL-nya: flag yang disimpan event harus
    // sampai ke laman pengerjaan, karena laman itulah satu-satunya yang
    // mencetaknya.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $data['event']->forceFill(['show_watermark' => false])->save();

    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    $props = masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/kerjakan")
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['tanda_air_aktif'])->toBeFalse()
        // Bahannya TETAP dikirim. Laman yang memutuskan mencetak atau
        // tidak, dan mematikan pengiriman identitasnya berarti mengubah
        // satu centang menjadi perubahan bentuk payload - perubahan yang
        // tidak diminta siapa pun.
        ->and($props['tanda_air'])->toBe([
            'nama' => $data['siswa'][0]->name,
            'nomor_induk' => $data['siswa'][0]->username,
        ]);
});

it('menyebut tanda air di aturan main hanya bila event menyalakannya', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $data['event']->forceFill(['show_watermark' => false])->save();

    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);

    $props = masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/gerbang")
        ->assertOk()
        ->viewData('page')['props'];

    expect(collect($props['aturan'])->contains(fn (string $a) => str_contains($a, 'nomor induk')))
        ->toBeFalse();
});
