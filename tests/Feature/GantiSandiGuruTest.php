<?php

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMP Negeri 1']);
    $this->guru = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'guru.sandi',
    ]);
});

it('menampilkan rekomendasi ganti sandi saat guru masih memakai sandi bawaan', function () {
    app(SchoolResolver::class)->forget();

    $this->actingAs($this->guru)
        ->get('/guru')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guru/Dashboard')
            ->where('sandi_bawaan', true)
        );

    $this->get('/guru/sandi')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guru/Sandi')
            ->where('sandi_bawaan', true)
        );
});

it('mengganti sandi guru dan menghilangkan rekomendasi sandi bawaan', function () {
    app(SchoolResolver::class)->forget();

    $this->actingAs($this->guru)
        ->put('/guru/sandi', [
            'current_password' => User::SANDI_BAWAAN,
            'password' => 'SandiBaruGuru99!',
            'password_confirmation' => 'SandiBaruGuru99!',
        ])
        ->assertRedirect('/guru');

    expect(Hash::check('SandiBaruGuru99!', $this->guru->refresh()->password))->toBeTrue();

    $this->get('/guru')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guru/Dashboard')
            ->where('sandi_bawaan', false)
        );
});

it('menolak ganti sandi guru jika sandi saat ini salah', function () {
    app(SchoolResolver::class)->forget();

    $this->actingAs($this->guru)
        ->put('/guru/sandi', [
            'current_password' => 'salah-sekali',
            'password' => 'SandiBaruGuru99!',
            'password_confirmation' => 'SandiBaruGuru99!',
        ])
        ->assertSessionHasErrors('current_password');
});
