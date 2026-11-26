<?php

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Models\ErrorLog;
use App\Services\ErrorLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMPN 1 Cipanas']);

    $this->super = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'super.galat',
    ]);
    $this->super->forceFill(['is_super_admin' => true])->save();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.galat',
    ]);
});

it('menghasilkan kode galat terstruktur sesuai portal rute', function () {
    $e = new RuntimeException('Contoh galat');

    expect(ErrorLogger::catat($e, Request::create('/admin/events/1/mapel', 'GET')))->toBe('ER-ADM-001')
        ->and(ErrorLogger::catat(new RuntimeException('Galat guru'), Request::create('/guru/bank-soal', 'GET')))->toBe('ER-GR-001')
        ->and(ErrorLogger::catat(new RuntimeException('Galat pengawas'), Request::create('/p/ruang-01', 'GET')))->toBe('ER-PGW-001')
        ->and(ErrorLogger::catat(new RuntimeException('Galat siswa'), Request::create('/siswa/ujian', 'GET')))->toBe('ER-SSW-001')
        ->and(ErrorLogger::catat(new RuntimeException('Galat super'), Request::create('/super/sekolah', 'GET')))->toBe('ER-SUP-001')
        ->and(ErrorLogger::catat(new RuntimeException('Galat publik'), Request::create('/login', 'GET')))->toBe('ER-PUB-001')
        ->and(ErrorLogger::catat(new RuntimeException('Galat sistem'), null))->toBe('ER-SYS-001');
});

it('mengelompokkan galat berulang dengan fingerprint sama dan membuat kode baru setelah selesai', function () {
    $req1 = Request::create('/admin/events/1/mapel', 'GET');
    $req2 = Request::create('/admin/events/1/mapel', 'GET');

    $lempar = fn () => new RuntimeException('Kegagalan sama');

    $kode1 = ErrorLogger::catat($lempar(), $req1);
    $kode2 = ErrorLogger::catat($lempar(), $req2);

    expect($kode1)->toBe('ER-ADM-001')
        ->and($kode2)->toBe('ER-ADM-001')
        ->and(ErrorLog::query()->where('code', 'ER-ADM-001')->value('occurrences'))->toBe(2);

    ErrorLog::query()->where('code', 'ER-ADM-001')->update(['resolved_at' => now()]);

    $req3 = Request::create('/admin/events/1/mapel', 'GET');
    $kode3 = ErrorLogger::catat($lempar(), $req3);

    expect($kode3)->toBe('ER-ADM-002')
        ->and(ErrorLog::query()->count())->toBe(2);
});

it('menyensor field sensitif pada konteks payload yang disimpan ke DB', function () {
    $req = Request::create('/admin/pengguna', 'POST', [
        'name' => 'Budi',
        'password' => 'rahasia-super',
        '_token' => 'csrf-123',
        'api_key' => 'sk-secret-key',
    ]);

    $kode = ErrorLogger::catat(new RuntimeException('Gagal simpan'), $req);
    $log = ErrorLog::query()->where('code', $kode)->firstOrFail();

    expect($log->context['input']['name'])->toBe('Budi')
        ->and($log->context['input']['password'])->toBe('[REDACTED]')
        ->and($log->context['input']['_token'])->toBe('[REDACTED]')
        ->and($log->context['input']['api_key'])->toBe('[REDACTED]');
});

it('mencatat galat 500 dari rute admin ke DB dan mengirim errorCode ke halaman Galat saat debug mati', function () {
    Route::middleware(['web', 'auth'])->get('/admin/_uji-galat-500', function () {
        throw new RuntimeException('Simulasi kerusakan server');
    });

    config(['app.debug' => false]);

    $this->actingAs($this->admin)
        ->get('/admin/_uji-galat-500')
        ->assertStatus(500)
        ->assertInertia(fn ($page) => $page
            ->component('Galat')
            ->where('status', 500)
            ->where('errorCode', 'ER-ADM-001')
            ->where('portal', 'ADM'),
        );

    $this->assertDatabaseHas('error_logs', [
        'code' => 'ER-ADM-001',
        'portal' => 'ADM',
        'status_code' => 500,
        'school_id' => $this->sekolah->id,
        'user_id' => $this->admin->id,
    ]);
});

it('mengizinkan super admin melihat, memfilter, dan menyelesaikan log galat serta menolak admin biasa', function () {
    $this->actingAs($this->admin)
        ->get('/super/galat')
        ->assertForbidden();

    $kode = ErrorLogger::catat(new RuntimeException('Bug mapel'), Request::create('/admin/events/1/mapel', 'GET'));
    $log = ErrorLog::query()->where('code', $kode)->firstOrFail();

    $this->actingAs($this->super)
        ->get('/super/galat?q=ER-ADM-001')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Super/Galat')
            ->has('items', 1)
            ->where('items.0.code', 'ER-ADM-001'),
        );

    $this->actingAs($this->super)
        ->get('/super/galat/'.$log->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Super/GalatShow')
            ->where('galat.code', 'ER-ADM-001'),
        );

    $this->actingAs($this->super)
        ->post('/super/galat/'.$log->id.'/selesai', [
            'resolution_note' => 'Sudah dibersihkan cache konfigurasi.',
        ])
        ->assertRedirect();

    $log->refresh();
    expect($log->resolved_at)->not->toBeNull()
        ->and($log->resolved_by)->toBe($this->super->id)
        ->and($log->resolution_note)->toBe('Sudah dibersihkan cache konfigurasi.');
});
