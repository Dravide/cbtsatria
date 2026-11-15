<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Services\EventRegistrar;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamToken;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Testing\TestResponse;

/**
 * Peringatan hari-H di beranda Admin.
 *
 * =====================================================================
 * Yang paling penting dibuktikan bukan "peringatan muncul"
 * =====================================================================
 * Panel yang berbunyi untuk SEGALA keadaan sama tidak bergunanya dengan
 * panel yang tidak pernah muncul: keduanya berhenti dibaca, dan yang
 * dilewati adalah kotak yang sama. Karena itu dua dari tes di sini
 * membuktikan panelnya DIAM - tanpa event aktif, dan untuk event yang
 * masih jauh dari hari-H.
 *
 * Dipanggil lewat HTTP, bukan langsung ke `PeringatanHariH`, karena yang
 * diuji adalah apa yang benar-benar dibaca Admin - termasuk penyaringan
 * event aktif di sesi.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.peringatan',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2027/2028',
    ]);

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'Ganjil',
    ]);
});

/** Event tanpa sesi, pada status dan rentang tanggal tertentu. */
function eventPeringatan(object $test, EventStatus $status, array $atribut = []): object
{
    return app(EventRegistrar::class)->buat(array_merge([
        'academic_year_id' => $test->tahun->id,
        'semester_id' => $test->semester->id,
        'name' => 'UAS Ganjil',
        'event_type' => 'Ujian akhir semester',
        'start_at' => now()->subHour()->toDateTimeString(),
        'end_at' => now()->addDays(3)->toDateTimeString(),
    ], $atribut), $test->sekolah->id, $test->admin->id, []);
}

/**
 * Beranda Admin dengan `event_aktif` disetel ke event ini.
 *
 * `masukSebagai()`, bukan `actingAs()` langsung: `siapkanPresensi()`
 * memanggil service (EventRegistrar, SesiService, ...) SEBELUM ada pengguna,
 * dan service itu membaca SchoolScope - sehingga resolver tenant menyimpan
 * `null` sebagai jawaban finalnya. Permintaan HTTP sesudahnya memakai
 * instance yang sama dan scope-nya menjadi no-op. Helper itu yang
 * melupakannya; lihat catatan panjang di `tests/Support/UjianHelpers.php`.
 */
function berandaPeringatan(object $test, int $eventId): TestResponse
{
    masukSebagai($test, $test->admin);

    return $test->withSession(['event_aktif' => $eventId])->get('/admin');
}

/** Props beranda Admin, apa adanya. */
function propsPeringatan(object $test, int $eventId): array
{
    $respon = berandaPeringatan($test, $eventId);

    $respon->assertOk();

    return $respon->viewData('page')['props'];
}

/* ================================================================ Diam */

it('tidak menampilkan peringatan tanpa event aktif di sesi', function () {
    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('peringatan', []));
});

it('tidak menampilkan peringatan untuk event yang sudah selesai', function () {
    $event = eventPeringatan($this, EventStatus::Completed);

    $this->actingAs($this->admin)
        ->withSession(['event_aktif' => $event->id])
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('peringatan', []));
});

it('tidak menampilkan peringatan waktu untuk event yang masih jauh', function () {
    // Dua minggu lagi, tanpa sesi. Tidak ada yang perlu dikerjakan HARI INI,
    // dan panel yang berbunyi sekarang akan berhenti dibaca saat ujiannya
    // benar-benar dekat.
    $event = eventPeringatan($this, EventStatus::Preparation, [
        'start_at' => now()->addDays(14)->toDateTimeString(),
        'end_at' => now()->addDays(16)->toDateTimeString(),
    ]);

    $this->actingAs($this->admin)
        ->withSession(['event_aktif' => $event->id])
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('peringatan', []));
});

/* ============================================================= Berbunyi */

it('menyebut sesi yang jendelanya sudah lewat tetapi belum dibuka', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    // Sesi `Siap` yang jendelanya sudah lewat: keadaan paling menentukan di
    // hari-H, dan satu-satunya yang TIDAK ditangkap `EventReadiness` -
    // dari sisi data sesinya lengkap, yang kurang hanya Admin menekan Buka.
    $sesi = $data['sesi'];

    ExamSession::query()->where('id', $sesi->id)->update([
        'status' => StatusSesi::Siap->value,
        'access_opens_at' => now()->subMinutes(20),
        'access_closes_at' => now()->addMinutes(40),
        'hard_end_at' => now()->addHours(2),
    ]);

    $event = $data['event'];

    // Event harus dianggap sudah dekat. Statusnya dijadikan Berjalan lewat
    // kolomnya langsung: yang diuji peringatannya, bukan transisi event.
    $event->forceFill(['status' => EventStatus::Ongoing->value])->save();

    $props = propsPeringatan($this, $event->id);

    expect(array_column($props['peringatan'], 'kunci'))
        ->toContain('sesi-belum-dibuka-'.$sesi->id);
});

it('menyebut sesi tanpa token dan menautkannya ke laman token event', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $event = $data['event'];

    // Token yang terbit dari helper dicabut, supaya sesinya benar-benar
    // tanpa token berlaku.
    ExamToken::query()->update(['revoked_at' => now()]);

    $event->forceFill(['status' => EventStatus::Ongoing->value])->save();

    $props = propsPeringatan($this, $event->id);

    $token = collect($props['peringatan'])->firstWhere('kunci', 'token-belum-terbit');

    expect($token)->not->toBeNull();

    // Tautannya menuju laman token event INI - peringatan yang tidak bisa
    // diperbaiki dari tempatnya sendiri hanya memindahkan pekerjaan.
    expect($token['href'])->toBe("/admin/events/{$event->id}/token");
});

it('menyebut sesi yang masih Draft padahal dibuka kurang dari dua jam lagi', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $sesi = $data['sesi'];

    ExamSession::query()->where('id', $sesi->id)->update([
        'status' => StatusSesi::Draft->value,
        'access_opens_at' => now()->addMinutes(90),
        'access_closes_at' => now()->addHours(3),
        'hard_end_at' => now()->addHours(4),
    ]);

    $event = $data['event'];
    $event->forceFill(['status' => EventStatus::Preparation->value])->save();

    $props = propsPeringatan($this, $event->id);

    expect(array_column($props['peringatan'], 'kunci'))->toContain('sesi-masih-draft');
});
