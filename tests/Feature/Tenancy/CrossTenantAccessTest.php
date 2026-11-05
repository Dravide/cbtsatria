<?php

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Kriteria penerimaan Fase 1: lintas tenant -> 404, bukan 403.
 *
 * ---------------------------------------------------------------------
 * Mengapa diuji di lapisan model, bukan lewat HTTP
 * ---------------------------------------------------------------------
 * Belum ada satu pun route Fase 1 yang memakai route model binding
 * (/admin hanya route statis). Menguji "/admin/pengguna/{id}" sekarang
 * akan lulus karena route-nya TIDAK ADA, bukan karena isolasinya bekerja -
 * test hampa yang lebih berbahaya daripada tidak ada test, karena ia
 * memberi rasa aman. Route ter-bind pertama muncul di Fase 2.
 *
 * Jadi yang diuji di sini adalah mekanismenya sendiri: baris sekolah lain
 * TIDAK TERLIHAT. Begitu ia tidak terlihat, route binding di Fase 2
 * otomatis melempar ModelNotFoundException -> 404, tanpa kode per-controller.
 *
 * ---------------------------------------------------------------------
 * Yang harus tetap benar setelah Fase 2 menambah route ter-bind
 * ---------------------------------------------------------------------
 * Tambahkan test HTTP-nya di sana: user sekolah A meminta ID sekolah B ->
 * 404, dan BUKAN 403. Kalau suatu saat Policy ikut memeriksa school_id,
 * statusnya berubah jadi 403 dan test itu gagal - dan itu tujuannya.
 */
beforeEach(function () {
    $this->sekolahA = School::factory()->create(['name' => 'Sekolah A']);
    $this->sekolahB = School::factory()->create(['name' => 'Sekolah B']);

    $this->adminA = User::factory()->admin()->untukSekolah($this->sekolahA)->create();
    $this->adminB = User::factory()->admin()->untukSekolah($this->sekolahB)->create();
});

it('menyembunyikan pengguna sekolah lain dari query biasa', function () {
    $this->actingAs($this->adminA);

    $terlihat = User::query()->pluck('id');

    expect($terlihat)->toContain($this->adminA->id)
        ->not->toContain($this->adminB->id);
});

it('membuat find() atas baris sekolah lain mengembalikan null', function () {
    $this->actingAs($this->adminA);

    // find(), bukan findOrFail(): yang diuji di sini adalah ketidak-terlihatan
    // barisnya. findOrFail() menambahkan langkah kedua, dan langkah kedua itu
    // yang diperiksa di test berikutnya.
    expect(User::find($this->adminB->id))->toBeNull();
});

it('melempar ModelNotFoundException dari findOrFail(), yang diterjemahkan menjadi 404', function () {
    $this->actingAs($this->adminA);

    // Inilah yang dilakukan Laravel saat route model binding: melempar
    // ModelNotFoundException. Handler-nya menerjemahkan itu menjadi 404 -
    // bukan 403. 403 akan membuktikan barisnya ada, dan itu kebocoran.
    expect(fn () => User::findOrFail($this->adminB->id))
        ->toThrow(ModelNotFoundException::class);
});

it('tetap menemukan barisnya sendiri', function () {
    $this->actingAs($this->adminA);

    expect(User::find($this->adminA->id))->not->toBeNull();
});

it('mengizinkan dashboard Admin dibuka', function () {
    $this->actingAs($this->adminA)->get('/admin')->assertOk();
});

it('menolak tamu yang belum login', function () {
    $this->get('/admin')->assertRedirect('/login');
});
