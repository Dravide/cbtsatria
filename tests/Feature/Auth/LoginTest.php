<?php

use App\Domain\Identity\Actions\AttemptLogin;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * Satu pesan untuk semua kegagalan.
 *
 * Wireframe plate 01: "Username tidak ada atau password salah - pesannya
 * sama, supaya tidak bisa dipakai menebak akun mana yang ada."
 *
 * Test ini membandingkan DUA respons satu sama lain, bukan masing-masing
 * terhadap sebuah string yang diharapkan. Itu penting: membandingkan dengan
 * string tetap akan lulus meskipun kedua cabang berubah bersama-sama menjadi
 * pesan yang berbeda dari yang dimaksud. Yang harus dijamin adalah
 * KESAMAANNYA - byte per byte, seluruh peta pesannya.
 */

/** pesanGalat() ada di tests/Pest.php - dipakai juga oleh InactiveUserTest. */
beforeEach(function () {
    $sekolah = School::factory()->create();

    $this->user = User::factory()->siswa()->untukSekolah($sekolah)->create([
        'username' => 'siswa.uji',
        'password' => 'rahasia-benar',
    ]);
});

it('memberi pesan dan status identik untuk username tidak ada dan password salah', function () {
    $usernameSalah = $this->post('/login', [
        'username' => 'tidak-pernah-ada',
        'password' => 'apa-saja',
    ]);

    $passwordSalah = $this->post('/login', [
        'username' => 'siswa.uji',
        'password' => 'password-keliru',
    ]);

    expect($usernameSalah->status())->toBe($passwordSalah->status())
        ->and(pesanGalat($usernameSalah))->toBe(pesanGalat($passwordSalah));
});

it('memakai pesan gagal yang ditetapkan plate 01', function () {
    $respons = $this->post('/login', [
        'username' => 'tidak-pernah-ada',
        'password' => 'apa-saja',
    ]);

    expect(pesanGalat($respons)['username'])->toBe([AttemptLogin::PESAN_GAGAL]);
});

it('tidak mengungkap apakah username ada lewat kode status', function () {
    $usernameSalah = $this->post('/login', ['username' => 'hantu', 'password' => 'x']);
    $passwordSalah = $this->post('/login', ['username' => 'siswa.uji', 'password' => 'x']);

    expect($usernameSalah->status())->toBe($passwordSalah->status());
});

it('masuk dengan kredensial benar', function () {
    $this->post('/login', [
        'username' => 'siswa.uji',
        'password' => 'rahasia-benar',
    ])->assertRedirect('/siswa');

    $this->assertAuthenticatedAs($this->user);
});

it('mencatat last_login_at saat berhasil', function () {
    expect($this->user->last_login_at)->toBeNull();

    $this->post('/login', [
        'username' => 'siswa.uji',
        'password' => 'rahasia-benar',
    ]);

    expect($this->user->fresh()->last_login_at)->not->toBeNull();
});

it('menolak form kosong dengan pesan kelengkapan, bukan pesan akun', function () {
    $respons = $this->post('/login', ['username' => '', 'password' => '']);

    // "required" adalah soal kelengkapan form, bukan keberadaan akun - ia
    // tidak membocorkan apa pun. Pesannya pun berbeda dari PESAN_GAGAL.
    expect(pesanGalat($respons)['username'])->toBe(['Username wajib diisi.'])
        ->and(pesanGalat($respons)['password'])->toBe(['Password wajib diisi.']);
});
