<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Enums\StatusPaket;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Services\PaketSoalService;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

function masukSebagaiGuru(TestCase $test, User $guru, School $sekolah): void
{
    app(SchoolResolver::class)->forget();
    $test->actingAs($guru)->withSession(['school_id' => $sekolah->id]);
}

beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();
    $this->mapel = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'MAT',
        'name' => 'Matematika',
    ]);
    $this->kelas = SchoolClass::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'VII-A',
    ]);
    $this->guru = User::factory()->guru()->untukSekolah($this->sekolah)->create();

    TeachingAssignment::create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'teacher_id' => $this->guru->id,
        'subject_id' => $this->mapel->id,
        'class_id' => $this->kelas->id,
    ]);
});

it('menampilkan perlu_koreksi dan daftar ampuan pada dashboard guru', function () {
    masukSebagaiGuru($this, $this->guru, $this->sekolah);

    $this->get('/guru')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guru/Dashboard')
            ->where('ringkasan.perlu_koreksi', 0)
            ->has('ampuan', 1)
            ->where('ampuan.0.mapel', 'Matematika')
            ->where('ampuan.0.kelas.0', 'VII-A'));
});

it('mengirim kisi paket dan mengunduh PDF naskah soal serta kunci', function () {
    $soal = Question::factory()->untukSekolah($this->sekolah)->oleh($this->guru, $this->mapel)->create([
        'question_type' => JenisSoal::PilihanGanda,
        'difficulty' => 'sedang',
        'status' => 'draft',
    ]);

    $paket = QuestionPackage::create([
        'school_id' => $this->sekolah->id,
        'subject_id' => $this->mapel->id,
        'created_by' => $this->guru->id,
        'code' => 'PKT-01',
        'name' => 'Paket UTS Matematika',
        'status' => StatusPaket::Draft,
    ]);

    app(PaketSoalService::class)->tambahButir($paket, [$soal->id]);

    masukSebagaiGuru($this, $this->guru, $this->sekolah);

    $this->get('/guru/paket/'.$paket->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guru/Paket/Detail')
            ->where('kisi.tingkat.sedang', 1)
            ->has('kisi.peringatan', 1));

    $this->get('/guru/paket/'.$paket->id.'/naskah?mode=soal')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->get('/guru/paket/'.$paket->id.'/naskah?mode=kunci')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('menjalankan aksi massal bank soal untuk masuk paket dan arsip', function () {
    $soalA = Question::factory()->untukSekolah($this->sekolah)->oleh($this->guru, $this->mapel)->create([
        'status' => 'aktif',
    ]);
    $soalB = Question::factory()->untukSekolah($this->sekolah)->oleh($this->guru, $this->mapel)->create([
        'status' => 'aktif',
    ]);

    $paket = QuestionPackage::create([
        'school_id' => $this->sekolah->id,
        'subject_id' => $this->mapel->id,
        'created_by' => $this->guru->id,
        'code' => 'PKT-MASS',
        'name' => 'Paket Massal',
        'status' => StatusPaket::Draft,
    ]);

    masukSebagaiGuru($this, $this->guru, $this->sekolah);

    $this->post('/guru/bank-soal/massal', [
        'aksi' => 'masuk_paket',
        'ids' => [$soalA->id, $soalB->id],
        'paket_id' => $paket->id,
    ])->assertRedirect();

    expect($paket->butir()->count())->toBe(2);

    $this->post('/guru/bank-soal/massal', [
        'aksi' => 'arsip',
        'ids' => [$soalA->id, $soalB->id],
    ])->assertRedirect();

    expect($soalA->fresh()->status)->toBe('arsip');
    expect($soalB->fresh()->status)->toBe('arsip');
});

it('mengunduh laporan PDF analisis butir untuk ujian yang diampu', function () {
    $event = SchoolEvent::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);
    $ujian = Exam::factory()->create([
        'school_id' => $this->sekolah->id,
        'event_id' => $event->id,
        'subject_id' => $this->mapel->id,
    ]);
    ExamQuestion::factory()->untukUjian($ujian)->create([
        'position' => 1,
    ]);

    masukSebagaiGuru($this, $this->guru, $this->sekolah);

    $this->get('/guru/analisis/'.$ujian->id.'/unduh')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
