<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\Subject;
use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use App\Domain\Tenancy\Models\School;

beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create();
    $this->guru = User::factory()->guru()->untukSekolah($this->sekolah)->create();
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

    $this->mapel = Subject::factory()->untukSekolah($this->sekolah)->create(['name' => 'Matematika']);

    $paket = QuestionPackage::factory()->oleh($this->guru, $this->mapel)->siap()->create();
    QuestionPackageItem::factory()->create([
        'school_id' => $this->sekolah->id,
        'question_package_id' => $paket->id,
        'question_id' => Question::factory()->oleh($this->guru, $this->mapel)->create()->id,
    ]);

    EventSubject::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $this->event->id,
        'subject_id' => $this->mapel->id,
        'question_package_id' => $paket->id,
    ]);

    $room = Room::factory()->untukSekolah($this->sekolah)->create(['code' => 'R-01']);
    $this->ruangEvent = EventRoom::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $this->event->id,
        'room_id' => $room->id,
        'capacity' => 30,
    ]);

    $this->ujian = app(ExamRegistrar::class)->buat($this->event, [
        'subject_id' => $this->mapel->id,
        'title' => 'ASAS Matematika',
        'duration_minutes' => 90,
        'class_id' => [],
    ], $this->admin->id);
});

it('menerbitkan token otomatis saat sesi disiapkan bila pengawas sudah lengkap', function () {
    $sesi = app(SesiService::class)->buat($this->ujian, [
        'access_opens_at' => '2026-12-01 08:00:00',
        'access_closes_at' => '2026-12-01 08:30:00',
        'hard_end_at' => '2026-12-01 09:30:00',
        'event_room_id' => [$this->ruangEvent->id],
    ]);

    $ruangSesi = ExamSessionRoom::query()->where('exam_session_id', $sesi->id)->firstOrFail();
    $panitia = EventCommitteeMember::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $this->event->id,
        'user_id' => $this->guru->id,
        'name' => $this->guru->name,
        'position' => 'pengawas',
    ]);

    SupervisionAssignment::create([
        'school_id' => $this->sekolah->id,
        'exam_session_room_id' => $ruangSesi->id,
        'supervisor_id' => $this->guru->id,
        'assigned_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin);
    $sesi = app(SesiService::class)->siapkan($sesi);

    expect($sesi->status)->toBe(StatusSesi::Siap)
        ->and($sesi->tokenAktif())->not->toBeNull();
});

it('tetap menyiapkan sesi tanpa galat bila pengawas belum ditugaskan', function () {
    $sesi = app(SesiService::class)->buat($this->ujian, [
        'access_opens_at' => '2026-12-01 08:00:00',
        'access_closes_at' => '2026-12-01 08:30:00',
        'hard_end_at' => '2026-12-01 09:30:00',
        'event_room_id' => [$this->ruangEvent->id],
    ]);

    $sesi = app(SesiService::class)->siapkan($sesi);

    expect($sesi->status)->toBe(StatusSesi::Siap)
        ->and($sesi->tokenAktif())->toBeNull();
});
