<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\Subject;
use App\Domain\Event\Models\EventClass;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create();
    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();
    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'Ganjil',
    ]);

    $this->event = SchoolEvent::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'semester_id' => $this->semester->id,
        'created_by' => $this->admin->id,
    ]);

    $this->kelas = SchoolClass::factory()->untukTahun($this->tahun)->create();
    EventClass::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $this->event->id,
        'class_id' => $this->kelas->id,
    ]);

    $room = Room::factory()->untukSekolah($this->sekolah)->create(['code' => 'R-01']);
    $this->ruangEvent = EventRoom::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $this->event->id,
        'room_id' => $room->id,
        'capacity' => 30,
    ]);

    $this->mapelA = Subject::factory()->untukSekolah($this->sekolah)->create(['name' => 'Matematika']);
    $this->mapelB = Subject::factory()->untukSekolah($this->sekolah)->create(['name' => 'Bahasa Indonesia']);
});

function masukJadwalSesi($test, User $user)
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

it('menampilkan laman sesi khusus satu ujian dan menyaring sesinya', function () {
    $ujianA = app(ExamRegistrar::class)->buat($this->event, [
        'subject_id' => $this->mapelA->id,
        'title' => 'ASAS Matematika',
        'duration_minutes' => 90,
        'class_id' => [],
    ], $this->admin->id);

    $ujianB = app(ExamRegistrar::class)->buat($this->event, [
        'subject_id' => $this->mapelB->id,
        'title' => 'ASAS Bahasa Indonesia',
        'duration_minutes' => 90,
        'class_id' => [],
    ], $this->admin->id);

    app(SesiService::class)->buat($ujianA, [
        'access_opens_at' => '2026-12-01 08:00:00',
        'access_closes_at' => '2026-12-01 08:30:00',
        'hard_end_at' => '2026-12-01 09:30:00',
        'event_room_id' => [$this->ruangEvent->id],
    ]);

    app(SesiService::class)->buat($ujianB, [
        'access_opens_at' => '2026-12-02 08:00:00',
        'access_closes_at' => '2026-12-02 08:30:00',
        'hard_end_at' => '2026-12-02 09:30:00',
        'event_room_id' => [$this->ruangEvent->id],
    ]);

    masukJadwalSesi($this, $this->admin)
        ->get("/admin/events/{$this->event->id}/jadwal/{$ujianA->id}/sesi")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Sesi')
            ->where('ujian_aktif.id', $ujianA->id)
            ->has('baris', 1)
            ->where('baris.0.ujian_id', $ujianA->id)
        );
});

it('mengembalikan 404 bila ujian bukan milik event tersebut', function () {
    $eventLain = SchoolEvent::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'semester_id' => $this->semester->id,
        'created_by' => $this->admin->id,
    ]);

    $ujianLain = app(ExamRegistrar::class)->buat($eventLain, [
        'subject_id' => $this->mapelA->id,
        'title' => 'Ujian Event Lain',
        'duration_minutes' => 60,
        'class_id' => [],
    ], $this->admin->id);

    masukJadwalSesi($this, $this->admin)
        ->get("/admin/events/{$this->event->id}/jadwal/{$ujianLain->id}/sesi")
        ->assertNotFound();
});
