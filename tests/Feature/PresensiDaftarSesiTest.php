<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Inertia\Testing\AssertableInertia;

/**
 * Laman presensi Admin adalah DAFTAR SESI, dan rinciannya laman sesi.
 *
 * =====================================================================
 * Yang dijaga: SESI, bukan ruang
 * =====================================================================
 * Sampai 2026-11-17 `/presensi` memuat satu kartu per RUANG. Dua hal salah
 * sekaligus: kartunya mencampur sesi (R-01 dipakai pagi dan siang), dan
 * pertanyaan pertama Admin - "sesi mana yang belum tuntas" - hanya bisa
 * dijawab dengan menjumlahkan tiga puluh tiga kartu sendiri.
 *
 * Test di sini memakai event DUA sesi yang berbagi satu ruang, karena itu
 * satu-satunya bentuk yang membedakan "satu baris per sesi" dari "satu baris
 * per ruang". Fixture dengan satu sesi akan hijau pada kedua bentuk.
 *
 * =====================================================================
 * `pending` adalah "belum dipresensi", bukan "tidak hadir"
 * =====================================================================
 * Peserta yang belum punya baris presensi masuk `pending`. Menghitungnya
 * sebagai tidak hadir membuat sesi yang belum dimulai terbaca sebagai sesi
 * yang seluruh siswanya alpa - dan Admin akan mengejar pengawas yang belum
 * perlu dikejar.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.presensi.sesi',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);
});

/** Ujian kedua di event yang sama, dengan sesinya sendiri. */
function sesiKedua(SchoolEvent $event, User $admin): ExamSession
{
    $ujian = app(ExamRegistrar::class)->buat($event, [
        'subject_id' => $event->exams()->firstOrFail()->subject_id,
        'title' => 'Ujian Sesi Kedua',
        'exam_type' => 'Ujian akhir semester',
        'duration_minutes' => 90,
        'allow_late_entry' => true,
        'class_id' => [],
    ], $admin->id);

    $ruangEvent = EventRoom::query()
        ->where('event_id', $event->id)
        ->pluck('id')
        ->all();

    return app(SesiService::class)->buat($ujian, [
        'session_number' => 2,
        'access_opens_at' => now()->addHours(2),
        'access_closes_at' => now()->addHours(4),
        'hard_end_at' => now()->addHours(4),
        'duration_minutes' => 90,
        'event_room_id' => $ruangEvent,
    ]);
}

it('menampilkan DAFTAR SESI, bukan daftar ruang', function () {
    $d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01', 'R-02'], 4);

    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$d['event']->id}/presensi")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/PresensiDaftarSesi')
            // Satu sesi pada fixture ini - dan SATU baris, bukan dua
            // (jumlah ruangnya dua).
            ->has('baris', 1)
            ->where('baris.0.label', 'S-1')
            ->where('baris.0.ruang', 2)
            ->where('angka.sesi', 1)
            ->has('jalur_sesi')
            ->has('jalur_ruang')
        );
});

it('membuka laman sesi dengan ruang dan rekap sesi ITU saja', function () {
    $semester = $this->semester;

    $d = siapkanPresensi($this->sekolah, $this->tahun, $semester, $this->admin, ['R-01', 'R-02'], 4);

    $sesiKedua = sesiKedua($d['event'], $this->admin);

    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$d['event']->id}/presensi/sesi/{$d['sesi']->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Presensi')
            ->where('sesi.label', 'S-1')
            ->where('sesi.id', $d['sesi']->id)
            ->has('baris', 2)
            ->where('baris.0.sesi_id', $d['sesi']->id)
            // Kepala sesi membawa jamnya: itulah yang menjelaskan mengapa
            // presensinya belum terisi.
            ->where('sesi.jam', fn ($jam) => is_string($jam) && $jam !== '')
            ->has('jalur_daftar_sesi')
        );

    // Sesi kedua punya lamannya sendiri, dan laman itu TIDAK memuat ruang
    // sesi pertama: angka "2 ruang" di sesi 2 harus dua, bukan empat.
    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$d['event']->id}/presensi/sesi/{$sesiKedua->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Presensi')
            ->where('sesi.label', 'S-2')
            ->has('baris', 2)
            ->where('baris.0.sesi_id', $sesiKedua->id)
        );
});

it('mengirim kepala sesi tetap kosong di papan seluruh ruang', function () {
    $d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01', 'R-02'], 4);

    // Papan seluruh ruang harus tetap menjawab pertanyaan "R-07 hari ini
    // bagaimana" - dan `sesi` null adalah yang membedakan kepalanya.
    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$d['event']->id}/presensi/ruang")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Presensi')
            ->where('sesi', null)
        );
});

it('menghitung peserta tanpa baris presensi sebagai belum, bukan mengecilkan total', function () {
    $d = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 4);

    // Semua baris presensi dibuang: bentuk yang sama dengan sesi yang belum
    // dibuka pengawasnya.
    AttendanceRecord::query()->delete();

    $props = masukSebagai($this, $this->admin)
        ->get("/admin/events/{$d['event']->id}/presensi")
        ->viewData('page')['props'];

    $baris = collect($props['baris'])->firstWhere('id', $d['sesi']->id);

    expect($baris)->not->toBeNull()
        ->and($baris['rekap']['total'])->toBe(4)
        ->and($baris['rekap']['pending'])->toBe(4)
        ->and($baris['rekap']['tidak_hadir'])->toBe(0);
});

it('menolak membuka laman sesi milik event lain', function () {
    $semester = $this->semester;

    $d = siapkanPresensi($this->sekolah, $this->tahun, $semester, $this->admin, ['R-01'], 2);
    $lain = siapkanPresensi($this->sekolah, $this->tahun, $semester, $this->admin, ['R-01'], 2);

    // Binding implisit menemukan sesinya - sekolahnya sama, sehingga scope
    // tenant tidak menutupnya. 404, bukan 403: yang 403 membuktikan sesinya
    // ada di tempat lain.
    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$d['event']->id}/presensi/sesi/{$lain['sesi']->id}")
        ->assertNotFound();
});

it('tidak memuat baris presensi peserta dari event lain', function () {
    $semester = $this->semester;

    $d = siapkanPresensi($this->sekolah, $this->tahun, $semester, $this->admin, ['R-01'], 2);
    $lain = siapkanPresensi($this->sekolah, $this->tahun, $semester, $this->admin, ['R-01'], 2);

    $idPeserta = ExamParticipant::query()
        ->whereIn('exam_id', $lain['event']->exams()->pluck('id'))
        ->pluck('id');

    $props = masukSebagai($this, $this->admin)
        ->get("/admin/events/{$d['event']->id}/presensi")
        ->viewData('page')['props'];

    $idBaris = collect($props['baris'])
        ->flatMap(fn (array $b) => [$b['id']])
        ->all();

    expect($idBaris)->toContain($d['sesi']->id)
        ->and($idBaris)->not->toContain($lain['sesi']->id)
        ->and($idPeserta->isNotEmpty())->toBeTrue();
});
