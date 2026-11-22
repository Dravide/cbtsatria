<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Models\PlatformActivityLog;
use App\Models\PlatformAnnouncement;

beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Induk']);

    $this->super = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'super.operator',
    ]);
    $this->super->forceFill(['is_super_admin' => true])->save();

    $this->adminBiasa = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.biasa',
        'password' => 'rahasia123',
    ]);
});

it('menolak admin biasa mengakses konsol /super dengan 403', function () {
    $this->actingAs($this->adminBiasa)
        ->get('/super')
        ->assertForbidden();
});

it('mengarahkan super admin dari /dashboard ke /super dan menampilkan command center', function () {
    $this->actingAs($this->super)
        ->get('/dashboard')
        ->assertRedirect('/super');

    $this->actingAs($this->super)
        ->get('/super')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Super/Dashboard')
            ->where('kpi.total_sekolah', 1),
        );
});

it('dapat mendaftarkan sekolah baru beserta admin pertama, TA aktif, dan semester dalam satu transaksi', function () {
    $this->actingAs($this->super)
        ->post('/super/sekolah', [
            'code' => 'SMPN9',
            'name' => 'SMP Negeri 9',
            'npsn' => '20209999',
            'timezone' => 'Asia/Jakarta',
            'admin_name' => 'Admin SMPN 9',
            'admin_username' => 'admin.smpn9',
        ])
        ->assertRedirect()
        ->assertSessionHas('sukses')
        ->assertSessionHas('sandi_baru');

    $baru = School::query()->where('code', 'SMPN9')->firstOrFail();

    expect($baru->npsn)->toBe('20209999')
        ->and(User::query()->withoutGlobalScopes()->where('school_id', $baru->id)->where('role', Role::Admin->value)->count())->toBe(1)
        ->and(AcademicYear::query()->withoutGlobalScopes()->where('school_id', $baru->id)->where('is_active', true)->count())->toBe(1)
        ->and(PlatformActivityLog::query()->where('school_id', $baru->id)->count())->toBe(1);
});

it('memblokir login sekolah yang ditangguhkan oleh super admin', function () {
    $this->actingAs($this->super)
        ->patch('/super/sekolah/'.$this->sekolah->id, [
            'name' => $this->sekolah->name,
            'npsn' => $this->sekolah->npsn,
            'status' => 'ditangguhkan',
            'timezone' => 'Asia/Jakarta',
            'maks_siswa' => 500,
            'maks_storage_mb' => 2048,
            'modul_ai' => true,
            'modul_publik' => true,
            'modul_waktu_luang' => false,
        ])
        ->assertRedirect();

    auth()->logout();

    $this->post('/login', [
        'username' => 'admin.biasa',
        'password' => 'rahasia123',
    ])->assertSessionHasErrors('username');
});

it('mendukung mode dukung impersonate masuk dan keluar kembali ke super admin', function () {
    $sekolahTarget = School::factory()->create(['name' => 'SMK Target']);
    $adminTarget = User::factory()->admin()->untukSekolah($sekolahTarget)->create([
        'username' => 'admin.target',
    ]);

    $this->actingAs($this->super)
        ->post('/super/sekolah/'.$sekolahTarget->id.'/impersonasi', [
            'alasan' => 'Membantu pengecekan jadwal ujian',
        ])
        ->assertRedirect('/admin');

    expect(auth()->id())->toBe($adminTarget->id)
        ->and(session('impersonasi_super.super_id'))->toBe($this->super->id);

    $this->post('/super/impersonasi/keluar')
        ->assertRedirect('/super');

    expect(auth()->id())->toBe($this->super->id)
        ->and(session('impersonasi_super'))->toBeNull();
});

it('dapat menayangkan dan menghapus pengumuman global serta membuka halaman sistem dan audit', function () {
    $this->actingAs($this->super)
        ->post('/super/pengumuman', [
            'title' => 'Pemeliharaan Minggu Malam',
            'body' => 'Server akan direstart pukul 22:00 WIB.',
            'nada' => PlatformAnnouncement::NADA_PENTING,
        ])
        ->assertRedirect();

    $item = PlatformAnnouncement::query()->firstOrFail();

    $this->actingAs($this->super)->get('/super/sistem')->assertOk();
    $this->actingAs($this->super)->get('/super/audit')->assertOk();

    $this->actingAs($this->super)
        ->delete('/super/pengumuman/'.$item->id)
        ->assertRedirect();

    expect(PlatformAnnouncement::query()->count())->toBe(0);
});
