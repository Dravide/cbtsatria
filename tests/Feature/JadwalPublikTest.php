<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\EventAnnouncement;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);
    $this->sekolah->forceFill(['papan_slug' => 'smkn1-jadwal-ab12'])->save();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.jadwal',
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

it('mengembalikan 404 bila slug sekolah tidak ditemukan atau belum dinyalakan', function () {
    $this->get('/jadwal/tidak-ada')->assertNotFound();

    $this->sekolah->forceFill(['papan_slug' => null])->save();

    $this->get('/jadwal/smkn1-jadwal-ab12')->assertNotFound();
});

it('menampilkan jadwal sesi dan pengumuman berlaku tanpa membocorkan data siswa atau token', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $data['event']->forceFill(['status' => EventStatus::Ongoing])->save();

    EventAnnouncement::query()->withoutGlobalScopes()->create([
        'school_id' => $this->sekolah->id,
        'event_id' => $data['event']->id,
        'title' => 'Sesi 2 mundur 15 menit',
        'body' => 'Karena upacara bendera, sesi kedua dimulai pukul 09:45.',
        'nada' => EventAnnouncement::NADA_PENTING,
        'expires_at' => now()->addHours(4),
        'created_by' => $this->admin->id,
    ]);

    EventAnnouncement::query()->withoutGlobalScopes()->create([
        'school_id' => $this->sekolah->id,
        'event_id' => $data['event']->id,
        'title' => 'Pengumuman kedaluwarsa',
        'body' => 'Tidak boleh tayang lagi.',
        'nada' => EventAnnouncement::NADA_INFO,
        'expires_at' => now()->subMinute(),
        'created_by' => $this->admin->id,
    ]);

    Auth::logout();
    app(SchoolResolver::class)->forget();

    $props = $this->get('/jadwal/'.$this->sekolah->papan_slug)
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['sekolah'])->toBe('SMK Negeri 1')
        ->and($props['events'])->toHaveCount(1);

    $event = $props['events'][0];

    expect($event['pengumuman'])->toHaveCount(1)
        ->and($event['pengumuman'][0]['judul'])->toBe('Sesi 2 mundur 15 menit')
        ->and($event['hari'])->toHaveCount(1)
        ->and($event['hari'][0]['sesi'])->toHaveCount(1);

    $json = json_encode($props);

    foreach ($data['siswa'] as $siswa) {
        expect($json)->not->toContain($siswa->name);
    }
});

it('tidak menampilkan sesi batal maupun event draft/arsip', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $data['event']->forceFill(['status' => EventStatus::Draft])->save();

    Auth::logout();
    app(SchoolResolver::class)->forget();

    $propsDraft = $this->get('/jadwal/'.$this->sekolah->papan_slug)
        ->assertOk()
        ->viewData('page')['props'];

    expect($propsDraft['events'])->toBeEmpty();

    $data['event']->forceFill(['status' => EventStatus::Ready])->save();
    $data['sesi']->forceFill(['status' => StatusSesi::Batal])->save();

    $propsBatal = $this->get('/jadwal/'.$this->sekolah->papan_slug)
        ->assertOk()
        ->viewData('page')['props'];

    expect($propsBatal['events'])->toHaveCount(1)
        ->and($propsBatal['events'][0]['hari'])->toBeEmpty();
});
