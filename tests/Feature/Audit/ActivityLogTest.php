<?php

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Identity\Actions\AttemptLogin;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Log aktivitas: satu baris per aksi, dan kegagalan mencatat TIDAK
 * menggagalkan aksinya.
 *
 * Prinsip kedua itu yang paling mudah hilang saat refactor. Ia berlaku
 * umum: autosave jawaban siswa jauh lebih penting daripada lognya, dan
 * aturan yang sama dipakai di sini.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();

    $this->user = User::factory()->siswa()->untukSekolah($this->sekolah)->create([
        'username' => 'siswa.log',
        'password' => 'rahasia-benar',
    ]);
});

it('menulis satu baris saat login berhasil', function () {
    RateLimiter::clear('login:siswa.log|127.0.0.1');

    $sebelum = ActivityLog::tanpaTenant()->count();

    $this->post('/login', ['username' => 'siswa.log', 'password' => 'rahasia-benar']);

    $baris = ActivityLog::tanpaTenant()->orderByDesc('id')->first();

    expect(ActivityLog::tanpaTenant()->count())->toBe($sebelum + 1)
        ->and($baris->action)->toBe('Login')
        ->and($baris->school_id)->toBe($this->sekolah->id)
        ->and($baris->user_id)->toBe($this->user->id);
});

it('tidak menulis log saat login gagal', function () {
    $sebelum = ActivityLog::tanpaTenant()->count();

    $this->post('/login', ['username' => 'siswa.log', 'password' => 'salah']);

    expect(ActivityLog::tanpaTenant()->count())->toBe($sebelum);
});

it('tetap membiarkan aksi sukses meski logger gagal', function () {
    // write() dibuat protected supaya test bisa merusaknya tanpa menyentuh
    // log() - log() itulah yang menelan kegagalan, jadi meng-override log()
    // justru membuang perilaku yang sedang diuji.
    $this->app->instance(ActivityLogger::class, new class(app(SchoolResolver::class), app(Factory::class)) extends ActivityLogger
    {
        protected function write(string $action, ?Model $entity, array $metadata): ActivityLog
        {
            throw new RuntimeException('logger sengaja dirusak');
        }
    });

    $aksi = app(AttemptLogin::class);

    $user = $aksi->execute('siswa.log', 'rahasia-benar');

    expect($user->id)->toBe($this->user->id);
});

it('mencatat aksi dengan metadata', function () {
    $this->actingAs($this->user);

    $log = app(ActivityLogger::class)->log('Mengubah sesuatu', $this->user, ['catatan' => 'uji']);

    expect($log)->not->toBeNull()
        ->and($log->metadata_json)->toBe(['catatan' => 'uji'])
        ->and($log->entity_type)->toBe('User')
        ->and($log->entity_id)->toBe($this->user->id);
});
