<?php

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

beforeEach(function () {
    $this->sekolah = School::factory()->create(['code' => 'smkn1']);
    $this->lain = School::factory()->create(['code' => 'smkn2']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.pengawas',
    ]);

    $this->pengawas1 = User::factory()->untukSekolah($this->sekolah)->create([
        'role' => 'supervisor',
        'name' => 'Pengawas Satu',
        'username' => 'pengawas.1',
    ]);

    $this->pengawas2 = User::factory()->untukSekolah($this->sekolah)->create([
        'role' => 'supervisor',
        'name' => 'Pengawas Dua',
        'username' => 'pengawas.2',
    ]);

    $this->siswa = User::factory()->untukSekolah($this->sekolah)->create([
        'role' => 'student',
        'name' => 'Siswa Satu',
        'username' => 'siswa.1',
    ]);

    $this->pengawasLain = User::factory()->untukSekolah($this->lain)->create([
        'role' => 'supervisor',
        'name' => 'Pengawas Sekolah Lain',
        'username' => 'pengawas.lain',
    ]);
});

function masukSebagaiAdmin(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

it('menghapus satu akun pengawas permanen', function () {
    masukSebagaiAdmin($this, $this->admin)
        ->delete("/admin/pengguna/pengawas/{$this->pengawas1->id}")
        ->assertRedirect()
        ->assertSessionHas('sukses');

    expect(DB::table('users')->where('id', $this->pengawas1->id)->exists())->toBeFalse()
        ->and(DB::table('users')->where('id', $this->pengawas2->id)->exists())->toBeTrue();
});

it('menolak menghapus akun selain pengawas lewat endpoint hapus pengawas', function () {
    masukSebagaiAdmin($this, $this->admin)
        ->delete("/admin/pengguna/pengawas/{$this->siswa->id}")
        ->assertRedirect()
        ->assertSessionHas('galat');

    expect(DB::table('users')->where('id', $this->siswa->id)->exists())->toBeTrue();
});

it('tidak bisa menghapus pengawas sekolah lain', function () {
    masukSebagaiAdmin($this, $this->admin)
        ->delete("/admin/pengguna/pengawas/{$this->pengawasLain->id}")
        ->assertNotFound();

    expect(DB::table('users')->where('id', $this->pengawasLain->id)->exists())->toBeTrue();
});

it('menghapus beberapa akun pengawas terpilih sekaligus', function () {
    masukSebagaiAdmin($this, $this->admin)
        ->delete('/admin/pengguna/pengawas/terpilih', [
            'id' => [$this->pengawas1->id, $this->pengawas2->id, $this->siswa->id, $this->pengawasLain->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('sukses');

    expect(DB::table('users')->where('id', $this->pengawas1->id)->exists())->toBeFalse()
        ->and(DB::table('users')->where('id', $this->pengawas2->id)->exists())->toBeFalse()
        ->and(DB::table('users')->where('id', $this->siswa->id)->exists())->toBeTrue()
        ->and(DB::table('users')->where('id', $this->pengawasLain->id)->exists())->toBeTrue();
});
