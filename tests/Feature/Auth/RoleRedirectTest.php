<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Satu form, empat tujuan.
 *
 * Wireframe plate 01: "Setelah valid -> redirect sesuai role, bukan dipilih
 * di UI." Test ini memasukkan keempat peran lewat SATU route yang sama.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();
});

it('mengarahkan setiap peran ke konsolnya sendiri', function (Role $peran, string $tujuan) {
    $user = User::factory()->untukSekolah($this->sekolah)->create([
        'role' => $peran,
        'username' => 'user.'.$peran->value,
        'password' => 'rahasia',
    ]);

    RateLimiter::clear('login:user.'.$peran->value.'|127.0.0.1');

    $this->post('/login', [
        'username' => $user->username,
        'password' => 'rahasia',
    ])->assertRedirect($tujuan);
})->with([
    'admin' => [Role::Admin, '/admin'],
    'guru' => [Role::Teacher, '/guru'],
    'siswa' => [Role::Student, '/siswa'],
    'pengawas' => [Role::Supervisor, '/pengawas'],
]);

it('mengarahkan /dashboard sesuai peran, bukan memilih di UI', function () {
    $admin = User::factory()->admin()->untukSekolah($this->sekolah)->create();

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertRedirect('/admin');
});

it('memetakan peran ke jalur yang benar', function (Role $peran, string $tujuan) {
    expect($peran->dashboardPath())->toBe($tujuan);
})->with([
    'admin' => [Role::Admin, '/admin'],
    'guru' => [Role::Teacher, '/guru'],
    'siswa' => [Role::Student, '/siswa'],
    'pengawas' => [Role::Supervisor, '/pengawas'],
]);
