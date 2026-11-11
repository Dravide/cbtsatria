<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Support\TanggaPelanggaran;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * Layar penuh sebagai penjaga yang SUNGGUH berjalan - Fase A.
 *
 * =====================================================================
 * Yang diperbaiki berkas ini: pilihan yang tidak berakibat apa pun
 * =====================================================================
 * `enforce_fullscreen` ada di `school_events` sejak Fase 3, ditampilkan di
 * formulir Admin, dan sampai sekarang TIDAK ADA satu baris pun di peramban
 * yang membacanya - sama kelasnya dengan `Siswa` yang tidak punya penghasil.
 * Gerbang tidak memintanya, laman pengerjaan tidak menjaganya, dan
 * `keluar_layar_penuh` ada di `ExamViolation::JENIS` tanpa satu pun pemanggil
 * `lapor()`.
 *
 * Yang diuji di sini adalah kabelnya, bukan tampilannya:
 *
 *   1. gerbang MENGIRIM keadaan itu ke peramban, dan hanya bila eventnya
 *      menuntut;
 *   2. aturan main MENYEBUTKANNYA di depan, karena anak yang tidak diberi
 *      tahu akan menolak izin layar dan mengira ujiannya rusak;
 *   3. laman pengerjaan menerima keadaan yang sama - ia bisa dibuka langsung
 *      dari bilah alamat tanpa melewati gerbang;
 *   4. laporan `keluar_layar_penuh` menjadi baris tingkat 1, tanpa mengunci.
 *
 * =====================================================================
 * Yang TIDAK bisa diuji di sini
 * =====================================================================
 * `requestFullscreen()` dan `fullscreenchange` adalah perilaku peramban.
 * Test PHP tidak bisa membuktikan layarnya benar-benar penuh; yang bisa
 * dibuktikan adalah bahwa server mengirim keadaan yang membuat peramban
 * mencobanya, dan bahwa laporannya diterima sebagai pelanggaran biasa.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.layar',
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

it('mengirim wajib_layar_penuh ke gerbang DAN ke laman pengerjaan bila event menuntutnya', function () {
    // Event dibuat oleh `siapkanUjianSiswa`, jadi yang diubah adalah event
    // yang SUNGGUH dipakai peserta itu - bukan event lain yang kebetulan
    // ada di sekolah yang sama.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $data['event']->forceFill(['enforce_fullscreen' => true])->save();

    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);

    $gerbang = masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/gerbang")
        ->assertOk()
        ->viewData('page')['props'];

    expect($gerbang['wajib_layar_penuh'])->toBeTrue();

    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token'])
        ->assertRedirect("/siswa/ujian/{$peserta->id}/kerjakan");

    // Laman pengerjaan bisa dibuka langsung dari bilah alamat atau dari
    // riwayat, tanpa melewati gerbang - jadi ia tidak boleh bergantung pada
    // prop gerbang untuk tahu keadaannya.
    $kerjakan = masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/kerjakan")
        ->assertOk()
        ->viewData('page')['props'];

    expect($kerjakan['wajib_layar_penuh'])->toBeTrue();
});

it('tidak menuntut layar penuh bila eventnya tidak menuntut', function () {
    // Bawaannya `false`, dan itu disengaja: peramban hanya mengizinkan
    // `requestFullscreen()` dari gestur pengguna, sehingga sekolah yang belum
    // mengaturnya tidak boleh menemukan tombol yang tiba-tiba meminta izin.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $data['event']->forceFill(['enforce_fullscreen' => false])->save();

    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);

    $gerbang = masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/gerbang")
        ->assertOk()
        ->viewData('page')['props'];

    expect($gerbang['wajib_layar_penuh'])->toBeFalse();

    // Dan aturan mainnya tidak menyebut layar penuh sama sekali - kalimat
    // tentang layar yang tidak dipakai adalah janji yang tidak ditepati.
    expect(collect($gerbang['aturan'])->contains(fn (string $a) => str_contains($a, 'layar penuh')))
        ->toBeFalse();
});

it('menyebut layar penuh di aturan main SEBELUM tombol mulai ditekan', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $data['event']->forceFill(['enforce_fullscreen' => true])->save();

    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);

    $props = masukUji($this, $data['siswa'][0])
        ->get("/siswa/ujian/{$peserta->id}/gerbang")
        ->assertOk()
        ->viewData('page')['props'];

    // Anak yang tahu aturannya sejak awal tidak bisa mengatakan ia tidak
    // diberi tahu - dan menolak izin layar tanpa tahu sebabnya adalah
    // penolakan yang bukan pelanggaran, hanya ujian tanpa layar penuh.
    expect(collect($props['aturan'])->contains(fn (string $a) => str_contains($a, 'layar penuh')))
        ->toBeTrue();
});

it('mencatat keluar dari layar penuh sebagai tingkat 1, tanpa mengunci', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $data['event']->forceFill(['enforce_fullscreen' => true])->save();

    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/lapor", ['jenis' => 'keluar_layar_penuh'])
        ->assertOk()
        ->assertJsonPath('dikunci', false)
        ->assertJsonPath('jumlah', 1);

    $baris = ExamViolation::query()->sole();

    expect($baris->violation_type)->toBe('keluar_layar_penuh')
        // Tingkatnya dibaca dari tangga, bukan dari jenisnya di sini: satu
        // tempat yang menentukan keberatan, dan test yang menyalinnya hanya
        // akan setuju dengan dirinya sendiri.
        ->and($baris->severity)->toBe(TanggaPelanggaran::tingkat('keluar_layar_penuh'))
        ->and($baris->severity)->toBe(1)
        ->and($baris->reported_by_client)->toBeTrue();

    expect(attemptBerjalan($peserta)->dikunci())->toBeFalse();
});
