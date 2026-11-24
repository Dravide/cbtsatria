<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Facility\Models\RoomEntryLink;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->sekolah = School::factory()->create();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.darurat',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);

    $this->d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin);

    // Ruang 0 = ruang kelas BYOD (tanpa PC), Ruang 1 = Lab Komputer dengan 10 PC.
    $this->d['ruang'][0]->eventRoom->room->forceFill(['device_count' => 0])->save();
    $this->d['ruang'][1]->eventRoom->room->forceFill([
        'capacity' => 10,
        'device_count' => 10,
    ])->save();
    $this->d['ruang'][1]->eventRoom->forceFill(['capacity_override' => 10])->save();

    $this->tautanLab = RoomEntryLink::query()->create([
        'school_id' => $this->sekolah->id,
        'room_id' => $this->d['ruang'][1]->eventRoom->room_id,
        'slug' => 'lab-darurat-test',
        'expires_at' => now()->addDays(7),
    ]);
});

it('pengawas dapat memindahkan peserta darurat ke PC lab khusus sesi ini dan langsung bisa masuk via kios PC tersebut', function () {
    $ruangAsal = $this->d['ruang'][0];
    $ruangLab = $this->d['ruang'][1];
    $pengawas = $ruangAsal->supervisors()->with('supervisor')->firstOrFail()->supervisor;
    $peserta = $ruangAsal->participants()->orderBy('id')->firstOrFail();
    $siswa = User::query()->findOrFail($peserta->student_id);

    // Pengawas membuka detail presensi siswa: melihat ruang tujuan & PC kosong.
    masukSebagai($this, $pengawas)
        ->get("/pengawas/ruang/{$ruangAsal->id}/presensi/{$peserta->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Pengawas/PresensiSatu')
            ->has('ruang_tujuan', 1)
            ->where('ruang_tujuan.0.id', $ruangLab->id)
            ->where('ruang_tujuan.0.pc', 10)
        );

    // Pindahkan ke Lab Kom pada PC 9 (slot kosong).
    masukSebagai($this, $pengawas)
        ->post("/pengawas/ruang/{$ruangAsal->id}/presensi/{$peserta->id}/pindah-ruang", [
            'ruang_tujuan_id' => $ruangLab->id,
            'mode' => 'pc',
            'nomor_pc' => 9,
            'alasan' => 'HP siswa rusak layar tidak menyala',
        ])
        ->assertRedirect("/pengawas/ruang/{$ruangAsal->id}/presensi")
        ->assertSessionHas('sukses');

    $peserta->refresh();

    expect((int) $peserta->exam_session_room_id)->toBe($ruangLab->id)
        ->and($peserta->emergency_device_mode)->toBe('pc')
        ->and($peserta->emergency_seat_number)->toBe(9)
        ->and((int) $peserta->emergency_origin_room_id)->toBe($ruangAsal->id);

    // Siswa langsung bisa login lewat kios /lab/{slug}/9 dan tercatat hadir.
    masukSebagai($this, $siswa)
        ->post("/lab/{$this->tautanLab->slug}/9")
        ->assertRedirect("/siswa/ujian/{$peserta->id}/gerbang");

    expect(
        AttendanceRecord::query()->where('exam_participant_id', $peserta->id)->firstOrFail()->attendance_status->value,
    )->toBe('hadir');
});

it('admin dapat memindahkan peserta darurat dengan opsi HP dan manajemen kios /admin/ruang/{ruang} menampilkan pemetaan PC', function () {
    $ruangAsal = $this->d['ruang'][0];
    $ruangLab = $this->d['ruang'][1];
    $peserta = $ruangAsal->participants()->orderBy('id')->firstOrFail();

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$this->d['event']->id}/monitoring/peserta/{$peserta->id}/pindah-ruang", [
            'ruang_tujuan_id' => $ruangLab->id,
            'mode' => 'hp',
            'alasan' => 'Pindah ruang karena kipas rusak, tetap pakai HP',
        ])
        ->assertRedirect()
        ->assertSessionHas('sukses');

    $peserta->refresh();

    expect((int) $peserta->exam_session_room_id)->toBe($ruangLab->id)
        ->and($peserta->emergency_device_mode)->toBe('hp')
        ->and($peserta->emergency_seat_number)->toBeNull();

    // Halaman detail ruang /admin/ruang/{id} menampilkan manajemen kios PC 1..10 & daftar peserta HP.
    $roomLabId = $ruangLab->eventRoom->room_id;

    masukSebagai($this, $this->admin)
        ->get("/admin/ruang/{$roomLabId}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/RuangDetail')
            ->has('kios.slot', 10)
            ->has('kios.peserta_hp', 1)
            ->where('kios.peserta_hp.0.peserta_id', $peserta->id)
        );
});
