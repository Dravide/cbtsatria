<?php

use App\Domain\Identity\Actions\AttemptLogin;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * Akun nonaktif gagal lewat jalur yang sama dengan password salah.
 *
 * 'is_active' => true duduk di dalam larik kredensial Auth::attempt, bukan
 * sebagai pemeriksaan sesudahnya. Kalau ia jadi pemeriksaan sesudahnya,
 * akan lahir cabang kedua - dan cabang kedua itu yang biasanya melahirkan
 * pesan "akun Anda dinonaktifkan", yang membocorkan bahwa akunnya ada.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();
});

it('menolak akun nonaktif', function () {
    User::factory()->siswa()->nonaktif()->untukSekolah($this->sekolah)->create([
        'username' => 'nonaktif.uji',
        'password' => 'rahasia-benar',
    ]);

    $this->post('/login', [
        'username' => 'nonaktif.uji',
        'password' => 'rahasia-benar',
    ]);

    $this->assertGuest();
});

it('memberi pesan yang sama seperti password salah', function () {
    User::factory()->siswa()->nonaktif()->untukSekolah($this->sekolah)->create([
        'username' => 'nonaktif.uji',
        'password' => 'rahasia-benar',
    ]);

    $nonaktif = $this->post('/login', [
        'username' => 'nonaktif.uji',
        'password' => 'rahasia-benar',
    ]);

    $salah = $this->post('/login', [
        'username' => 'nonaktif.uji',
        'password' => 'password-keliru',
    ]);

    expect(pesanGalat($nonaktif))->toBe(pesanGalat($salah))
        ->and(pesanGalat($nonaktif)['username'])->toBe([AttemptLogin::PESAN_GAGAL]);
});

it('tidak menulis last_login_at untuk akun nonaktif', function () {
    $user = User::factory()->siswa()->nonaktif()->untukSekolah($this->sekolah)->create([
        'username' => 'nonaktif.uji',
        'password' => 'rahasia-benar',
    ]);

    $this->post('/login', ['username' => 'nonaktif.uji', 'password' => 'rahasia-benar']);

    expect($user->fresh()->last_login_at)->toBeNull();
});
