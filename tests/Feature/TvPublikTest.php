<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\EventAnnouncement;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 3']);
    $this->sekolah->forceFill(['papan_slug' => 'smkn3-tv-ef56'])->save();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.tv',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'Ganjil',
    ]);
});

it('mengembalikan 404 bila slug tidak ditemukan pada /tv dan /tv/denyut', function () {
    $this->get('/tv/tidak-ada')->assertNotFound();
    $this->getJson('/tv/tidak-ada/denyut')->assertNotFound();
});

it('menampilkan potret live, pengumuman aktif, dan denyut JSON tanpa data siswa', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $data['event']->forceFill(['status' => EventStatus::Ongoing])->save();

    app(SesiService::class)->mulai($data['sesi'], $this->admin->id);

    EventAnnouncement::query()->withoutGlobalScopes()->create([
        'school_id' => $this->sekolah->id,
        'event_id' => $data['event']->id,
        'title' => 'Harap tenang di lorong',
        'body' => 'Ujian sedang berlangsung di seluruh ruang.',
        'nada' => EventAnnouncement::NADA_PENTING,
        'expires_at' => now()->addHours(2),
        'created_by' => $this->admin->id,
    ]);

    Auth::logout();
    app(SchoolResolver::class)->forget();

    $props = $this->get('/tv/'.$this->sekolah->papan_slug)
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['potret']['sekolah'])->toBe('SMK Negeri 3')
        ->and($props['potret']['keadaan'])->toBe('berjalan')
        ->and($props['potret']['ruang'])->toHaveCount(1)
        ->and($props['pengumuman'])->toHaveCount(1)
        ->and($props['pengumuman'][0]['judul'])->toBe('Harap tenang di lorong')
        ->and($props['selesai_iso'])->not->toBeNull();

    $denyut = $this->getJson('/tv/'.$this->sekolah->papan_slug.'/denyut')
        ->assertOk()
        ->json();

    expect($denyut['potret']['keadaan'])->toBe('berjalan')
        ->and($denyut['pengumuman'])->toHaveCount(1);

    $json = json_encode($props).json_encode($denyut);

    foreach ($data['siswa'] as $siswa) {
        expect($json)->not->toContain($siswa->name);
    }
});
