<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Exam\Services\PengawasService;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * Papan publik pengawas - `/papan/{slug}`, ditambahkan 2026-11-14.
 *
 * =====================================================================
 * Yang paling mahal bila rusak bukan "papannya kosong"
 * =====================================================================
 * Ia halaman TANPA LOGIN yang tayang untuk seluruh internet. Yang dijaga di
 * sini karena itu bukan tampilannya, melainkan TIGA hal:
 *
 *   1. Sekolah tanpa slug tidak punya alamat sama sekali - bukan alamat
 *      yang menolak.
 *   2. Isinya hanya nama ruang, nama pengawas, dan jam. Nama siswa, nomor
 *      kursi, dan token tidak boleh ikut lewat jalur mana pun.
 *   3. Slug unik LINTAS sekolah, karena rutenya di-resolve tanpa konteks
 *      tenant.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);
    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.papan',
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

it('tidak punya alamat sebelum Admin menyalakan papannya', function () {
    expect($this->sekolah->papan_slug)->toBeNull();

    // Rute yang tidak pernah lahir tidak bisa ditebak - lebih kuat daripada
    // 403 yang justru membuktikan alamatnya ada.
    $this->get('/papan/apa-saja')->assertNotFound();
});

it('menyalakan papan membangkitkan slug dari nama sekolah', function () {
    $this->actingAs($this->admin)
        ->patch('/admin/papan', ['aktif' => true])
        ->assertRedirect()
        ->assertSessionHas('sukses');

    $slug = $this->sekolah->fresh()->papan_slug;

    expect($slug)->not->toBeNull()
        ->and($slug)->toStartWith('smk-negeri-1-');
});

it('tidak mengganti slug saat dinyalakan ulang', function () {
    // Tautannya sudah ditempel di lorong. Menyalakan ulang yang menghasilkan
    // alamat baru berarti setiap tautan lama menjadi 404.
    $this->actingAs($this->admin)->patch('/admin/papan', ['aktif' => true]);
    $slug = $this->sekolah->fresh()->papan_slug;

    $this->actingAs($this->admin)
        ->patch('/admin/papan', ['aktif' => true])
        ->assertRedirect();

    expect($this->sekolah->fresh()->papan_slug)->toBe($slug);
});

it('mematikan papan membuat alamatnya benar-benar mati', function () {
    $this->actingAs($this->admin)->patch('/admin/papan', ['aktif' => true]);
    $slug = $this->sekolah->fresh()->papan_slug;

    $this->get("/papan/{$slug}")->assertOk();

    $this->actingAs($this->admin)
        ->patch('/admin/papan', ['aktif' => false])
        ->assertSessionHas('galat');

    // Papan yang "dimatikan" tetapi masih bisa dibuka dengan alamat lama
    // adalah papan yang tidak dimatikan.
    $this->get("/papan/{$slug}")->assertNotFound();
});

it('menampilkan ruang, pengawas, dan jam - tanpa nama siswa', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $this->actingAs($this->admin)->patch('/admin/papan', ['aktif' => true]);
    $slug = $this->sekolah->fresh()->papan_slug;

    // Sesi harus BERJALAN supaya terpilih papan. `siapkanUjianSiswa` berhenti
    // di "siap"; satu transisi status lewat service - jalur yang sama dengan
    // Admin menekan Buka.
    app(SesiService::class)->mulai($data['sesi'], $this->admin->id);

    $props = $this->get("/papan/{$slug}")->assertOk()->viewData('page')['props'];

    expect($props['sekolah'])->toBe('SMK Negeri 1')
        ->and($props['sesi'])->toHaveCount(1);

    $sesi = $props['sesi'][0];

    expect($sesi['ruang'])->toHaveCount(1)
        ->and($sesi['ruang'][0]['kode'])->toBe($data['ruang'][0]->eventRoom->room->code)
        ->and($sesi['ruang'][0]['pengawas'])->toHaveCount(1)
        ->and($sesi['ruang'][0]['pengawas'][0]['nama'])->toBe($data['pengawas']->name)
        ->and($sesi['jam'])->toMatch('/^\d{2}:\d{2}–\d{2}:\d{2}$/');

    // BATASNYA: nama siswa tidak boleh ikut lewat jalur mana pun. Diperiksa
    // pada JSON mentah, bukan hanya pada kunci - nama yang lolos lewat
    // `peserta.nama` di dalam sel akan lolos dari pemeriksaan kunci.
    $mentah = json_encode($props);

    foreach ($data['siswa'] as $siswa) {
        expect($mentah)->not->toContain($siswa->name)
            ->and($mentah)->not->toContain($siswa->username);
    }

    // Token juga tidak: ia ditulis di papan tulis ruangan, dan papan ini
    // tayang di internet.
    expect($mentah)->not->toContain($data['token']);
});

it('tetap menampilkan ruang yang pengawasnya belum ditetapkan', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    // Sesi dibuat BERJALAN lebih dulu lewat jalur produksi - `mulai()`
    // menolak ruang tanpa pengawas, dan penolakan itu benar. Pengawasnya
    // dilepas SESUDAHNYA, meniru keadaan yang paling perlu dikejar panitia
    // sepuluh menit sebelum sesi dibuka: sesi sudah menyala, pengawasnya
    // batal datang.
    app(SesiService::class)->mulai($data['sesi'], $this->admin->id);

    SupervisionAssignment::query()
        ->where('exam_session_room_id', $data['ruang'][0]->id)
        ->delete();

    $this->actingAs($this->admin)->patch('/admin/papan', ['aktif' => true]);
    $slug = $this->sekolah->fresh()->papan_slug;

    $props = $this->get("/papan/{$slug}")->assertOk()->viewData('page')['props'];

    // Ruangnya tetap TAMPIL (yang hilang dari daftar tidak bisa dicari),
    // dan kodenya muncul di `tanpa_pengawas` supaya bisa dikejar.
    expect($props['sesi'][0]['ruang'])->toHaveCount(1)
        ->and($props['sesi'][0]['ruang'][0]['pengawas'])->toBe([])
        ->and($props['tanpa_pengawas'])->toHaveCount(1);
});

it('menampilkan halaman per pengawas, dan 404 untuk yang tidak bertugas', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $this->actingAs($this->admin)->patch('/admin/papan', ['aktif' => true]);
    $slug = $this->sekolah->fresh()->papan_slug;

    app(SesiService::class)->mulai($data['sesi'], $this->admin->id);

    $props = $this->get("/papan/{$slug}/pengawas/{$data['pengawas']->id}")
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['nama'])->toBe($data['pengawas']->name)
        ->and($props['ruang'])->toHaveCount(1);

    // Pengawas lain yang tidak bertugas pada sesi ini: 404, bukan halaman
    // kosong. Halaman yang menjawab "tidak ada" untuk SETIAP id membuat
    // penanya mengira papannya rusak.
    $lain = User::factory()->pengawas()->untukSekolah($this->sekolah)->create();

    $this->get("/papan/{$slug}/pengawas/{$lain->id}")->assertNotFound();
});

/* =====================================================================
 | Sesi yang tidak lagi aktif - ditambahkan 2026-11-14
 |===================================================================== */

it('menyembunyikan sesi dari event yang sudah selesai, arsip, atau batal', function () {
    // Gejala 2026-11-14: papan publik di lorong masih menyebut "Bu Ani di
    // R-02" untuk ujian yang sudah diarsipkan. Versi pertama hanya
    // menyaring `school_id`, sehingga status event tidak pernah dibaca.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $this->actingAs($this->admin)->patch('/admin/papan', ['aktif' => true]);
    $slug = $this->sekolah->fresh()->papan_slug;

    app(SesiService::class)->mulai($data['sesi']);

    expect($this->get("/papan/{$slug}")->assertOk()->viewData('page')['props']['sesi'])
        ->toHaveCount(1);

    foreach (['selesai', 'arsip', 'batal'] as $status) {
        $data['event']->forceFill(['status' => $status])->save();

        $props = $this->get("/papan/{$slug}")->assertOk()->viewData('page')['props'];

        expect($props['sesi'])->toBeEmpty()
            ->and($props['tanpa_pengawas'])->toBeEmpty();
    }
});

it('menyembunyikan sesi yang jendelanya sudah lewat walau statusnya masih berjalan', function () {
    // `ujian:tutup-sesi` berjalan tiap menit, sehingga ada jendela singkat
    // sesudah `hard_end_at` lewat dan sebelum statusnya dipindahkan. Papan
    // yang tayang tanpa login tidak boleh menjawab dengan keadaan yang sudah
    // tidak benar - orang di lorong sudah membaca "R-02" sebelum barisnya
    // tertutup.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $this->actingAs($this->admin)->patch('/admin/papan', ['aktif' => true]);
    $slug = $this->sekolah->fresh()->papan_slug;

    app(SesiService::class)->mulai($data['sesi']);

    $this->travel(4)->hours();

    // Status SESI sengaja belum disentuh - itulah keadaan yang diuji.
    expect($data['sesi']->refresh()->status->value)->toBe('berjalan')
        ->and(now()->greaterThan($data['sesi']->hard_end_at))->toBeTrue();

    expect($this->get("/papan/{$slug}")->assertOk()->viewData('page')['props']['sesi'])
        ->toBeEmpty();
});

it('tetap menampilkan sesi milik event yang belum dibuka', function () {
    // Kebalikannya harus dijaga juga: penyaring yang terlalu ketat membuat
    // papan kosong pada pagi hari ujian, dan itu justru satu-satunya saat
    // papan ini berguna.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $this->actingAs($this->admin)->patch('/admin/papan', ['aktif' => true]);
    $slug = $this->sekolah->fresh()->papan_slug;

    // `siapkanUjianSiswa` berhenti di tahap sesi "siap" tanpa memindahkan
    // status EVENT-nya - jadi eventnya masih `draft`. Yang diuji di sini
    // adalah bahwa tahap itu MASIH TAMPIL: papan dipakai justru sebelum
    // sesinya dibuka, untuk memastikan pengawasnya sudah di ruangnya.
    expect($data['event']->refresh()->status->value)->toBe('draft');

    expect($this->get("/papan/{$slug}")->assertOk()->viewData('page')['props']['sesi'])
        ->toHaveCount(1);
});

it('tidak membocorkan papan sekolah lain lewat slug', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    app(SesiService::class)->mulai($data['sesi'], $this->admin->id);

    $lain = School::factory()->create(['name' => 'SMA Negeri 2']);
    $this->actingAs($this->admin)->patch('/admin/papan', ['aktif' => true]);
    $slug = $this->sekolah->fresh()->papan_slug;

    // Sekolah lain TIDAK menyalakan papannya.
    $props = $this->get("/papan/{$slug}")->assertOk()->viewData('page')['props'];

    expect($props['sekolah'])->toBe('SMK Negeri 1');

    // Dan slug sekolah yang tidak menyala tidak ditemukan sama sekali.
    expect($lain->fresh()->papan_slug)->toBeNull();
});

/* =====================================================================
 | Tukar pengawas - ditambahkan 2026-11-14
 |===================================================================== */

it('menukar dua penugasan pengawas antar ruang', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    // Ruang kedua pada sesi yang sama, dengan pengawas lain.
    $ruangDua = ExamSessionRoom::query()
        ->where('exam_session_id', $data['sesi']->id)
        ->where('id', '!=', $data['ruang'][0]->id)
        ->first();

    // Event ini baru punya satu ruang - buat ruang sesi kedua dari ruang
    // master yang baru, lewat service yang sama dengan Admin.
    if ($ruangDua === null) {
        $ruangMaster = Room::factory()->create([
            'school_id' => $this->sekolah->id,
            'code' => 'R-02-TUKAR',
            'capacity' => 10,
        ]);

        $eventRoom = EventRoom::create([
            'school_id' => $this->sekolah->id,
            'event_id' => $data['event']->id,
            'room_id' => $ruangMaster->id,
            'status' => 'aktif',
        ]);

        $ruangDua = ExamSessionRoom::create([
            'school_id' => $this->sekolah->id,
            'exam_session_id' => $data['sesi']->id,
            'event_room_id' => $eventRoom->id,
        ]);
    }

    $pengawasDua = User::factory()->pengawas()
        ->untukSekolah($this->sekolah)->create(['username' => 'pengawas.tukar']);

    app(PengawasService::class)->tugaskan($ruangDua, $pengawasDua, $this->admin->id);

    $tugasA = SupervisionAssignment::query()
        ->where('exam_session_room_id', $data['ruang'][0]->id)->sole();
    $tugasB = SupervisionAssignment::query()
        ->where('exam_session_room_id', $ruangDua->id)->sole();

    expect((int) $tugasA->supervisor_id)->toBe($data['pengawas']->id);

    $this->actingAs($this->admin)
        ->post("/admin/events/{$data['event']->id}/pengawas/tukar", [
            'a' => $tugasA->id,
            'b' => $tugasB->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('sukses');

    // Orangnya bertukar; BARISNYA tetap, sehingga ruang dan sesinya tidak
    // bergerak - hanya siapa yang berdiri di sana.
    expect((int) $tugasA->fresh()->supervisor_id)->toBe($pengawasDua->id)
        ->and((int) $tugasB->fresh()->supervisor_id)->toBe($data['pengawas']->id)
        ->and((int) $tugasA->fresh()->exam_session_room_id)->toBe((int) $data['ruang'][0]->id);
});

it('menolak menukar penugasan dengan dirinya sendiri', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $tugas = SupervisionAssignment::query()
        ->where('exam_session_room_id', $data['ruang'][0]->id)->sole();

    $this->actingAs($this->admin)
        ->post("/admin/events/{$data['event']->id}/pengawas/tukar", [
            'a' => $tugas->id,
            'b' => $tugas->id,
        ])
        ->assertSessionHasErrors('b');
});

it('menolak menukar penugasan milik event lain', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $lain = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $tugasA = SupervisionAssignment::query()
        ->where('exam_session_room_id', $data['ruang'][0]->id)->sole();
    $tugasB = SupervisionAssignment::query()
        ->where('exam_session_room_id', $lain['ruang'][0]->id)->sole();

    // 404, bukan 403 - alasan yang sama dengan seluruh rute bersarang.
    $this->actingAs($this->admin)
        ->post("/admin/events/{$data['event']->id}/pengawas/tukar", [
            'a' => $tugasA->id,
            'b' => $tugasB->id,
        ])
        ->assertNotFound();

    // Dan tidak satu pun berubah.
    expect((int) $tugasA->fresh()->supervisor_id)->toBe($data['pengawas']->id)
        ->and((int) $tugasB->fresh()->supervisor_id)->toBe($lain['pengawas']->id);
});

it('membatalkan seluruh pertukaran bila hasilnya bentrok', function () {
    // Penugasan pertama di sesi 1, penugasan kedua di SESI LAIN pada jam yang
    // sama. Menukarnya akan menaruh pengawas sesi 1 ke sesi 2, dan ia sudah
    // punya penugasan di sana - bentrok.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $tugasA = SupervisionAssignment::query()
        ->where('exam_session_room_id', $data['ruang'][0]->id)->sole();

    $sesiKedua = app(SesiService::class)->buat($data['ujian'], [
        'session_number' => 9,
        'access_opens_at' => now()->subHour(),
        'access_closes_at' => now()->addHours(2),
        'hard_end_at' => now()->addHours(3),
        'event_room_id' => [$data['event']->rooms()->first()->id],
    ]);

    $ruangKedua = ExamSessionRoom::query()
        ->where('exam_session_id', $sesiKedua->id)->firstOrFail();

    // Pengawas yang SAMA ditugaskan di sesi kedua yang jendelanya bertumpang.
    // `tugaskan()` menolak bentrok, jadi baris kedua ditulis langsung - yang
    // diuji di sini adalah penjagaan `tukar()`, bukan penjagaan `tugaskan()`.
    $tugasB = SupervisionAssignment::create([
        'school_id' => $this->sekolah->id,
        'exam_session_room_id' => $ruangKedua->id,
        'supervisor_id' => $data['pengawas']->id,
        'assigned_by' => $this->admin->id,
        'status' => 'aktif',
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/events/{$data['event']->id}/pengawas/tukar", [
            'a' => $tugasA->id,
            'b' => $tugasB->id,
        ])
        ->assertSessionHas('galat');

    // Penolakan TIDAK meninggalkan perubahan setengah jadi: kedua baris
    // kembali ke orang semula.
    expect((int) $tugasA->fresh()->supervisor_id)->toBe($data['pengawas']->id)
        ->and((int) $tugasB->fresh()->supervisor_id)->toBe($data['pengawas']->id);
});

it('mengirim dua penugasan yang bisa ditukar dari laman Pengawas', function () {
    // Panel tukar disusun dari `baris[].pengawas[]` yang dikirim server -
    // bukan dari state lain. Test ini menjaga kontraknya: id penugasan
    // (bukan id orang), beserta ruang dan sesi yang membedakan dua baris
    // yang orangnya sama.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $kedua = User::factory()->pengawas()
        ->untukSekolah($this->sekolah)->create(['username' => 'pengawas.dua']);

    app(PengawasService::class)
        ->tugaskan($data['ruang'][0], $kedua, $this->admin->id);

    $props = $this->actingAs($this->admin)
        ->get("/admin/events/{$data['event']->id}/pengawas")
        ->assertOk()
        ->viewData('page')['props'];

    $pengawas = collect($props['baris'])->flatMap(fn (array $b) => $b['pengawas']);

    expect($pengawas)->toHaveCount(2)
        // `id` adalah id BARIS penugasan - yang ditukar. `user_id` adalah
        // orangnya. Mengirim hanya `user_id` berarti laman tidak bisa
        // menyatakan penugasan mana yang dimaksud saat orangnya memegang
        // dua penugasan sah di sesi berbeda.
        ->and($pengawas->pluck('id')->unique())->toHaveCount(2)
        ->and($pengawas->pluck('user_id')->unique())->toHaveCount(2)
        ->and($pengawas->every(fn (array $p) => isset($p['nama'])))->toBeTrue();
});
