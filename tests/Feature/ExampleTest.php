<?php

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * Perilaku akar aplikasi.
 *
 * "/" bukan halaman publik: ia mengarahkan tamu ke login, dan pengguna yang
 * sudah masuk ke konsol perannya. Test bawaan yang mengharapkan 200 di sini
 * salah sejak awal - ia lulus pada skeleton polos sebelum ada autentikasi.
 */
it('mengarahkan tamu dari akar ke login', function () {
    $this->get('/')->assertRedirect('/login');
});

it('mengarahkan pengguna yang sudah masuk ke konsol perannya', function () {
    $sekolah = School::factory()->create();
    $admin = User::factory()->admin()->untukSekolah($sekolah)->create();

    $this->actingAs($admin)->get('/')->assertRedirect('/dashboard');
});
