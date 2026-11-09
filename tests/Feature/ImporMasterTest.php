<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Admin\Enums\JenisImporMaster;
use App\Domain\Admin\Import\PenjajarKelas;
use App\Domain\Admin\Import\PenjajarMapel;
use App\Domain\Admin\Import\PenjajarRuang;
use App\Domain\Admin\Models\MasterImportBatch;
use App\Domain\Admin\Services\ImporMasterService;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Impor master data - mata pelajaran, ruang ujian, dan kelas.
 *
 * =====================================================================
 * Janji yang dijaga berkas ini
 * =====================================================================
 * Wireframe menampilkan tombol "Impor" di laman Mata pelajaran (plate 08), di
 * laman Ruang (plate 09), dan di laman Kelas (plate 07), dan ketiganya dulu
 * MATI. Berkas ini menjaga dua hal yang membuat tombol itu layak hidup:
 *
 *   1. BARIS DICOCOKKAN LEWAT KOLOM KUNCINYA, DAN YANG COCOK DIPERBARUI.
 *      `kode` untuk mapel dan ruang, `nama` untuk kelas. Kalau tidak,
 *      mengimpor berkas yang sama dua kali akan pecah pada unique constraint -
 *      galat yang tidak bisa ditindaklanjuti Admin, untuk berkas yang benar.
 *
 *   2. SEL KOSONG TIDAK MENGOSONGKAN DATA YANG SUDAH ADA. Berkas yang hanya
 *      memuat kode dan nama tidak boleh menghapus `kelompok` dan `tingkat` -
 *      dan ruang yang kolom `pc`-nya kosong tetap BYOD, bukan menjadi nol
 *      yang tertulis.
 *
 * =====================================================================
 * Yang paling mudah lulus secara teknis dan paling mudah gagal secara nyata
 * =====================================================================
 * Nomor baris. Importer yang melaporkan "baris 2 gagal" untuk galat di baris 7
 * lulus setiap pemeriksaan yang hanya menanyakan "apakah ada galat" - dan
 * Admin yang membuka Excelnya akan memperbaiki baris yang salah.
 *
 * Berkasnya dibangun di dalam test, bukan disimpan sebagai fixture: yang
 * menentukan di sini adalah BENTUK berkasnya, dan CSV yang ditulis terbuka
 * bisa dibaca di tempat.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->sekolah = School::factory()->create(['code' => 'smkn1']);
    $this->lain = School::factory()->create(['code' => 'smkn2']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.master',
    ]);

    $this->adminLain = User::factory()->admin()->untukSekolah($this->lain)->create([
        'username' => 'admin.master.lain',
    ]);

    $this->guru = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'guru.master',
    ]);
});

/** Login yang melewati cache resolver tenant. */
function masukMaster(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/**
 * Berkas CSV dari baris-baris sel, berpemisah titik koma.
 *
 * Titik koma, bukan koma: Excel berbahasa Indonesia menyimpan CSV-nya begitu,
 * dan templatenya sendiri juga berpemisah titik koma.
 *
 * @param  array<int, array<int, string>>  $baris
 */
function berkasMaster(array $baris, string $nama = 'master.csv'): UploadedFile
{
    $isi = "\xEF\xBB\xBF";

    foreach ($baris as $satu) {
        $isi .= implode(';', array_map(fn (string $s) => '"'.str_replace('"', '""', $s).'"', $satu))."\r\n";
    }

    $jalur = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cbt-impor-master-'.uniqid().'-'.$nama;

    file_put_contents($jalur, $isi);

    return new UploadedFile($jalur, $nama, 'text/csv', null, true);
}

/** Satu baris mapel yang sah. @return array<int, string> */
function barisMapel(
    string $kode = 'MTK',
    string $nama = 'Matematika',
    string $kelompok = 'Wajib',
    string $tingkat = 'X',
    string $status = 'aktif',
): array {
    return [$kode, $nama, $kelompok, $tingkat, $status];
}

/** Satu baris ruang yang sah. @return array<int, string> */
function barisRuang(
    string $kode = 'R-01',
    string $nama = 'Ruang 01',
    string $lokasi = 'Gedung A',
    string $kapasitas = '32',
    string $pc = '32',
    string $status = 'aktif',
): array {
    return [$kode, $nama, $lokasi, $kapasitas, $pc, $status];
}

/** Unggah + pratinjau, langsung dari controller. */
function unggahMaster(
    TestCase $test,
    JenisImporMaster $jenis,
    array $baris,
    ?User $admin = null,
    string $nama = 'master.csv',
): MasterImportBatch {
    masukMaster($test, $admin ?? $test->admin);

    $test->post("/admin/impor/{$jenis->value}/unggah", ['berkas' => berkasMaster($baris, $nama)])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    return MasterImportBatch::query()->latest('id')->firstOrFail();
}

/** Konfirmasi batch yang sudah dipratinjau. */
function konfirmasiMaster(TestCase $test, MasterImportBatch $batch, ?User $admin = null): void
{
    masukMaster($test, $admin ?? $test->admin);

    $test->post("/admin/impor/{$batch->jenis}/{$batch->id}/konfirmasi")
        ->assertRedirect()
        ->assertSessionHasNoErrors();
}

/* ====================================================== Laman & template */

it('menampilkan laman impor mapel', function () {
    masukMaster($this, $this->admin);

    $this->get('/admin/impor/mapel')
        ->assertOk()
        ->assertInertia(fn ($halaman) => $halaman
            ->component('Admin/MasterImpor')
            ->where('jenis', 'mapel')
            ->where('kembali', '/admin/mapel')
            ->where('kolom', PenjajarMapel::judul())
        );
});

it('menampilkan laman impor ruang', function () {
    masukMaster($this, $this->admin);

    $this->get('/admin/impor/ruang')
        ->assertOk()
        ->assertInertia(fn ($halaman) => $halaman
            ->component('Admin/MasterImpor')
            ->where('jenis', 'ruang')
            ->where('kembali', '/admin/ruang')
            ->where('kolom', PenjajarRuang::judul())
        );
});

it('menjawab 404 untuk jenis yang tidak dikenal', function () {
    masukMaster($this, $this->admin);

    $this->get('/admin/impor/siswa')->assertNotFound();
    $this->get('/admin/impor/../etc/passwd')->assertNotFound();
});

it('mengunduh template yang kolomnya sama dengan yang dibaca importer', function () {
    masukMaster($this, $this->admin);

    $respons = $this->get('/admin/impor/mapel/template')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    expect($respons->headers->get('Content-Disposition'))->toContain('template-impor-mapel.csv');

    // Baris pertama adalah judul kolomnya - dan ia harus sama dengan daftar
    // yang dibaca importer. Template dan importer yang berbeda diam-diam
    // adalah berkas yang selalu ditolak, tanpa satu pun petunjuk sebabnya.
    $baris = explode("\n", trim(str_replace("\xEF\xBB\xBF", '', $respons->getContent())));

    expect(str_getcsv($baris[0], ';', '"', '\\'))->toBe(PenjajarMapel::judul());
});

/* ======================================================= Pratinjau & baris */

it('mempratinjau baris mapel baru tanpa menyimpan satu pun', function () {
    $batch = unggahMaster($this, JenisImporMaster::Mapel, [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika'),
        barisMapel('BIN', 'Bahasa Indonesia', '', ''),
    ]);

    expect($batch->statusImpor()->value)->toBe('dipratinjau')
        ->and($batch->pratinjau_json['baru'])->toBe(2)
        ->and($batch->pratinjau_json['diperbarui'])->toBe(0);

    // Pratinjau tidak menulis apa pun - itulah gunanya.
    expect(Subject::tanpaTenant()->count())->toBe(0);
});

it('melaporkan nomor baris yang dilihat Admin di Excel', function () {
    $batch = unggahMaster($this, JenisImporMaster::Mapel, [
        ['Laporan mata pelajaran', ''],      // baris 1 - judul berkas
        PenjajarMapel::judul(),              // baris 2 - judul kolom
        barisMapel('MTK', 'Matematika'),     // baris 3
        barisMapel('', 'Tanpa kode'),        // baris 4 - gagal
    ]);

    $baris = $batch->pratinjau_json['baris'];

    // Nomor yang dilaporkan adalah nomor BARIS BERKASNYA, bukan nomor urut
    // hasil impor. Baris 4 di sini harus baris 4 di Excel.
    $gagal = collect($baris)->firstWhere('sah', false);

    expect($gagal['nomor'])->toBe(4)
        ->and($gagal['kolom'])->toBe('kode');
});

it('menolak dua baris dengan kode yang sama di dalam satu berkas', function () {
    $batch = unggahMaster($this, JenisImporMaster::Mapel, [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika'),
        barisMapel('MTK', 'Matematika Lagi'),
    ]);

    $baris = $batch->pratinjau_json['baris'];

    expect($batch->pratinjau_json['sah'])->toBe(1)
        ->and($batch->pratinjau_json['gagal'])->toBe(1);

    // Yang ditolak baris KEDUA: yang membaca laporan membuka berkasnya dari
    // atas, dan baris pertama itulah yang ia lihat lebih dulu.
    expect($baris[1]['nomor'])->toBe(3)
        ->and($baris[1]['pesan'])->toContain('baris 2');
});

it('menolak berkas tanpa kolom wajib', function () {
    masukMaster($this, $this->admin);

    $this->post('/admin/impor/mapel/unggah', [
        'berkas' => berkasMaster([
            ['nama', 'kelompok'],
            ['Matematika', 'Wajib'],
        ]),
    ])->assertSessionHasErrors('berkas');

    $batch = MasterImportBatch::query()->latest('id')->firstOrFail();

    expect($batch->statusImpor()->value)->toBe('gagal')
        ->and($batch->pratinjau_json['pesan'])->toContain('kode');
});

it('menolak berkas kosong', function () {
    masukMaster($this, $this->admin);

    $this->post('/admin/impor/mapel/unggah', ['berkas' => berkasMaster([])])
        ->assertSessionHasErrors('berkas');

    expect(Subject::tanpaTenant()->count())->toBe(0);
});

it('menolak berkas dengan jenis yang tidak didukung', function () {
    masukMaster($this, $this->admin);

    $jalur = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cbt-impor-master-'.uniqid().'.xls';
    file_put_contents($jalur, 'bukan xlsx');

    $this->post('/admin/impor/mapel/unggah', [
        'berkas' => new UploadedFile($jalur, 'mapel.xls', 'application/vnd.ms-excel', null, true),
    ])->assertSessionHasErrors('berkas');
});

it('menolak unggahan berkas yang sama dua kali', function () {
    unggahMaster($this, JenisImporMaster::Mapel, [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika'),
    ], nama: 'sama.csv');

    masukMaster($this, $this->admin);

    $this->post('/admin/impor/mapel/unggah', [
        'berkas' => berkasMaster([
            PenjajarMapel::judul(),
            barisMapel('MTK', 'Matematika'),
        ], 'sama.csv'),
    ])->assertSessionHasErrors('berkas');
});

it('mengizinkan berkas mapel yang sama diunggah di laman ruang', function () {
    // Berkas yang sama, dua jenis berbeda. Menolaknya dengan "sudah pernah
    // diunggah" adalah kalimat yang membingungkan untuk masalah yang
    // sebenarnya adalah salah laman.
    $isi = [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika'),
    ];

    unggahMaster($this, JenisImporMaster::Mapel, $isi, nama: 'lintas.csv');

    masukMaster($this, $this->admin);

    $this->post('/admin/impor/ruang/unggah', ['berkas' => berkasMaster($isi, 'lintas.csv')])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(MasterImportBatch::query()->count())->toBe(2);
});

/* ========================================================== Konfirmasi */

it('membuat mapel baru pada konfirmasi', function () {
    $batch = unggahMaster($this, JenisImporMaster::Mapel, [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika', 'Wajib', 'X'),
        barisMapel('BIN', 'Bahasa Indonesia', 'Wajib', ''),
    ]);

    konfirmasiMaster($this, $batch);
    $batch->refresh();

    expect($batch->created_rows)->toBe(2)
        ->and($batch->updated_rows)->toBe(0)
        ->and($batch->statusImpor()->value)->toBe('dikonfirmasi');

    $mtk = Subject::tanpaTenant()->where('code', 'MTK')->firstOrFail();

    expect($mtk->name)->toBe('Matematika')
        ->and($mtk->category)->toBe('Wajib')
        ->and($mtk->grade_level)->toBe('X')
        ->and($mtk->school_id)->toBe($this->sekolah->id);
});

it('memperbarui mapel yang kodenya sudah ada, bukan membuat yang kedua', function () {
    $lama = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'MTK',
        'name' => 'Matematika Lama',
        'category' => 'Wajib',
        'grade_level' => 'X',
    ]);

    $batch = unggahMaster($this, JenisImporMaster::Mapel, [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika Baru'),
    ]);

    expect($batch->pratinjau_json['baru'])->toBe(0)
        ->and($batch->pratinjau_json['diperbarui'])->toBe(1);

    konfirmasiMaster($this, $batch);

    expect(Subject::tanpaTenant()->where('code', 'MTK')->count())->toBe(1);

    $lama->refresh();

    expect($lama->name)->toBe('Matematika Baru')
        // Sel kosong TIDAK mengosongkan yang sudah terisi.
        ->and($lama->category)->toBe('Wajib')
        ->and($lama->grade_level)->toBe('X');
});

it('mengizinkan kode yang sama di sekolah lain', function () {
    Subject::factory()->untukSekolah($this->lain)->create(['code' => 'MTK']);

    $batch = unggahMaster($this, JenisImporMaster::Mapel, [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika'),
    ]);

    // Bagi sekolah ini, kodenya belum ada - dan sekolah lain tidak boleh
    // membuat barisnya terlihat "sudah ada".
    expect($batch->pratinjau_json['baru'])->toBe(1);

    konfirmasiMaster($this, $batch);

    expect(Subject::tanpaTenant()->where('code', 'MTK')->count())->toBe(2);
});

it('menyimpan baris yang sah meski ada baris yang gagal', function () {
    $batch = unggahMaster($this, JenisImporMaster::Mapel, [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika'),
        barisMapel('', 'Tanpa kode'),
        barisMapel('BIN', 'Bahasa Indonesia'),
    ]);

    konfirmasiMaster($this, $batch);
    $batch->refresh();

    expect($batch->success_rows)->toBe(2)
        ->and($batch->failed_rows)->toBe(1)
        ->and($batch->statusImpor()->value)->toBe('dikonfirmasi')
        ->and($batch->error_report_path)->not->toBeNull();

    expect(Subject::tanpaTenant()->pluck('code')->sort()->values()->all())->toBe(['BIN', 'MTK']);
});

it('menulis laporan galat yang bisa diunduh', function () {
    $batch = unggahMaster($this, JenisImporMaster::Mapel, [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika'),
        barisMapel('', 'Tanpa kode'),
    ]);

    konfirmasiMaster($this, $batch);
    $batch->refresh();

    masukMaster($this, $this->admin);

    $respons = $this->get("/admin/impor/mapel/{$batch->id}/laporan")->assertOk();

    // Laporan memuat NOMOR BARIS, bukan hanya sebabnya - itu satu-satunya
    // gunanya bagi Admin yang membuka Excelnya.
    expect($respons->streamedContent())
        ->toContain('kode')
        ->toContain('Tanpa kode');
});

it('menjawab 404 untuk laporan berkas Admin lain', function () {
    $batch = unggahMaster($this, JenisImporMaster::Mapel, [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika'),
    ]);

    // Bukan 403: bagi Admin lain, barisnya memang tidak ada. Baris impor
    // memuat nama asli berkas orang lain.
    masukMaster($this, $this->adminLain);

    $this->get("/admin/impor/mapel/{$batch->id}/laporan")->assertNotFound();
});

it('tidak bisa dikonfirmasi dua kali', function () {
    $batch = unggahMaster($this, JenisImporMaster::Mapel, [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika'),
    ]);

    konfirmasiMaster($this, $batch);

    masukMaster($this, $this->admin);

    $this->post("/admin/impor/mapel/{$batch->id}/konfirmasi")
        ->assertSessionHasErrors('berkas');
});

it('menjawab 404 saat konfirmasi batch milik Admin lain', function () {
    $batch = unggahMaster($this, JenisImporMaster::Mapel, [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika'),
    ]);

    masukMaster($this, $this->adminLain);

    $this->post("/admin/impor/mapel/{$batch->id}/konfirmasi")->assertNotFound();
});

it('menjawab 404 saat batch mapel dibuka lewat rute ruang', function () {
    $batch = unggahMaster($this, JenisImporMaster::Mapel, [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika'),
    ]);

    masukMaster($this, $this->admin);

    $this->get("/admin/impor/ruang/{$batch->id}/laporan")->assertNotFound();
});

/* ================================================================ Ruang */

it('membuat ruang baru dari berkas', function () {
    $batch = unggahMaster($this, JenisImporMaster::Ruang, [
        PenjajarRuang::judul(),
        barisRuang('R-01', 'Ruang 01', 'Gedung A', '32', '32'),
        barisRuang('R-02', 'Ruang 02', '', '40', '0'),
    ]);

    konfirmasiMaster($this, $batch);

    $r01 = Room::tanpaTenant()->where('code', 'R-01')->firstOrFail();
    $r02 = Room::tanpaTenant()->where('code', 'R-02')->firstOrFail();

    expect($r01->capacity)->toBe(32)
        ->and($r01->device_count)->toBe(32)
        ->and($r01->isByod())->toBeFalse()
        // `0` harus LOLOS sebagai nilai, bukan dibuang sebagai falsy -
        // ruang tanpa PC adalah ruang BYOD, dan itu keadaan yang sah.
        ->and($r02->device_count)->toBe(0)
        ->and($r02->isByod())->toBeTrue();
});

it('menolak jumlah PC yang melebihi kapasitas', function () {
    $batch = unggahMaster($this, JenisImporMaster::Ruang, [
        PenjajarRuang::judul(),
        barisRuang('R-01', 'Ruang 01', 'Gedung A', '20', '32'),
    ]);

    expect($batch->pratinjau_json['gagal'])->toBe(1)
        ->and($batch->pratinjau_json['baris'][0]['kolom'])->toBe('pc');
});

it('menolak kapasitas yang bukan angka', function () {
    $batch = unggahMaster($this, JenisImporMaster::Ruang, [
        PenjajarRuang::judul(),
        barisRuang('R-01', 'Ruang 01', 'Gedung A', 'tiga puluh dua', '0'),
    ]);

    // `(int) 'tiga puluh dua'` adalah 0, dan 0 lolos `integer` - sehingga
    // kapasitas yang salah ketik akan tersimpan sebagai kapasitas nol.
    expect($batch->pratinjau_json['gagal'])->toBe(1)
        ->and($batch->pratinjau_json['baris'][0]['kolom'])->toBe('kapasitas');
});

it('menolak status yang tidak dikenal jenisnya', function () {
    $batch = unggahMaster($this, JenisImporMaster::Mapel, [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika', 'Wajib', 'X', 'perbaikan'),
    ]);

    // "perbaikan" adalah status ruang, bukan status mapel. Satu daftar
    // bersama akan meloloskannya, dan barisnya tersimpan tanpa muncul di
    // filter mana pun.
    expect($batch->pratinjau_json['gagal'])->toBe(1)
        ->and($batch->pratinjau_json['baris'][0]['kolom'])->toBe('status');
});

it('menerima status perbaikan untuk ruang', function () {
    $batch = unggahMaster($this, JenisImporMaster::Ruang, [
        PenjajarRuang::judul(),
        barisRuang('R-09', 'Ruang 09', '', '40', '40', 'perbaikan'),
    ]);

    expect($batch->pratinjau_json['sah'])->toBe(1);
});

it('menerima nama kolom sinonim', function () {
    // Berkas yang disiapkan orang lain memakai "kode_mapel", bukan "kode".
    // Menolaknya berarti menolak berkas yang isinya benar.
    $batch = unggahMaster($this, JenisImporMaster::Mapel, [
        ['kode_mapel', 'nama_mapel', 'kategori', 'jenjang', 'status'],
        ['MTK', 'Matematika', 'Wajib', 'X', 'aktif'],
    ]);

    expect($batch->pratinjau_json['sah'])->toBe(1);
});

/* ============================================================ Kewenangan */

it('menolak Guru di laman impor mapel', function () {
    masukMaster($this, $this->guru);

    $this->get('/admin/impor/mapel')->assertForbidden();
});

it('menolak Guru di laman impor ruang', function () {
    masukMaster($this, $this->guru);

    $this->get('/admin/impor/ruang')->assertForbidden();
});

it('menolak Guru saat mengunggah', function () {
    masukMaster($this, $this->guru);

    $this->post('/admin/impor/mapel/unggah', [
        'berkas' => berkasMaster([PenjajarMapel::judul(), barisMapel()]),
    ])->assertForbidden();

    expect(Subject::tanpaTenant()->count())->toBe(0);
});

it('menolak tamu yang belum masuk', function () {
    app(SchoolResolver::class)->forget();

    $this->get('/admin/impor/mapel')->assertRedirect();
});

/* ============================================================= Riwayat */

it('menampilkan riwayat hanya untuk jenisnya sendiri', function () {
    unggahMaster($this, JenisImporMaster::Mapel, [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika'),
    ], nama: 'mapel-riwayat.csv');

    unggahMaster($this, JenisImporMaster::Ruang, [
        PenjajarRuang::judul(),
        barisRuang('R-01'),
    ], nama: 'ruang-riwayat.csv');

    masukMaster($this, $this->admin);

    $this->get('/admin/impor/mapel')
        ->assertInertia(fn ($halaman) => $halaman
            ->has('riwayat', 1)
            ->where('riwayat.0.nama_asli', 'mapel-riwayat.csv')
        );
});

it('membuat riwayat impor master bercatat di log aktivitas', function () {
    $batch = unggahMaster($this, JenisImporMaster::Mapel, [
        PenjajarMapel::judul(),
        barisMapel('MTK', 'Matematika'),
    ]);

    konfirmasiMaster($this, $batch);

    $this->assertDatabaseHas('activity_logs', [
        'school_id' => $this->sekolah->id,
        'action' => 'Mengimpor Mata pelajaran dari berkas',
    ]);
});

/* ================================================================= Kelas */

/**
 * =====================================================================
 * Apa yang membuat kelas berbeda dari dua importer sebelumnya
 * =====================================================================
 * Tabel `classes` tidak punya kolom `code` - yang unik di sana
 * `['school_id','academic_year_id','name']`. Dua akibatnya menentukan, dan
 * keduanya diuji di bawah:
 *
 *   1. Barisnya dicocokkan lewat NAMA, bukan kode. `kode` tidak ada di
 *      berkasnya sama sekali.
 *
 *   2. Nama itu unik PER TAHUN AJARAN, sehingga berkas kelas selalu butuh satu
 *      tahun ajaran - dan tahun itu datang dari LAMAN, bukan dari berkasnya.
 *      "X-1" milik 2026/2027 dan "X-1" milik 2027/2028 adalah dua kelas yang
 *      berbeda, dan impor ke tahun baru tidak boleh menyentuh kelas tahun lama.
 */
function unggahKelas(
    TestCase $test,
    array $baris,
    ?AcademicYear $tahun,
    ?User $admin = null,
    string $nama = 'kelas.csv',
): MasterImportBatch {
    masukMaster($test, $admin ?? $test->admin);

    $test->post('/admin/impor/kelas/unggah', array_filter([
        'berkas' => berkasMaster($baris, $nama),
        'tahun_ajaran_id' => $tahun?->id,
    ], fn ($nilai) => $nilai !== null))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    return MasterImportBatch::query()->latest('id')->firstOrFail();
}

/** Satu baris kelas yang sah. @return array<int, string> */
function barisKelas(
    string $nama = 'X-1',
    string $tingkat = 'X',
    string $status = 'aktif',
): array {
    return [$nama, $tingkat, $status];
}

it('menampilkan laman impor kelas dengan tahun ajarannya', function () {
    $tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->create(['name' => '2026/2027']);

    masukMaster($this, $this->admin);

    $this->get('/admin/impor/kelas')
        ->assertOk()
        ->assertInertia(fn ($halaman) => $halaman
            ->component('Admin/MasterImpor')
            ->where('jenis', 'kelas')
            ->where('kembali', '/admin/kelas?ta='.$tahun->id)
            ->where('kolom', PenjajarKelas::judul())
            ->where('kolom_kunci', 'nama')
            ->where('label_kunci', 'Kelas')
            ->where('butuh_tahun', true)
            ->where('tahun.id', $tahun->id)
        );
});

it('menampilkan laman impor MAPEL tanpa tahun ajaran sama sekali', function () {
    AcademicYear::factory()->untukSekolah($this->sekolah)->create();

    masukMaster($this, $this->admin);

    // Mapel tidak terikat tahun ajaran, dan menyodorkan pemilih tahun di
    // lamannya berarti menawarkan pilihan yang tidak mengubah apa pun.
    $this->get('/admin/impor/mapel')
        ->assertOk()
        ->assertInertia(fn ($halaman) => $halaman
            ->where('tahun', null)
            ->where('butuh_tahun', false)
            ->where('daftar_tahun', [])
        );
});

it('mengunduh template kelas yang kolomnya sama dengan yang dibaca importer', function () {
    masukMaster($this, $this->admin);

    $respons = $this->get('/admin/impor/kelas/template')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    expect($respons->headers->get('Content-Disposition'))->toContain('template-impor-kelas.csv');

    $baris = explode("\n", trim(str_replace("\xEF\xBB\xBF", '', $respons->getContent())));

    expect(str_getcsv($baris[0], ';', '"', '\\'))->toBe(PenjajarKelas::judul());
});

it('membuat kelas baru dari berkas', function () {
    $tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->create();

    $batch = unggahKelas($this, [
        PenjajarKelas::judul(),
        barisKelas('X-1', 'X'),
        barisKelas('X-2', '', 'nonaktif'),
    ], $tahun);

    konfirmasiMaster($this, $batch);

    $x1 = SchoolClass::tanpaTenant()->where('name', 'X-1')->firstOrFail();
    $x2 = SchoolClass::tanpaTenant()->where('name', 'X-2')->firstOrFail();

    expect($x1->grade_level)->toBe('X')
        ->and($x1->status)->toBe('aktif')
        // Sel kosong berarti "biarkan seperti adanya" - dan `status` yang
        // memang diisi tetap terpakai.
        ->and($x2->grade_level)->toBeNull()
        ->and($x2->status)->toBe('nonaktif')
        // Tahun ajarannya datang dari LAMAN, bukan dari berkasnya.
        ->and((int) $x1->academic_year_id)->toBe($tahun->id)
        ->and((int) $x2->academic_year_id)->toBe($tahun->id)
        ->and((int) $x1->school_id)->toBe($this->sekolah->id);
});

it('memperbarui kelas yang namanya sudah ada, bukan membuat yang kedua', function () {
    $tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->create();

    SchoolClass::factory()->untukTahun($tahun)->create([
        'name' => 'X-1',
        'grade_level' => 'X',
    ]);

    $batch = unggahKelas($this, [
        PenjajarKelas::judul(),
        barisKelas('X-1', 'X IPS'),
    ], $tahun);

    expect($batch->pratinjau_json['diperbarui'])->toBe(1)
        ->and($batch->pratinjau_json['baru'])->toBe(0);

    konfirmasiMaster($this, $batch);

    // Satu baris, bukan dua: itulah gunanya mencocokkan lewat nama.
    expect(SchoolClass::tanpaTenant()->where('name', 'X-1')->count())->toBe(1)
        ->and(SchoolClass::tanpaTenant()->where('name', 'X-1')->firstOrFail()->grade_level)
        ->toBe('X IPS');
});

it('memperbarui kelas meski huruf besar-kecilnya berbeda', function () {
    $tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->create();

    SchoolClass::factory()->untukTahun($tahun)->create([
        'name' => 'X-1',
    ]);

    // Kolomnya memakai kolasi `utf8mb4_unicode_ci`, sehingga 'x-1' dan 'X-1'
    // adalah nilai yang SAMA bagi unique index. Kalau perbandingannya persis,
    // baris ini dianggap baru - lolos pratinjau, lalu pecah pada unique
    // constraint saat Konfirmasi. Galat basis data mentah untuk baris yang
    // seharusnya menimpa.
    $batch = unggahKelas($this, [
        PenjajarKelas::judul(),
        barisKelas('x-1', 'X'),
    ], $tahun);

    expect($batch->pratinjau_json['diperbarui'])->toBe(1);

    konfirmasiMaster($this, $batch);

    expect(SchoolClass::tanpaTenant()->where('academic_year_id', $tahun->id)->count())->toBe(1);
});

it('menolak nama kelas yang sama dua kali di dalam satu berkas', function () {
    $tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->create();

    $batch = unggahKelas($this, [
        PenjajarKelas::judul(),
        barisKelas('X-1', 'X'),
        barisKelas('X-1', 'X IPA'),
    ], $tahun);

    expect($batch->pratinjau_json['sah'])->toBe(1)
        ->and($batch->pratinjau_json['gagal'])->toBe(1)
        ->and($batch->pratinjau_json['baris'][1]['kolom'])->toBe('nama')
        ->and($batch->pratinjau_json['baris'][1]['pesan'])->toContain('baris 2');
});

it('memperlakukan X-1 di tahun ajaran lain sebagai kelas yang berbeda', function () {
    $lama = AcademicYear::factory()->untukSekolah($this->sekolah)->create(['name' => '2026/2027']);
    $baru = AcademicYear::factory()->untukSekolah($this->sekolah)->create(['name' => '2027/2028']);

    SchoolClass::factory()->untukTahun($lama)->create([
        'name' => 'X-1',
        'grade_level' => 'X',
    ]);

    $batch = unggahKelas($this, [
        PenjajarKelas::judul(),
        barisKelas('X-1', 'X'),
    ], $baru);

    // "X-1" milik tahun lama TIDAK dianggap sudah ada di tahun baru. Kalau ia
    // dianggap ada, impornya akan melaporkan "akan memperbarui" lalu benar
    // -benar memperbarui kelas tahun LAMA - dan tahun yang dituju tetap kosong.
    expect($batch->pratinjau_json['baru'])->toBe(1)
        ->and($batch->pratinjau_json['diperbarui'])->toBe(0);

    konfirmasiMaster($this, $batch);

    expect(SchoolClass::tanpaTenant()->where('name', 'X-1')->count())->toBe(2)
        ->and(SchoolClass::tanpaTenant()->where('name', 'X-1')->where('academic_year_id', $baru->id)->count())
        ->toBe(1);
});

it('menolak berkas kelas tanpa tahun ajaran', function () {
    $tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->create();

    masukMaster($this, $this->admin);

    $this->post('/admin/impor/kelas/unggah', [
        'berkas' => berkasMaster([PenjajarKelas::judul(), barisKelas('X-1')]),
    ])->assertSessionHasErrors('tahun_ajaran_id');

    expect(MasterImportBatch::query()->count())->toBe(0)
        ->and(SchoolClass::tanpaTenant()->count())->toBe(0);

    // Tahun ajarannya memang ada - yang kurang adalah pilihannya, dan itu
    // yang membedakan pesan ini dari "sekolah belum punya tahun ajaran".
    expect($tahun->exists)->toBeTrue();
});

it('menolak tahun ajaran milik sekolah lain', function () {
    $tahunLain = AcademicYear::factory()->untukSekolah($this->lain)->create();

    masukMaster($this, $this->admin);

    // Scope `AcademicYear` yang memutuskan: id sekolah lain tidak ditemukan,
    // dan hasilnya 404 - bukan kelas yang lahir di sekolah lain.
    $this->post('/admin/impor/kelas/unggah', [
        'berkas' => berkasMaster([PenjajarKelas::judul(), barisKelas('X-1')]),
        'tahun_ajaran_id' => $tahunLain->id,
    ])->assertNotFound();

    expect(SchoolClass::tanpaTenant()->count())->toBe(0);
});

it('mengizinkan berkas kelas yang sama diunggah untuk tahun ajaran berbeda', function () {
    $lama = AcademicYear::factory()->untukSekolah($this->sekolah)->create(['name' => '2026/2027']);
    $baru = AcademicYear::factory()->untukSekolah($this->sekolah)->create(['name' => '2027/2028']);

    // Berkasnya IDENTIK, dan itu memang lazim: bentuk daftar kelas jarang
    // berubah antar tahun, yang berubah isinya. Menolaknya sebagai "sudah
    // pernah diunggah" berarti menolak impor yang sah.
    $isi = [PenjajarKelas::judul(), barisKelas('X-1'), barisKelas('X-2')];

    $pertama = unggahKelas($this, $isi, $lama, nama: 'kelas-sama.csv');
    konfirmasiMaster($this, $pertama);

    $kedua = unggahKelas($this, $isi, $baru, nama: 'kelas-sama.csv');

    expect($kedua->jenisImpor())->toBe(JenisImporMaster::Kelas)
        ->and((int) $kedua->academic_year_id)->toBe($baru->id);

    konfirmasiMaster($this, $kedua);

    // Dua kelas per nama, satu di tiap tahun ajaran.
    expect(SchoolClass::tanpaTenant()->where('name', 'X-1')->count())->toBe(2)
        ->and(SchoolClass::tanpaTenant()->where('academic_year_id', $baru->id)->count())->toBe(2);
});

it('masih menolak berkas kelas yang sama untuk tahun ajaran yang sama', function () {
    $tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->create();
    $isi = [PenjajarKelas::judul(), barisKelas('X-1')];

    unggahKelas($this, $isi, $tahun, nama: 'kelas-ulang.csv');

    masukMaster($this, $this->admin);

    $this->post('/admin/impor/kelas/unggah', [
        'berkas' => berkasMaster($isi, 'kelas-ulang.csv'),
        'tahun_ajaran_id' => $tahun->id,
    ])->assertSessionHasErrors('berkas');

    expect(MasterImportBatch::query()->count())->toBe(1);
});

it('menolak nama kelas yang lebih dari 40 huruf, karena laman Kelas pun menolaknya', function () {
    $tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->create();

    // `SchoolClassController::store()` membatasi `name` 40 huruf. Satu batas
    // bersama dengan mapel (255) akan meloloskan nama ini - lalu Admin tidak
    // bisa menyuntingnya lagi di layar, karena simpanannya ditolak.
    $batch = unggahKelas($this, [
        PenjajarKelas::judul(),
        barisKelas(str_repeat('X', 41)),
    ], $tahun);

    expect($batch->pratinjau_json['gagal'])->toBe(1)
        ->and($batch->pratinjau_json['baris'][0]['kolom'])->toBe('nama');
});

it('menerima nama kolom sinonim untuk kelas', function () {
    $tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->create();

    // "Kelas" dan "Tingkat" adalah judul kolom yang lazim dipakai orang, dan
    // berkas yang memakainya adalah berkas yang isinya benar.
    $batch = unggahKelas($this, [
        ['Kelas', 'Tingkat', 'Status'],
        ['X-1', 'X', 'aktif'],
    ], $tahun);

    expect($batch->pratinjau_json['sah'])->toBe(1);
});

it('menolak status perbaikan untuk kelas, karena itu status ruang', function () {
    $tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->create();

    $batch = unggahKelas($this, [
        PenjajarKelas::judul(),
        barisKelas('X-1', 'X', 'perbaikan'),
    ], $tahun);

    expect($batch->pratinjau_json['gagal'])->toBe(1)
        ->and($batch->pratinjau_json['baris'][0]['kolom'])->toBe('status');
});

it('menolak berkas kelas yang dipratinjau tanpa tahun ajaran di barisnya', function () {
    // Baris batch bisa bertahan lebih lama daripada bentuk laman yang
    // membuatnya. Pemeriksaannya ada di service, bukan hanya di controller,
    // supaya baris lama tidak bisa menulis kelas tanpa tahun ajaran.
    $batch = MasterImportBatch::factory()
        ->kelas()
        ->untukSekolah($this->sekolah)
        ->create(['user_id' => $this->admin->id, 'academic_year_id' => null]);

    Storage::disk('local')->put($batch->file_path, "nama;tingkat;status\r\nX-1;X;aktif\r\n");

    expect(fn () => app(ImporMasterService::class)->pratinjau($batch))
        ->toThrow(ValidationException::class);

    expect($batch->fresh()->statusImpor()->value)->toBe('gagal');
});

it('menulis kolom kunci yang benar di laporan galat kelas', function () {
    $tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->create();

    $batch = unggahKelas($this, [
        PenjajarKelas::judul(),
        barisKelas('', 'X'),
    ], $tahun);

    konfirmasiMaster($this, $batch);

    $laporan = Storage::disk('local')->get($batch->fresh()->error_report_path);

    // Judul kolomnya mengikuti berkas yang diunggah Admin, bukan nama bidang
    // di basis datanya - dan untuk kelas, kolom kuncinya memang `nama`.
    expect($laporan)->toContain('nama')
        ->and($laporan)->toContain('nama belum diisi');
});

it('menolak Guru di laman impor kelas', function () {
    AcademicYear::factory()->untukSekolah($this->sekolah)->create();

    masukMaster($this, $this->guru);

    $this->get('/admin/impor/kelas')->assertForbidden();
});

it('tidak menyentuh kelas tahun ajaran lain saat mengimpor', function () {
    $lama = AcademicYear::factory()->untukSekolah($this->sekolah)->create(['name' => '2026/2027']);
    $baru = AcademicYear::factory()->untukSekolah($this->sekolah)->create(['name' => '2027/2028']);

    $kelasLama = SchoolClass::factory()->untukTahun($lama)->create([
        'name' => 'X-9',
        'grade_level' => 'X',
    ]);

    $batch = unggahKelas($this, [
        PenjajarKelas::judul(),
        // Nama yang sama sekali berbeda - impor ke tahun baru tidak boleh
        // menyentuh apa pun di tahun lama.
        barisKelas('XI-1', 'XI'),
    ], $baru);

    konfirmasiMaster($this, $batch);

    $kelasLama->refresh();

    expect($kelasLama->grade_level)->toBe('X')
        ->and((int) $kelasLama->academic_year_id)->toBe($lama->id);
});
