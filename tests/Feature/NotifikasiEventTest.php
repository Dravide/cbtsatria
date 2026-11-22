<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Services\InsidenService;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Attendance\Services\PencatatPelanggaran;
use App\Domain\Event\Models\EventNotification;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Support\PengaturanBawaan;
use Inertia\Testing\AssertableInertia;

it('menyimpan pengaturan notifikasi event melalui /admin/pengaturan', function () {
    $sekolah = School::factory()->create();
    $admin = User::factory()->admin()->untukSekolah($sekolah)->create();

    $payload = array_merge(PengaturanBawaan::NILAI, [
        'notifikasi_terkunci' => true,
        'notifikasi_pelanggaran' => false,
        'notifikasi_terputus_massal' => true,
        'notifikasi_insiden' => false,
        'notifikasi_suara' => true,
    ]);

    masukUji($this, $admin)
        ->put('/admin/pengaturan', $payload)
        ->assertSessionHas('sukses');

    $tersimpan = $sekolah->refresh()->settings;
    expect($tersimpan['notifikasi_pelanggaran'])->toBeFalse()
        ->and($tersimpan['notifikasi_insiden'])->toBeFalse()
        ->and($tersimpan['notifikasi_suara'])->toBeTrue();
});

it('mencatat notifikasi saat peserta terkunci dan saat pengawas mencatat insiden', function () {
    $sekolah = School::factory()->create();
    $admin = User::factory()->admin()->untukSekolah($sekolah)->create();
    $tahun = AcademicYear::factory()->untukSekolah($sekolah)->create();
    $semester = Semester::factory()->create([
        'school_id' => $sekolah->id,
        'academic_year_id' => $tahun->id,
    ]);

    $data = siapkanUjianSiswa($sekolah, $tahun, $semester, $admin);
    $peserta = $data['peserta']->first();
    $siswa = $data['siswa'][0];
    $ruang = $data['ruang']->first();

    $data['event']->forceFill([
        'violation_thresholds' => [
            'keluar_tab' => 1,
            'layar_terbagi' => 1,
            'jendela_mengambang' => 1,
        ],
    ])->save();

    verifikasiHadir($this, $data['pengawas'], $ruang, $peserta);
    mulaiAttempt($this, $siswa, $peserta, $data['token']);
    $attempt = attemptBerjalan($peserta);

    // Pelanggaran tingkat 3 (sesi_ganda) -> langsung terkunci & memicu notifikasi kritis.
    app(PencatatPelanggaran::class)->catat($attempt, 'sesi_ganda');

    expect(EventNotification::query()
        ->where('event_id', $data['event']->id)
        ->where('jenis', EventNotification::JENIS_TERKUNCI)
        ->where('tingkat', EventNotification::TINGKAT_BAD)
        ->exists())->toBeTrue();

    // Pengawas mencatat insiden -> memicu notifikasi insiden.
    masukUji($this, $data['pengawas']);
    app(InsidenService::class)->catat(
        $ruang,
        KonteksRuang::dari($ruang),
        'listrik',
        'Listrik ruang padam 2 menit',
    );

    expect(EventNotification::query()
        ->where('event_id', $data['event']->id)
        ->where('jenis', EventNotification::JENIS_INSIDEN)
        ->exists())->toBeTrue();

    // Endpoint ringkas & halaman log + tandai dibaca.
    masukUji($this, $admin)
        ->getJson("/admin/events/{$data['event']->id}/notifikasi/ringkas")
        ->assertOk()
        ->assertJsonPath('belum_dibaca', 2)
        ->assertJsonPath('kritis_belum_dibaca', 1);

    $notif = EventNotification::query()->where('event_id', $data['event']->id)->firstOrFail();

    masukUji($this, $admin)
        ->postJson("/admin/events/{$data['event']->id}/notifikasi/{$notif->id}/baca")
        ->assertOk();

    expect($notif->refresh()->read_at)->not->toBeNull();

    masukUji($this, $admin)
        ->post("/admin/events/{$data['event']->id}/notifikasi/baca-semua")
        ->assertSessionHas('sukses');

    expect(EventNotification::query()
        ->where('event_id', $data['event']->id)
        ->whereNull('read_at')
        ->count())->toBe(0);

    masukUji($this, $admin)
        ->get("/admin/events/{$data['event']->id}/notifikasi")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Notifikasi')
            ->where('angka.total', 2)
            ->where('angka.terkunci', 1)
        );
});
