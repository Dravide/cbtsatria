<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Services\PetaKursiEvent;
use App\Domain\Facility\Models\Room;
use App\Domain\Facility\Models\RoomEntryLink;
use App\Domain\Facility\Services\RoomEntryLinkService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Inertia\Testing\AssertableInertia;

/**
 * Kios per-PC ruang lab - `/lab/{slug}/{nomor}`, wireframe-ruang-lab.html K1-K3.
 *
 * =====================================================================
 * Yang dijaga: kursi, dan siapa yang menentukan
 * =====================================================================
 * Nama di URL bisa diketik siapa saja - ia tercetak di meja dan di tablo.
 * Yang menentukan SIAPA adalah akun. Test di bawah memeriksa urutan itu dari
 * dua arah: anak yang mengetik nomor PC temannya ditolak, dan anak yang
 * alamatnya benar tetapi bukan pesertanya juga ditolak.
 *
 * =====================================================================
 * Yang dijaga kedua: presensi TIDAK ditulis dua kali
 * =====================================================================
 * Anak yang sudah memindai QR pintu dari HP-nya berstatus `hadir` sebelum ia
 * menyentuh kios. Menulisnya lagi berarti baris log kedua untuk kejadian
 * yang sama, dan riwayat presensi yang melaporkan dua kejadian membuat
 * pertanyaan "kenapa dua" tidak bisa dijawab.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.kios',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();
    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
    ]);

    // Ruang lab campuran: 4 kursi, 2 di antaranya ber-PC.
    $this->d = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['LAB'], 4,
    );

    $eventRoom = $this->d['ruang'][0]->eventRoom;
    $eventRoom->room->forceFill(['capacity' => 4, 'device_count' => 2])->save();
    $eventRoom->forceFill(['capacity_override' => 4])->save();

    $this->slug = app(RoomEntryLinkService::class)
        ->terbitkan($eventRoom->room, $this->admin->id)
        ->slug;
});

/** Nomor kursi satu peserta di ruangnya. */
function kursiPeserta(ExamParticipant $peserta): int
{
    return app(PetaKursiEvent::class)->kursiSatu($peserta) ?? 0;
}

it('menampilkan layar tunggu tanpa menyebut satu nama pun', function () {
    $this->get("/lab/{$this->slug}/1")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Pengawas/KiosLab')
            ->where('nomor', 1)
            ->where('ruang.kode', fn ($k) => is_string($k) && $k !== '')
            ->where('ruang.kursi_ber_pc', 2)
            ->where('nomor_sah', false)
        );
});

it('TIDAK memuat nama siswa di layar tunggu', function () {
    // Layar ini menghadap ruangan dan difoto orang. Yang boleh tampil hanya
    // identitas perangkat - bukan daftar nama.
    $isi = json_encode(
        $this->get("/lab/{$this->slug}/1")->viewData('page')['props'],
    );

    foreach ($this->d['siswa'] as $siswa) {
        expect($isi)->not->toContain($siswa->name);
    }
});

it('menolak 404 untuk slug tautan yang tidak ada', function () {
    $this->get('/lab/tidak-ada-sama-sekali/1')->assertNotFound();
});

it('menolak 404 untuk tautan yang sudah dicabut', function () {
    RoomEntryLink::query()
        ->where('slug', $this->slug)
        ->update(['revoked_at' => now()]);

    $this->get("/lab/{$this->slug}/1")->assertNotFound();
});

it('menerima anak yang masuk di kursinya sendiri, dan menulis presensi', function () {
    $peserta = $this->d['ruang'][0]->participants()->orderBy('id')->firstOrFail();
    $siswa = User::query()->findOrFail($peserta->student_id);

    $kursi = kursiPeserta($peserta);

    expect($kursi)->toBeGreaterThan(0);

    $record = AttendanceRecord::query()
        ->where('exam_participant_id', $peserta->id)
        ->firstOrFail();

    expect($record->attendance_status->value)->toBe('pending');

    masukSebagai($this, $siswa)
        ->post("/lab/{$this->slug}/{$kursi}")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $record->refresh();

    expect($record->attendance_status->value)->toBe('hadir');

    // Pelakunya PERANGKAT, bukan anak itu: yang mencatat mesin, dan baris
    // log yang menyebut nama anak akan berbohong tentang siapa yang mencatat.
    $log = AttendanceLog::query()
        ->where('attendance_record_id', $record->id)
        ->orderByDesc('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->pelaku_label)->toContain('PC')
        ->and($log->pelaku_label)->not->toContain($siswa->name);
});

it('menolak anak yang masuk di kursi orang lain', function () {
    // Inti fiturnya: tanpa ikatan kursi, dua anak bisa bertukar tempat tanpa
    // ada yang tahu, dan seluruh tablo, denah, serta berita acara salah.
    $peserta = $this->d['ruang'][0]->participants()->orderBy('id')->firstOrFail();
    $siswa = User::query()->findOrFail($peserta->student_id);

    $kursiSendiri = kursiPeserta($peserta);
    $kursiLain = $kursiSendiri === 1 ? 2 : 1;

    $record = AttendanceRecord::query()
        ->where('exam_participant_id', $peserta->id)
        ->firstOrFail();

    masukSebagai($this, $siswa)
        ->post("/lab/{$this->slug}/{$kursiLain}")
        ->assertRedirect()
        ->assertSessionHas('galat', fn (string $pesan) => str_contains($pesan, 'bukan PC'));

    // Presensinya TIDAK berubah - penolakan terjadi sebelum penulisan.
    expect($record->fresh()->attendance_status->value)->toBe('pending');
});

it('menolak akun yang tidak ditempatkan di ruang ini', function () {
    $orangLuar = User::factory()->siswa()->untukSekolah($this->sekolah)->create([
        'username' => 'siswa.luar.kios',
    ]);

    masukSebagai($this, $orangLuar)
        ->post("/lab/{$this->slug}/1")
        ->assertRedirect()
        ->assertSessionHas('galat', fn (string $pesan) => str_contains($pesan, 'tidak ditempatkan'));
});

it('TIDAK menulis presensi kedua untuk anak yang sudah hadir', function () {
    // Anak yang memindai QR pintu dari HP-nya sudah `hadir` sebelum ia
    // menyentuh kios. Menulisnya lagi berarti dua baris log untuk satu
    // kejadian.
    $peserta = $this->d['ruang'][0]->participants()->orderBy('id')->firstOrFail();
    $siswa = User::query()->findOrFail($peserta->student_id);
    $kursi = kursiPeserta($peserta);

    $record = AttendanceRecord::query()
        ->where('exam_participant_id', $peserta->id)
        ->firstOrFail();

    $record->forceFill([
        'attendance_status' => 'hadir',
        'verified_at' => now(),
        'pelaku_label' => 'R-01 (pengawas)',
    ])->save();

    $sebelum = AttendanceLog::query()->where('attendance_record_id', $record->id)->count();

    masukSebagai($this, $siswa)
        ->post("/lab/{$this->slug}/{$kursi}")
        ->assertRedirect();

    $sesudah = AttendanceLog::query()->where('attendance_record_id', $record->id)->count();

    expect($sesudah)->toBe($sebelum);
});

it('menolak masuk ketika sesi ruang tidak berjalan', function () {
    $peserta = $this->d['ruang'][0]->participants()->orderBy('id')->firstOrFail();
    $siswa = User::query()->findOrFail($peserta->student_id);
    $kursi = kursiPeserta($peserta);

    // Jendelanya digeser ke masa lalu - bentuk yang sama dengan kios yang
    // dinyalakan sebelum jam ujian.
    $this->d['sesi']->forceFill([
        'access_opens_at' => now()->subHours(3),
        'access_closes_at' => now()->subHours(2),
        'hard_end_at' => now()->subHours(1),
    ])->save();

    masukSebagai($this, $siswa)
        ->post("/lab/{$this->slug}/{$kursi}")
        ->assertRedirect()
        ->assertSessionHas('galat', fn (string $pesan) => str_contains($pesan, 'tidak sedang berjalan'));
});

it('menuntut login untuk masuk, dan mengembalikan ke kios sesudahnya', function () {
    // `url.intended` hanya ditulis middleware `auth` saat ia mengalihkan -
    // itulah sebabnya tombolnya menunjuk rute gerbang, bukan `/login`.
    $peserta = $this->d['ruang'][0]->participants()->orderBy('id')->firstOrFail();
    $kursi = kursiPeserta($peserta);

    $this->get("/lab/{$this->slug}/{$kursi}/gerbang")
        ->assertRedirect('/login');

    expect(session('url.intended'))->toContain("/lab/{$this->slug}/{$kursi}/gerbang");
});

/* =====================================================================
 | Tautan kios di laman Ruang
 |===================================================================== */

it('menautkan kios dari daftar ruang, dan hanya untuk ruang ber-PC', function () {
    // Ruang BYOD tetap tampil, tetapi tidak menawarkan kios: tidak ada kursi
    // berperangkat di sana, sehingga `/lab/...` adalah alamat yang tidak
    // akan pernah dipakai.
    $byod = Room::factory()->create([
        'school_id' => $this->sekolah->id,
        'code' => 'R-99',
        'name' => 'Kelas Biasa',
        'capacity' => 32,
        'device_count' => 0,
        'status' => 'aktif',
    ]);

    $props = masukSebagai($this, $this->admin)
        ->get('/admin/ruang')
        ->viewData('page')['props'];

    $baris = collect($props['baris']['data']);

    // Ruang lab: punya slug tautan yang sedang berlaku.
    $lab = $baris->firstWhere('kode', $this->d['ruang'][0]->eventRoom->room->code);

    expect($lab)->not->toBeNull()
        ->and($lab['slug'])->toBe($this->slug)
        ->and($lab['byod'])->toBeFalse();

    // Dan slug itu benar-benar membuka kios.
    $this->get("/lab/{$lab['slug']}/1")->assertOk();

    // Ruang BYOD: tanpa PC, dan tanpa tautan yang ditawarkan.
    $tanpaPc = $baris->firstWhere('kode', 'R-99');

    expect($tanpaPc)->not->toBeNull()
        ->and($tanpaPc['byod'])->toBeTrue()
        ->and($tanpaPc['slug'])->toBeNull();
});

it('mengirim slug null bila tautan ruangnya belum diterbitkan', function () {
    // Tombol "Buka kios" tidak dirender tanpa slug - kios tanpa tautan
    // adalah alamat yang 404, dan tombol yang pasti gagal lebih buruk
    // daripada keterangan yang menyebut apa yang harus dilakukan.
    RoomEntryLink::query()->update(['revoked_at' => now()]);

    $props = masukSebagai($this, $this->admin)
        ->get('/admin/ruang')
        ->viewData('page')['props'];

    $lab = collect($props['baris']['data'])
        ->firstWhere('kode', $this->d['ruang'][0]->eventRoom->room->code);

    expect($lab['slug'])->toBeNull();
});

it('menampilkan layar, bukan 404, ketika sesinya sudah lewat', function () {
    // Bug yang dilaporkan 2026-11-17: `/lab/{slug}/1` menjawab 404 di lab
    // sungguhan, karena sesi terakhirnya berakhir 27 jam sebelumnya. Kios
    // dinyalakan lebih dulu daripada sesinya dan tetap menyala sesudahnya -
    // keduanya keadaan normal, dan keduanya menuntut layar yang menjelaskan,
    // bukan halaman galat.
    $this->d['sesi']->forceFill([
        'access_opens_at' => now()->subDays(2),
        'access_closes_at' => now()->subDay(),
        'hard_end_at' => now()->subDay(),
    ])->save();

    $this->get("/lab/{$this->slug}/1")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Pengawas/KiosLab')
            ->where('sesi.berjalan', false)
            ->where('pesan', fn ($p) => is_string($p) && str_contains($p, 'sudah berakhir'))
        );
});

it('menampilkan layar, bukan 404, ketika sesinya belum mulai', function () {
    $this->d['sesi']->forceFill([
        'access_opens_at' => now()->addDay(),
        'access_closes_at' => now()->addDay()->addHours(2),
        'hard_end_at' => now()->addDay()->addHours(2),
    ])->save();

    $this->get("/lab/{$this->slug}/1")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Pengawas/KiosLab')
            ->where('sesi.berjalan', false)
            ->where('pesan', fn ($p) => is_string($p) && str_contains($p, 'Jadwalnya'))
        );
});
