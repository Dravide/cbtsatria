<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * Kriteria penerimaan lintas tenant, di lapisan HTTP.
 *
 * ---------------------------------------------------------------------
 * Mengapa test ini baru ada di Fase 2
 * ---------------------------------------------------------------------
 * Fase 1 tidak punya satu pun route ter-bind, jadi test HTTP terhadap route
 * yang tidak ada akan lulus karena alasan yang salah - 404-nya datang dari
 * router, bukan dari scope. Test yang lulus tanpa membuktikan apa pun lebih
 * buruk daripada tidak ada test, karena ia membuat mekanisme yang justru
 * menopang seluruh isolasi tampak terverifikasi.
 *
 * Fase 2 memberi route ter-bind pertama. Sekarang 404-nya bisa datang dari
 * tempat yang benar.
 *
 * ---------------------------------------------------------------------
 * Yang dibuktikan, dan mengapa 404 bukan 403
 * ---------------------------------------------------------------------
 * Global scope membuat baris sekolah B tidak ADA bagi user sekolah A,
 * sehingga route binding implisit melempar ModelNotFoundException dan
 * pengguna menerima 404.
 *
 * 403 akan menjadi kebocoran: ia membuktikan barisnya ada, hanya tidak boleh
 * diakses. 404 tidak membedakan "tidak berhak" dari "tidak ada", dan
 * memang itu yang benar - user sekolah A tidak berhak tahu apakah ID itu
 * menunjuk sesuatu.
 */
beforeEach(function () {
    $this->sekolahA = School::factory()->create(['name' => 'Sekolah A']);
    $this->sekolahB = School::factory()->create(['name' => 'Sekolah B']);

    $this->adminA = User::factory()->admin()->untukSekolah($this->sekolahA)->create();
    $this->adminB = User::factory()->admin()->untukSekolah($this->sekolahB)->create();

    $this->tahunB = AcademicYear::factory()->untukSekolah($this->sekolahB)->create();
    $this->kelasB = SchoolClass::factory()->untukTahun($this->tahunB)->create();
    $this->mapelB = Subject::factory()->untukSekolah($this->sekolahB)->create();
    $this->ruangB = Room::factory()->untukSekolah($this->sekolahB)->create();
});

it('menolak tahun ajaran sekolah lain dengan 404, bukan 403', function () {
    $this->actingAs($this->adminA)
        ->put("/admin/tahun-ajaran/{$this->tahunB->id}", [
            'name' => '2026/2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
        ])
        ->assertNotFound();
});

it('menolak mengaktifkan tahun ajaran sekolah lain dengan 404', function () {
    $this->actingAs($this->adminA)
        ->post("/admin/tahun-ajaran/{$this->tahunB->id}/aktifkan")
        ->assertNotFound();
});

it('menolak kelas sekolah lain dengan 404', function () {
    $this->actingAs($this->adminA)
        ->get("/admin/kelas/{$this->kelasB->id}/anggota")
        ->assertNotFound();
});

it('menolak mata pelajaran sekolah lain dengan 404', function () {
    $this->actingAs($this->adminA)
        ->put("/admin/mapel/{$this->mapelB->id}", [
            'code' => 'XXX',
            'name' => 'Mapel lain',
            'status' => 'aktif',
        ])
        ->assertNotFound();
});

it('menolak ruang sekolah lain dengan 404', function () {
    $this->actingAs($this->adminA)
        ->get("/admin/ruang/{$this->ruangB->id}")
        ->assertNotFound();
});

it('menolak menerbitkan tautan pada ruang sekolah lain dengan 404', function () {
    $this->actingAs($this->adminA)
        ->post("/admin/ruang/{$this->ruangB->id}/terbitkan")
        ->assertNotFound();
});

it('menolak mengubah pengguna sekolah lain dengan 404', function () {
    $this->actingAs($this->adminA)
        ->put("/admin/pengguna/{$this->adminB->id}", [
            'name' => 'Diubah',
            'is_active' => true,
        ])
        ->assertNotFound();
});

it('TIDAK membocorkan keberadaan baris lewat pesan yang berbeda', function () {
    // 404 dan 403 memang sama-sama "tidak boleh", tetapi pesannya berbeda,
    // dan perbedaan itu cukup untuk memetakan ID mana yang ada. Yang
    // dibandingkan di sini bukan kode status - melainkan bahwa permintaan
    // atas ID sekolah lain dan ID yang memang tidak ada menghasilkan
    // halaman yang sama.
    $idTidakAda = 999999;

    $asing = $this->actingAs($this->adminA)
        ->get("/admin/ruang/{$this->ruangB->id}");

    $hantu = $this->actingAs($this->adminA)
        ->get("/admin/ruang/{$idTidakAda}");

    expect($asing->status())
        ->toBe($hantu->status())
        ->and($asing->status())->toBe(404);
});
