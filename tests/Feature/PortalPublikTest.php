<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Semester;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\EventAnnouncement;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 4']);
    $this->sekolah->forceFill(['papan_slug' => 'smkn4-portal-gh78'])->save();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.portal',
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

it('mengembalikan 404 bila slug tidak ditemukan atau papan belum dinyalakan', function () {
    $this->get('/info/tidak-ada')->assertNotFound();

    $this->sekolah->forceFill(['papan_slug' => null])->save();

    $this->get('/info/smkn4-portal-gh78')->assertNotFound();
});

it('menampilkan portal publik, sesi berjalan, pengumuman, dan jumlah kelas wali tanpa data siswa', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $data['event']->forceFill(['status' => EventStatus::Ongoing])->save();

    app(SesiService::class)->mulai($data['sesi'], $this->admin->id);

    EventAnnouncement::query()->withoutGlobalScopes()->create([
        'school_id' => $this->sekolah->id,
        'event_id' => $data['event']->id,
        'title' => 'Gerbang dibuka pukul 07:00',
        'body' => 'Peserta harap membawa kartu ujian.',
        'nada' => EventAnnouncement::NADA_PENTING,
        'expires_at' => now()->addHours(3),
        'created_by' => $this->admin->id,
    ]);

    $kelas = SchoolClass::query()
        ->withoutGlobalScopes()
        ->where('school_id', $this->sekolah->id)
        ->firstOrFail();

    // Sebelum wali_slug dinyalakan: jumlah_kelas_wali = 0
    Auth::logout();
    app(SchoolResolver::class)->forget();

    $propsAwal = $this->get('/info/'.$this->sekolah->papan_slug)
        ->assertOk()
        ->viewData('page')['props'];

    expect($propsAwal['sekolah'])->toBe('SMK Negeri 4')
        ->and($propsAwal['keadaan'])->toBe('berjalan')
        ->and($propsAwal['jumlah_kelas_wali'])->toBe(0)
        ->and($propsAwal['pengumuman']['judul'])->toBe('Gerbang dibuka pukul 07:00')
        ->and($propsAwal['sesi_ringkas']['berjalan'])->toBeTrue();

    // Setelah wali_slug dinyalakan: jumlah_kelas_wali = 1
    $kelas->forceFill(['wali_slug' => 'kelas-'.$kelas->id.'-xy12', 'status' => 'aktif'])->save();

    $propsSesudah = $this->get('/info/'.$this->sekolah->papan_slug)
        ->assertOk()
        ->viewData('page')['props'];

    expect($propsSesudah['jumlah_kelas_wali'])->toBe(1);

    $json = json_encode($propsSesudah);

    foreach ($data['siswa'] as $siswa) {
        expect($json)->not->toContain($siswa->name);
    }
});

it('memungkinkan admin mencetak poster QR portal saat papan menyala dan 404 saat mati', function () {
    $this->actingAs($this->admin)
        ->get('/admin/papan/poster')
        ->assertOk()
        ->assertSee('SMK Negeri 4')
        ->assertSee('/info/'.$this->sekolah->papan_slug);

    $this->sekolah->forceFill(['papan_slug' => null])->save();

    $this->actingAs($this->admin)
        ->get('/admin/papan/poster')
        ->assertNotFound();
});
