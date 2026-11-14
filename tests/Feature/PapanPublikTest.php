<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Exam\Services\SesiService;
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
