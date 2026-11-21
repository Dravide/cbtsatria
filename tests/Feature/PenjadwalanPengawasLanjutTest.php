<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\Subject;
use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Exam\Models\SupervisorSessionExclusion;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\PengawasService;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

function buatPengawasEventDasar(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->admin()->untukSekolah($school)->create();
    $tahun = AcademicYear::factory()->untukSekolah($school)->create();
    $semester = Semester::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $tahun->id,
    ]);
    $mapel = Subject::factory()->untukSekolah($school)->create();
    $event = SchoolEvent::factory()
        ->untukSekolah($school)
        ->for($tahun, 'academicYear')
        ->for($semester, 'semester')
        ->create();

    $room1 = Room::factory()->untukSekolah($school)->create(['code' => 'R-01', 'name' => 'Ruang 1']);
    $room2 = Room::factory()->untukSekolah($school)->create(['code' => 'R-02', 'name' => 'Ruang 2']);

    $er1 = EventRoom::create([
        'school_id' => $school->id,
        'event_id' => $event->id,
        'room_id' => $room1->id,
        'capacity' => 30,
    ]);
    $er2 = EventRoom::create([
        'school_id' => $school->id,
        'event_id' => $event->id,
        'room_id' => $room2->id,
        'capacity' => 30,
    ]);

    $p1 = User::factory()->pengawas()->untukSekolah($school)->create(['name' => 'Pengawas Satu']);
    $p2 = User::factory()->pengawas()->untukSekolah($school)->create(['name' => 'Pengawas Dua']);

    foreach ([$p1, $p2] as $p) {
        EventCommitteeMember::create([
            'school_id' => $school->id,
            'event_id' => $event->id,
            'user_id' => $p->id,
            'name' => $p->name,
            'position' => 'pengawas',
            'scope' => 'event',
            'status' => 'aktif',
        ]);
    }

    $ujian = app(ExamRegistrar::class)->buat($event, [
        'subject_id' => $mapel->id,
        'title' => 'ASAS '.$mapel->name,
        'exam_type' => 'ASAS',
        'duration_minutes' => 90,
        'class_id' => [],
    ], $admin->id);

    $sesi1 = app(SesiService::class)->buat($ujian, [
        'access_opens_at' => '2026-12-01 07:30:00',
        'access_closes_at' => '2026-12-01 09:00:00',
        'hard_end_at' => '2026-12-01 09:30:00',
        'event_room_id' => [$er1->id, $er2->id],
    ]);

    $sesi2 = app(SesiService::class)->buat($ujian, [
        'access_opens_at' => '2026-12-01 10:00:00',
        'access_closes_at' => '2026-12-01 11:30:00',
        'hard_end_at' => '2026-12-01 12:00:00',
        'event_room_id' => [$er1->id, $er2->id],
    ]);

    $sesi3 = app(SesiService::class)->buat($ujian, [
        'access_opens_at' => '2026-12-02 07:30:00',
        'access_closes_at' => '2026-12-02 09:00:00',
        'hard_end_at' => '2026-12-02 09:30:00',
        'event_room_id' => [$er1->id],
    ]);

    return compact('school', 'admin', 'event', 'ujian', 'sesi1', 'sesi2', 'sesi3', 'p1', 'p2');
}

it('menyimpan aturan penjadwalan pengawas dan mengirim matriks ke halaman pengawas', function () {
    $d = buatPengawasEventDasar();

    masukSebagai($this, $d['admin'])
        ->post("/admin/events/{$d['event']->id}/pengawas/aturan", [
            'maks_per_event' => 3,
            'maks_per_hari' => 2,
            'target_per_ruang' => 1,
            'prioritas' => 'merata',
            'hormati_pengecualian' => true,
            'hindari_wali_kelas' => true,
            'hindari_guru_mapel' => false,
            'tolak_melebihi_kuota' => true,
        ])
        ->assertSessionHas('sukses');

    masukSebagai($this, $d['admin'])
        ->get("/admin/events/{$d['event']->id}/pengawas")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Pengawas')
            ->where('aturan.maks_per_event', 3)
            ->where('aturan.maks_per_hari', 2)
            ->where('aturan.tolak_melebihi_kuota', true)
            ->has('matriks', 2)
            ->has('sesi_kolom', 3)
        );
});

it('mengelola pengecualian sesi, kosongkan hari, dan kuota khusus per pengawas', function () {
    $d = buatPengawasEventDasar();

    // Toggle pengecualian sesi 1 untuk p1 (aktif -> nonaktif -> aktif)
    masukSebagai($this, $d['admin'])
        ->post("/admin/events/{$d['event']->id}/pengawas/pengecualian", [
            'exam_session_id' => $d['sesi1']->id,
            'supervisor_id' => $d['p1']->id,
            'reason' => 'Rapat dinas',
        ])
        ->assertSessionHas('sukses');

    expect(SupervisorSessionExclusion::query()
        ->where('event_id', $d['event']->id)
        ->where('exam_session_id', $d['sesi1']->id)
        ->where('supervisor_id', $d['p1']->id)
        ->exists())->toBeTrue();

    // Kosongkan hari kedua untuk p2
    masukSebagai($this, $d['admin'])
        ->post("/admin/events/{$d['event']->id}/pengawas/kosongkan-hari", [
            'supervisor_id' => $d['p2']->id,
            'tanggal' => '2026-12-02',
            'reason' => 'Izin',
        ])
        ->assertSessionHas('sukses');

    expect(SupervisorSessionExclusion::query()
        ->where('event_id', $d['event']->id)
        ->where('exam_session_id', $d['sesi3']->id)
        ->where('supervisor_id', $d['p2']->id)
        ->exists())->toBeTrue();

    // Kuota khusus per pengawas
    masukSebagai($this, $d['admin'])
        ->post("/admin/events/{$d['event']->id}/pengawas/kuota", [
            'supervisor_id' => $d['p1']->id,
            'maks_mengawas' => 1,
        ])
        ->assertSessionHas('sukses');

    expect(EventCommitteeMember::query()
        ->where('event_id', $d['event']->id)
        ->where('user_id', $d['p1']->id)
        ->value('maks_mengawas'))->toBe(1);
});

it('penugasan otomatis menghormati kuota event, kuota harian, pengecualian sesi, dan pembagian merata', function () {
    $d = buatPengawasEventDasar();
    $service = app(PengawasService::class);

    $service->simpanAturan($d['event'], [
        'maks_per_event' => 2,
        'maks_per_hari' => 1,
        'target_per_ruang' => 1,
        'prioritas' => 'merata',
        'hormati_pengecualian' => true,
        'hindari_wali_kelas' => false,
        'hindari_guru_mapel' => false,
        'tolak_melebihi_kuota' => false,
    ]);

    // P1 dikosongkan pada sesi 1
    SupervisorSessionExclusion::create([
        'school_id' => $d['school']->id,
        'event_id' => $d['event']->id,
        'exam_session_id' => $d['sesi1']->id,
        'supervisor_id' => $d['p1']->id,
        'reason' => 'Dikosongkan',
    ]);

    $hasil = $service->tugaskanOtomatis($d['event'], $d['admin']->id);

    // P1 tidak boleh ditugaskan di sesi 1
    $ruangSesi1 = ExamSessionRoom::query()->where('exam_session_id', $d['sesi1']->id)->pluck('id');
    expect(SupervisionAssignment::query()
        ->whereIn('exam_session_room_id', $ruangSesi1)
        ->where('supervisor_id', $d['p1']->id)
        ->exists())->toBeFalse();

    // Total beban masing-masing pengawas tidak melebihi maks_per_event = 2
    $beban = $service->bebanEvent($d['event']);
    expect($beban[$d['p1']->id] ?? 0)->toBeLessThanOrEqual(2)
        ->and($beban[$d['p2']->id] ?? 0)->toBeLessThanOrEqual(2)
        ->and($hasil['penugasan'])->toBeGreaterThan(0);
});

it('menolak penugasan manual saat tolak_melebihi_kuota aktif jika pengawas dikosongkan atau penuh', function () {
    $d = buatPengawasEventDasar();
    $service = app(PengawasService::class);

    $service->simpanAturan($d['event'], [
        'maks_per_event' => 1,
        'maks_per_hari' => 1,
        'target_per_ruang' => 1,
        'prioritas' => 'merata',
        'hormati_pengecualian' => true,
        'hindari_wali_kelas' => false,
        'hindari_guru_mapel' => false,
        'tolak_melebihi_kuota' => true,
    ]);

    SupervisorSessionExclusion::create([
        'school_id' => $d['school']->id,
        'event_id' => $d['event']->id,
        'exam_session_id' => $d['sesi1']->id,
        'supervisor_id' => $d['p1']->id,
    ]);

    $ruangSesi1 = ExamSessionRoom::query()->where('exam_session_id', $d['sesi1']->id)->firstOrFail();
    $ruangSesi2 = ExamSessionRoom::query()->where('exam_session_id', $d['sesi2']->id)->firstOrFail();
    $ruangSesi3 = ExamSessionRoom::query()->where('exam_session_id', $d['sesi3']->id)->firstOrFail();

    // P1 dikosongkan pada sesi 1 -> harus ditolak
    expect(fn () => $service->tugaskan($ruangSesi1, $d['p1'], $d['admin']->id))
        ->toThrow(ValidationException::class);

    // P1 ditugaskan di sesi 2 -> berhasil (kuota 1/1 tercapai)
    $service->tugaskan($ruangSesi2, $d['p1'], $d['admin']->id);

    // P1 ditugaskan lagi di sesi 3 -> harus ditolak karena kuota event = 1
    expect(fn () => $service->tugaskan($ruangSesi3, $d['p1'], $d['admin']->id))
        ->toThrow(ValidationException::class);
});

it('mendukung tugaskan otomatis per sesi dan kosongkan sesi melalui endpoint', function () {
    $d = buatPengawasEventDasar();

    masukSebagai($this, $d['admin'])
        ->post("/admin/events/{$d['event']->id}/pengawas/sesi/{$d['sesi1']->id}/otomatis")
        ->assertSessionHas('sukses');

    $ruangSesi1 = ExamSessionRoom::query()->where('exam_session_id', $d['sesi1']->id)->pluck('id');
    expect(SupervisionAssignment::query()->whereIn('exam_session_room_id', $ruangSesi1)->count())->toBe(2);

    masukSebagai($this, $d['admin'])
        ->post("/admin/events/{$d['event']->id}/pengawas/sesi/{$d['sesi1']->id}/kosongkan")
        ->assertSessionHas('sukses');

    expect(SupervisionAssignment::query()->whereIn('exam_session_room_id', $ruangSesi1)->count())->toBe(0);
});
