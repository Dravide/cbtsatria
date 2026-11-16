<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\ClassEnrollment;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\Subject;
use App\Domain\Event\Models\EventClass;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Services\EventParticipantService;
use App\Domain\Event\Services\EventRegistrar;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamClass;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * `ujian:gabung-tingkat` - satu mapel lintas tingkat menjadi satu ujian.
 *
 * =====================================================================
 * Masalah yang diselesaikan
 * =====================================================================
 * Sekolah menyusun satu ujian per tingkat: "Pendidikan Agama #7" dan "#8",
 * masing-masing membawa 33 ruang. Laman pengawas lalu menampilkan 33 baris
 * per tingkat - tiga kali lebih banyak daripada yang diperlukan, karena ruang
 * milik EVENT, bukan milik tingkat.
 *
 * Yang dijaga berkas ini: penggabungan MEMINDAHKAN struktur dengan benar,
 * dan MENOLAK bila ada hasil pekerjaan siswa yang bisa hilang.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();
    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.gabung',
    ]);
    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);

    $this->event = app(EventRegistrar::class)->buat([
        'academic_year_id' => $this->tahun->id,
        'semester_id' => $this->semester->id,
        'name' => 'Asesmen Harian',
        'event_type' => 'Asesmen',
    ], $this->sekolah->id, $this->admin->id, []);
});

/**
 * Dua ujian satu MAPEL, masing-masing untuk satu tingkat - bentuk yang
 * dihasilkan sekolah yang menyusun mapel per tingkat.
 *
 * @return array{ujian: array<int, Exam>, mapel: array<int, Subject>}
 */
function duaUjianSatuMapel(SchoolEvent $event, User $admin, AcademicYear $tahun): array
{
    $ujian = [];
    $mapel = [];

    foreach (['7' => 'PABK-7', '8' => 'PABK-8'] as $tingkat => $kode) {
        $kelas = SchoolClass::factory()->create([
            'school_id' => $event->school_id,
            'academic_year_id' => $tahun->id,
            'name' => "{$tingkat}A-{$event->id}",
            'grade_level' => $tingkat,
        ]);

        EventClass::create([
            'school_id' => $event->school_id,
            'event_id' => $event->id,
            'class_id' => $kelas->id,
        ]);

        $s = Subject::factory()->create([
            'school_id' => $event->school_id,
            'code' => $kode,
            'name' => 'Pendidikan Agama dan Budi Pekerti',
            'grade_level' => $tingkat,
        ]);

        EventSubject::create([
            'school_id' => $event->school_id,
            'event_id' => $event->id,
            'subject_id' => $s->id,
        ]);

        // Dua siswa per kelas, supaya ada peserta yang bisa dipindah DAN
        // baris penempatan yang bisa dipakai menguji penolakan attempt.
        foreach (range(1, 2) as $_) {
            $siswa = User::factory()->siswa()->untukSekolah(School::find($event->school_id))->create();

            ClassEnrollment::create([
                'school_id' => $event->school_id,
                'class_id' => $kelas->id,
                'student_id' => $siswa->id,
                'academic_year_id' => $tahun->id,
                'status' => 'aktif',
            ]);
        }

        app(EventParticipantService::class)->tarikDariKelas($event->refresh());

        $u = app(ExamRegistrar::class)->buat($event, [
            'subject_id' => $s->id,
            'title' => "Pendidikan Agama #{$tingkat}",
            'duration_minutes' => 90,
            'class_id' => [$kelas->id],
        ], $admin->id);

        app(SesiService::class)->buat($u, [
            'access_opens_at' => '2026-12-07 14:00:00',
            'access_closes_at' => '2026-12-07 16:00:00',
            'hard_end_at' => '2026-12-07 17:00:00',
            'event_room_id' => [],
        ]);

        $ujian[] = $u;
        $mapel[] = $s;
    }

    // Peserta ditarik SETELAH kedua kelas ada, supaya `event_participants`
    // memuat siswa kedua tingkat dan `tarikPeserta` membuat baris penempatan
    // untuk masing-masing ujian.
    foreach ($ujian as $u) {
        app(PenempatanService::class)->tarikPeserta($u);
    }

    return ['ujian' => $ujian, 'mapel' => $mapel];
}

it('menggabungkan dua ujian satu mapel lintas tingkat menjadi satu', function () {
    // Inti perintah. Dua baris `subjects` bernama SAMA dengan kode berbeda
    // (PABK-7, PABK-8) dianggap satu mapel - dan itulah yang membuat
    // pengelompokan menurut `subject_id` tidak akan pernah menemukan apa pun.
    $d = duaUjianSatuMapel($this->event, $this->admin, $this->tahun);

    [$pertama, $kedua] = $d['ujian'];

    $this->artisan('ujian:gabung-tingkat', ['event' => $this->event->id, '--tulis' => true])
        ->assertExitCode(0);

    // Hanya SATU ujian tersisa pada event ini.
    expect(Exam::query()->where('event_id', $this->event->id)->count())->toBe(1);

    $utama = Exam::query()->where('event_id', $this->event->id)->firstOrFail();

    // Yang dipertahankan adalah yang paling awal dibuat - kodenya mungkin
    // sudah tercetak di jadwal.
    expect($utama->id)->toBe($pertama->id);

    // Kelas sasaran kini menaungi DUA tingkat.
    $tingkat = ExamClass::query()
        ->where('exam_id', $utama->id)
        ->with('kelas:id,grade_level')
        ->get()
        ->map(fn (ExamClass $k) => $k->kelas?->grade_level)
        ->filter()
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($tingkat)->toBe(['7', '8']);

    expect(Exam::query()->whereKey($kedua->id)->exists())->toBeFalse();

    expect($d['mapel'][0]->id)->not->toBe($d['mapel'][1]->id);
});

it('memindahkan sesi dan ruang-sesinya, bukan menghapusnya', function () {
    // Sesi lama sudah membawa RUANG dan PENUGASAN pengawasnya. Membuat sesi
    // baru berarti menyalin ketiganya, dan salinan itu yang akan berbeda
    // diam-diam dari aslinya.
    $d = duaUjianSatuMapel($this->event, $this->admin, $this->tahun);

    [$pertama, $kedua] = $d['ujian'];

    $sesiKedua = ExamSession::query()->where('exam_id', $kedua->id)->pluck('id')->all();

    $this->artisan('ujian:gabung-tingkat', ['event' => $this->event->id, '--tulis' => true])
        ->assertExitCode(0);

    // Sesi itu TETAP ADA, hanya berpindah induk.
    expect(ExamSession::query()->whereIn('id', $sesiKedua)->pluck('exam_id')->unique()->all())
        ->toBe([$pertama->id]);

    // Dan nomornya tidak bertabrakan dengan sesi ujian utama.
    $nomor = ExamSession::query()->where('exam_id', $pertama->id)->pluck('session_number')->all();

    expect($nomor)->toBe(array_values(array_unique($nomor)));
});

it('menolak menggabungkan ujian yang sudah punya pengerjaan siswa', function () {
    // Penggabungan hanya memindahkan STRUKTUR. Attempt dan jawaban menempel
    // pada ujian asalnya lewat snapshot soal, dan dua snapshot tidak bisa
    // diakui sebagai satu - karena itu perintah ini berhenti sebelum menulis.
    $d = duaUjianSatuMapel($this->event, $this->admin, $this->tahun);

    [$pertama, $kedua] = $d['ujian'];

    $peserta = ExamParticipant::query()->where('exam_id', $kedua->id)->firstOrFail();

    DB::table('exam_attempts')->insert([
        'school_id' => $this->sekolah->id,
        'exam_participant_id' => $peserta->id,
        'attempt_number' => 1,
        'started_at' => now(),
        'status' => 'dikirim',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('ujian:gabung-tingkat', ['event' => $this->event->id, '--tulis' => true])
        ->assertExitCode(1);

    // TIDAK ADA yang berubah.
    expect(Exam::query()->where('event_id', $this->event->id)->count())->toBe(2)
        ->and(Exam::query()->whereKey($kedua->id)->exists())->toBeTrue();

    expect($pertama->id)->not->toBe($kedua->id);
});

it('tidak menulis apa pun tanpa --tulis', function () {
    // Perintah yang menghapus baris ujian tanpa memperlihatkan rencananya
    // lebih dulu adalah perintah yang dijalankan orang yang panik pada malam
    // sebelum ujian.
    duaUjianSatuMapel($this->event, $this->admin, $this->tahun);

    $this->artisan('ujian:gabung-tingkat', ['event' => $this->event->id])
        ->assertExitCode(0);

    expect(Exam::query()->where('event_id', $this->event->id)->count())->toBe(2);
});

it('membuang penanda tingkat dari judul hasil gabungan', function () {
    // Judul lama berakhiran "#7" - penanda TINGKAT yang kini tidak berlaku,
    // karena ujian ini menaungi dua tingkat. Mempertahankannya membuat ujian
    // lintas tingkat berjudul "...#7" di laporan dan berita acara.
    duaUjianSatuMapel($this->event, $this->admin, $this->tahun);

    $this->artisan('ujian:gabung-tingkat', ['event' => $this->event->id, '--tulis' => true])
        ->assertExitCode(0);

    $utama = Exam::query()->where('event_id', $this->event->id)->firstOrFail();

    expect($utama->title)->toBe('Pendidikan Agama');
});
