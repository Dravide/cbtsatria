<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Semester;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Services\NomorKursi;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use App\Support\PembatasLaju;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 2']);
    $this->sekolah->forceFill(['papan_slug' => 'smkn2-ruang-cd34'])->save();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.ruang',
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

it('mengembalikan 404 bila slug tidak sah', function () {
    $this->get('/ruang/tidak-ada')->assertNotFound();
    $this->post('/ruang/tidak-ada', ['kelas_id' => 1, 'nis' => '1001'])->assertNotFound();
});

it('menampilkan ruang dan nomor kursi siswa yang cocok tanpa membocorkan siswa lain', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $data['event']->forceFill(['status' => EventStatus::Ongoing])->save();

    $kelas = SchoolClass::query()
        ->withoutGlobalScopes()
        ->where('school_id', $this->sekolah->id)
        ->firstOrFail();

    $siswaA = $data['siswa'][0];
    $siswaB = $data['siswa'][1];

    StudentProfile::query()->withoutGlobalScopes()->updateOrCreate(
        ['school_id' => $this->sekolah->id, 'user_id' => $siswaA->id],
        ['nis' => '20260001'],
    );
    StudentProfile::query()->withoutGlobalScopes()->updateOrCreate(
        ['school_id' => $this->sekolah->id, 'user_id' => $siswaB->id],
        ['nis' => '20260002'],
    );

    $pesertaA = ExamParticipant::query()
        ->withoutGlobalScopes()
        ->where('school_id', $this->sekolah->id)
        ->where('student_id', $siswaA->id)
        ->firstOrFail();
    $kursiHarapan = app(NomorKursi::class)->satu($pesertaA);

    Auth::logout();
    app(SchoolResolver::class)->forget();

    // GET awal tidak mengirim data siswa mana pun
    $getProps = $this->get('/ruang/'.$this->sekolah->papan_slug)
        ->assertOk()
        ->viewData('page')['props'];

    expect($getProps['hasil'])->toBeNull()
        ->and(json_encode($getProps))->not->toContain($siswaA->name)
        ->and(json_encode($getProps))->not->toContain($siswaB->name);

    // POST dengan kelas + NIS yang cocok mengembalikan ruang & kursi siswa A saja
    $postProps = $this->post('/ruang/'.$this->sekolah->papan_slug, [
        'kelas_id' => $kelas->id,
        'nis' => '20260001',
    ])
        ->assertOk()
        ->viewData('page')['props'];

    expect($postProps['hasil'])->not->toBeNull()
        ->and($postProps['hasil']['nama'])->toBe($siswaA->name)
        ->and($postProps['hasil']['events'])->toHaveCount(1)
        ->and($postProps['hasil']['events'][0]['ruang']['kode'])->toBe($data['ruang'][0]->eventRoom->room->code)
        ->and($postProps['hasil']['events'][0]['kursi'])->toBe($kursiHarapan)
        ->and(json_encode($postProps))->not->toContain($siswaB->name);
});

it('menolak pasangan kelas dan NIS yang tidak cocok', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $data['event']->forceFill(['status' => EventStatus::Ongoing])->save();

    $kelas = SchoolClass::query()
        ->withoutGlobalScopes()
        ->where('school_id', $this->sekolah->id)
        ->firstOrFail();

    Auth::logout();
    app(SchoolResolver::class)->forget();

    $this->from('/ruang/'.$this->sekolah->papan_slug)
        ->post('/ruang/'.$this->sekolah->papan_slug, [
            'kelas_id' => $kelas->id,
            'nis' => '99999999',
        ])
        ->assertRedirect('/ruang/'.$this->sekolah->papan_slug)
        ->assertSessionHasErrors('nis');
});

it('membatasi laju pencarian berulang pada POST /ruang/{slug}', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $kelas = SchoolClass::query()
        ->withoutGlobalScopes()
        ->where('school_id', $this->sekolah->id)
        ->firstOrFail();

    Auth::logout();
    app(SchoolResolver::class)->forget();

    for ($i = 0; $i < PembatasLaju::RUANG_PER_IP; $i++) {
        $this->post('/ruang/'.$this->sekolah->papan_slug, [
            'kelas_id' => $kelas->id,
            'nis' => '00000000',
        ]);
    }

    $this->post('/ruang/'.$this->sekolah->papan_slug, [
        'kelas_id' => $kelas->id,
        'nis' => '00000000',
    ])
        ->assertRedirect('/ruang/'.$this->sekolah->papan_slug)
        ->assertSessionHas('galat', PembatasLaju::PESAN_RUANG);
});
