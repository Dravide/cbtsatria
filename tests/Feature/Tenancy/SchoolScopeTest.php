<?php

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;

/**
 * Scope tenant: kapan menyala, kapan diam, dan bagaimana dilewati.
 *
 * `if` di dalam SchoolScope::apply() adalah salah satu baris paling
 * menentukan di aplikasi ini. Kalau ia hilang, Auth::attempt menyaring
 * dengan school_id null dan SETIAP login gagal. Test "diam saat belum
 * ter-resolve" di bawah ini menjaga baris itu tetap ada.
 */
beforeEach(function () {
    $this->sekolahA = School::factory()->create();
    $this->sekolahB = School::factory()->create();

    $this->adminA = User::factory()->admin()->untukSekolah($this->sekolahA)->create();
    $this->adminB = User::factory()->admin()->untukSekolah($this->sekolahB)->create();
});

it('menyala saat ada pengguna terautentikasi', function () {
    $this->actingAs($this->adminA);

    expect(User::query()->count())->toBe(1);
});

it('DIAM saat belum ada pengguna terautentikasi', function () {
    // Inilah jalur login. Tanpa perilaku ini, User::where('username', ...)
    // akan membawa where('school_id', null) dan tidak menemukan siapa pun.
    expect(User::query()->count())->toBe(2);
});

it('membiarkan username yang unik global ditemukan sebelum login', function () {
    // Persis yang dilakukan Auth::attempt.
    $ditemukan = User::query()->where('username', $this->adminB->username)->first();

    expect($ditemukan)->not->toBeNull()
        ->and($ditemukan->id)->toBe($this->adminB->id);
});

it('bisa dilewati sengaja lewat withoutGlobalScope', function () {
    $this->actingAs($this->adminA);

    $semua = User::tanpaTenant()->count();

    expect($semua)->toBe(2);
});

it('bisa dilewati sengaja lewat resolver withoutTenant()', function () {
    $this->actingAs($this->adminA);

    $resolver = app(SchoolResolver::class);

    $sebelum = User::query()->count();

    $dalam = $resolver->withoutTenant(fn () => User::query()->count());

    expect($dalam)->toBe(2)
        ->and(User::query()->count())->toBe($sebelum, 'konteks tenant tidak dipulihkan setelah withoutTenant()');
});

it('tidak membatasi sekolah itu sendiri', function () {
    // School bukan tabel anak: ia tabel induknya, dan kalau ia ikut ter-scope
    // tidak ada yang bisa membacanya.
    expect(School::query()->count())->toBe(2);
});
