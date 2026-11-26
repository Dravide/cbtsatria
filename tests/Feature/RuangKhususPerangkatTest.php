<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Services\PencocokKodePengawas;
use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\EventParticipant;
use App\Domain\Exam\Enums\ModePenempatan;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Exam\Services\PenempatanKocok;
use App\Domain\Exam\Services\PengawasService;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

beforeEach(function () {
    $this->sekolah = School::factory()->create();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.khusus',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);
});

it('admin dapat menyimpan penanda khusus_perangkat pada ruang master', function () {
    $this->actingAs($this->admin)
        ->post('/admin/ruang', [
            'code' => 'LAB-01',
            'name' => 'Lab Komputer 1',
            'capacity' => 30,
            'device_count' => 30,
            'khusus_perangkat' => true,
            'status' => 'aktif',
        ])
        ->assertSessionHasNoErrors();

    $ruang = Room::query()->where('code', 'LAB-01')->firstOrFail();

    expect($ruang->khusus_perangkat)->toBeTrue();

    $this->actingAs($this->admin)
        ->put("/admin/ruang/{$ruang->id}", [
            'code' => 'LAB-01',
            'name' => 'Lab Komputer 1',
            'capacity' => 30,
            'device_count' => 30,
            'khusus_perangkat' => false,
            'status' => 'aktif',
        ])
        ->assertSessionHasNoErrors();

    expect($ruang->fresh()->khusus_perangkat)->toBeFalse();
});

it('memisahkan ruang khusus perangkat dari acak kelas reguler dan hanya diisi butuh_perangkat', function () {
    $data = siapkanPresensi(
        $this->sekolah,
        $this->tahun,
        $this->semester,
        $this->admin,
        ['R-01', 'LAB-01'],
        10,
    );

    $event = $data['event'];
    $ruangReguler = $data['ruang'][0]->eventRoom;
    $ruangLab = $data['ruang'][1]->eventRoom;

    $ruangReguler->room->forceFill([
        'capacity' => 6,
        'device_count' => 0,
        'khusus_perangkat' => false,
    ])->save();
    $ruangReguler->forceFill(['capacity_override' => 6])->save();

    $ruangLab->room->forceFill([
        'capacity' => 10,
        'device_count' => 10,
        'khusus_perangkat' => true,
    ])->save();
    $ruangLab->forceFill(['capacity_override' => 10])->save();

    // Tandai 2 peserta butuh_perangkat; sisa 8 peserta adalah reguler.
    $peserta = EventParticipant::query()
        ->where('event_id', $event->id)
        ->orderBy('id')
        ->get();

    $peserta[0]->forceFill(['butuh_perangkat' => true])->save();
    $peserta[1]->forceFill(['butuh_perangkat' => true])->save();

    $susunan = app(PenempatanKocok::class)->susun($event, 'BENIH1', ModePenempatan::Campur);

    $diLab = collect($susunan['per_ruang'][$ruangLab->id]);
    $diReguler = collect($susunan['per_ruang'][$ruangReguler->id]);

    // Hanya 2 peserta butuh_perangkat yang masuk LAB-01; siswa reguler tidak pernah masuk LAB-01
    // meskipun R-01 kurang 2 kursi (8 reguler vs 6 kapasitas R-01).
    expect($diLab)->toHaveCount(2)
        ->and($diLab->every(fn (EventParticipant $p) => (bool) $p->butuh_perangkat))->toBeTrue()
        ->and($diReguler)->toHaveCount(6)
        ->and($diReguler->every(fn (EventParticipant $p) => ! (bool) $p->butuh_perangkat))->toBeTrue()
        ->and($susunan['peserta_reguler'])->toBe(8)
        ->and($susunan['kapasitas_reguler'])->toBe(6)
        ->and($susunan['kurang_reguler'])->toBe(2)
        ->and($susunan['kurang'])->toBe(2)
        ->and($susunan['terisi_khusus'])->toBe(2)
        ->and($susunan['kapasitas_khusus'])->toBe(10);
});

it('melewati ruang khusus perangkat pada acak pengawas reguler dan menunjuk panitia event', function () {
    $data = siapkanPresensi(
        $this->sekolah,
        $this->tahun,
        $this->semester,
        $this->admin,
        ['R-01', 'LAB-01'],
        4,
    );

    $event = $data['event'];
    $sesiRuangReguler = $data['ruang'][0];
    $sesiRuangLab = $data['ruang'][1];

    $sesiRuangLab->eventRoom->room->forceFill([
        'khusus_perangkat' => true,
    ])->save();

    // Bersihkan kepanitiaan & penugasan bawaan helper agar skenario deterministik.
    EventCommitteeMember::query()->where('event_id', $event->id)->delete();
    SupervisionAssignment::query()->delete();

    // Pengawas reguler (jabatan Pengawas)
    $pengawasReguler = User::factory()->untukSekolah($this->sekolah)->create([
        'role' => Role::Supervisor,
        'name' => 'Pengawas Reguler',
        'username' => 'pengawas.reguler',
    ]);
    EventCommitteeMember::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $event->id,
        'user_id' => $pengawasReguler->id,
        'name' => $pengawasReguler->name,
        'position' => 'Pengawas Ruang',
        'scope' => EventCommitteeMember::SCOPE_EVENT,
        'status' => 'aktif',
    ]);

    // Panitia non-pengawas (misal Teknisi Lab berakun Admin)
    $panitiaTeknisi = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'name' => 'Teknisi Panitia',
        'username' => 'teknisi.panitia',
    ]);
    EventCommitteeMember::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $event->id,
        'user_id' => $panitiaTeknisi->id,
        'name' => $panitiaTeknisi->name,
        'position' => 'Teknisi Lab',
        'scope' => EventCommitteeMember::SCOPE_EVENT,
        'status' => 'aktif',
    ]);

    app(PengawasService::class)->tugaskanOtomatis($event, $this->admin->id, null, 'cepat');

    $tugasReguler = SupervisionAssignment::query()
        ->where('exam_session_room_id', $sesiRuangReguler->id)
        ->pluck('supervisor_id')
        ->all();

    $tugasLab = SupervisionAssignment::query()
        ->where('exam_session_room_id', $sesiRuangLab->id)
        ->pluck('supervisor_id')
        ->all();

    expect($tugasReguler)->toHaveCount(1)
        ->and($tugasReguler[0])->not->toBe($panitiaTeknisi->id)
        ->and($tugasLab)->toBe([$panitiaTeknisi->id]);

    // Mode expert juga harus melewati ruang khusus perangkat untuk pengawas reguler dan menunjuk panitia.
    SupervisionAssignment::query()->delete();
    app(PengawasService::class)->tugaskanOtomatis($event, $this->admin->id, null, 'expert');

    $tugasRegulerExpert = SupervisionAssignment::query()
        ->where('exam_session_room_id', $sesiRuangReguler->id)
        ->pluck('supervisor_id')
        ->all();

    expect($tugasRegulerExpert)->toHaveCount(1)
        ->and($tugasRegulerExpert[0])->not->toBe($panitiaTeknisi->id)
        ->and(SupervisionAssignment::query()->where('exam_session_room_id', $sesiRuangLab->id)->pluck('supervisor_id')->all())
        ->toBe([$panitiaTeknisi->id]);
});

it('dapat menugaskan panitia event tanpa akun ke ruang khusus perangkat', function () {
    $data = siapkanPresensi(
        $this->sekolah,
        $this->tahun,
        $this->semester,
        $this->admin,
        ['LAB-01'],
        2,
    );

    $event = $data['event'];
    $sesiRuangLab = $data['ruang'][0];

    $sesiRuangLab->eventRoom->room->forceFill([
        'khusus_perangkat' => true,
    ])->save();

    EventCommitteeMember::query()->where('event_id', $event->id)->delete();
    SupervisionAssignment::query()->delete();

    $panitiaTanpaAkun = EventCommitteeMember::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $event->id,
        'user_id' => null,
        'name' => 'Hendra Teknisi',
        'position' => 'Teknisi',
        'scope' => EventCommitteeMember::SCOPE_EVENT,
        'status' => 'aktif',
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/events/{$event->id}/pengawas/ruang/{$sesiRuangLab->id}", [
            'supervisor_id' => -$panitiaTanpaAkun->id,
        ])
        ->assertSessionHasNoErrors();

    $panitiaTanpaAkun->refresh();

    expect($panitiaTanpaAkun->user_id)->not->toBeNull()
        ->and($panitiaTanpaAkun->code)->not->toBeNull()
        ->and(strlen((string) $panitiaTanpaAkun->code))->toBe(6)
        ->and(app(PencocokKodePengawas::class)->cocokkan($sesiRuangLab, (string) $panitiaTanpaAkun->code)?->id)
        ->toBe($panitiaTanpaAkun->user_id)
        ->and(SupervisionAssignment::query()
            ->where('exam_session_room_id', $sesiRuangLab->id)
            ->pluck('supervisor_id')
            ->all())
        ->toBe([$panitiaTanpaAkun->user_id]);
});
