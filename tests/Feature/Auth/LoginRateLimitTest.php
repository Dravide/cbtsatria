<?php

use App\Domain\Identity\Actions\AttemptLogin;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Gagal 3x -> kunci 60 detik. Wireframe plate 01 menuliskannya di layar.
 *
 * Percobaan ke-4 harus ditolak MESKI passwordnya benar - kalau tidak,
 * pembatasnya hanya memperlambat penebak, bukan menghentikannya.
 */
beforeEach(function () {
    $sekolah = School::factory()->create();

    $this->user = User::factory()->siswa()->untukSekolah($sekolah)->create([
        'username' => 'siswa.kunci',
        'password' => 'rahasia-benar',
    ]);

    RateLimiter::clear('login:siswa.kunci|127.0.0.1');
});

it('mengunci setelah tiga kegagalan', function () {
    for ($i = 0; $i < AttemptLogin::MAX_ATTEMPTS; $i++) {
        $this->post('/login', ['username' => 'siswa.kunci', 'password' => 'salah']);
    }

    expect(RateLimiter::tooManyAttempts('login:siswa.kunci|127.0.0.1', AttemptLogin::MAX_ATTEMPTS))
        ->toBeTrue();
});

it('menolak percobaan keempat meski passwordnya benar', function () {
    for ($i = 0; $i < AttemptLogin::MAX_ATTEMPTS; $i++) {
        $this->post('/login', ['username' => 'siswa.kunci', 'password' => 'salah']);
    }

    $this->post('/login', ['username' => 'siswa.kunci', 'password' => 'rahasia-benar']);

    $this->assertGuest();
});

it('memakai masa kunci 60 detik', function () {
    $this->post('/login', ['username' => 'siswa.kunci', 'password' => 'salah']);

    expect(RateLimiter::availableIn('login:siswa.kunci|127.0.0.1'))
        ->toBeLessThanOrEqual(AttemptLogin::DECAY_SECONDS)
        ->toBeGreaterThan(0);
});

it('membersihkan hitungan setelah login berhasil', function () {
    $this->post('/login', ['username' => 'siswa.kunci', 'password' => 'salah']);
    $this->post('/login', ['username' => 'siswa.kunci', 'password' => 'rahasia-benar']);

    expect(RateLimiter::attempts('login:siswa.kunci|127.0.0.1'))->toBe(0);
});
