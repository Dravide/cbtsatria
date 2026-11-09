<?php

use App\Domain\Admin\Import\PenjajarPengguna;
use App\Domain\Admin\Jobs\BuatEksporPengguna;
use App\Domain\Admin\Models\UserExport;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\TeacherProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Ekspor pengguna - PRD §5.7, §19.3.
 *
 * =====================================================================
 * Dua janji yang dijaga berkas ini
 * =====================================================================
 *
 *   1. PENYARINGNYA DIHORMATI. Admin yang menekan "Ekspor" di tab Guru
 *      harus menerima berkas berisi guru - bukan 1.284 siswa. Janji itu
 *      tinggal di satu tempat: `parameter_json` yang ditulis controller
 *      lalu dibaca job. Kalau keduanya berbeda nama kuncinya, berkasnya
 *      tetap terbit, tetap bisa diunduh, dan isinya salah - tanpa satu pun
 *      galat.
 *
 *   2. BERKASNYA LENGKAP. `chunkById(500)` adalah satu-satunya tempat di
 *      aplikasi ini yang berpaginasi dengan keyset, dan keyset yang salah
 *      urut MELOMPATI baris tanpa berbunyi. Karena itu ada test yang menulis
 *      600 akun - lebih dari satu chunk - dan menghitung ulang isinya.
 *
 * =====================================================================
 * `Queue::fake()` dipakai untuk melihat perbedaan yang `sync` sembunyikan
 * =====================================================================
 * `phpunit.xml` menetapkan `QUEUE_CONNECTION=sync`, sehingga sepanjang suite
 * ini "job yang diantre" dan "job yang dijalankan langsung" adalah hal yang
 * sama. Padahal PRD §19.3 menuntut yang pertama: ekspor 5.000 akun yang
 * dikerjakan di dalam permintaan HTTP berarti Admin menatap tombol berputar.
 * `Queue::fake()` menimpa `sync`, dan test pertama memeriksa tiga hal
 * sekaligus - job terkirim, berkasnya BELUM ada, barisnya masih `menunggu`.
 * Dua yang terakhir gagal bila pekerjaannya dikerjakan di dalam permintaan.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1', 'code' => 'smkn1']);
    $this->lain = School::factory()->create(['name' => 'SMK Negeri 2', 'code' => 'smkn2']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.ekspor',
        'name' => 'Admin Ekspor',
    ]);

    $this->adminLain = User::factory()->admin()->untukSekolah($this->lain)->create([
        'username' => 'admin.lain',
    ]);
});

/* ===========================================================================
 | Pembantu
 =========================================================================== */

/** Masuk sebagai pengguna, dengan resolver tenant yang direset. */
function masukEksporPengguna(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/** Satu siswa lengkap dengan profilnya. */
function siswaEkspor(School $sekolah, string $nama, string $nis, array $atribut = []): User
{
    $user = User::factory()->siswa()->untukSekolah($sekolah)->create(array_merge([
        'name' => $nama,
        'username' => 'u'.$nis,
    ], $atribut));

    StudentProfile::create([
        'school_id' => $sekolah->id,
        'user_id' => $user->id,
        'nis' => $nis,
        'nisn' => '00'.$nis,
        'gender' => 'P',
        'address' => 'Jl. Melati No. 3',
    ]);

    return $user;
}

/** Satu guru lengkap dengan profilnya. */
function guruEkspor(School $sekolah, string $nama, string $nip): User
{
    $user = User::factory()->guru()->untukSekolah($sekolah)->create([
        'name' => $nama,
        'username' => 'g'.$nip,
    ]);

    TeacherProfile::create([
        'school_id' => $sekolah->id,
        'user_id' => $user->id,
        'nip' => $nip,
        'staff_code' => 'PG-'.$nip,
        'gender' => 'L',
    ]);

    return $user;
}

/** Minta satu ekspor lewat rute yang sama dengan produksi. */
function mintaEksporPengguna(
    TestCase $test,
    array $muatan,
    ?User $admin = null,
): UserExport {
    masukEksporPengguna($test, $admin ?? $test->admin)
        ->post('/admin/pengguna/ekspor', $muatan)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    return UserExport::query()->withoutGlobalScopes()->orderByDesc('id')->firstOrFail();
}

/** Isi berkas CSV sebagai matriks, tanpa BOM dan tanpa baris judulnya. */
function bacaCsvEkspor(string $path): array
{
    $isi = Storage::disk('local')->get($path);

    // BOM dibuang SEBELUM dipecah: kalau tidak, judul kolom pertama terbaca
    // sebagai "\u{FEFF}username" dan pembandingannya gagal karena sebab yang
    // tidak terlihat di layar.
    $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi);

    $baris = array_map(
        fn (string $satu) => str_getcsv($satu, ';', '"', '\\'),
        preg_split('/\r\n|\n/', trim($isi)),
    );

    return $baris;
}

/* ===========================================================================
 | 1. Antrean - yang membedakan `sync` dari produksi
 =========================================================================== */

it('melempar pekerjaan ekspor ke antrean, bukan mengerjakannya dalam permintaan', function () {
    Queue::fake();

    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');

    $ekspor = mintaEksporPengguna($this, ['format' => 'csv', 'role' => 'student']);

    // Job-nya menunjuk baris INI. `assertPushed` tanpa pemeriksaan id akan
    // lulus juga untuk job yang menunjuk baris salah - dan job seperti itu
    // berhenti diam-diam lalu meninggalkan ekspor berstatus `menunggu`
    // selamanya.
    Queue::assertPushed(
        BuatEksporPengguna::class,
        fn (BuatEksporPengguna $job) => $job->eksporId === $ekspor->id
            && UserExport::query()->withoutGlobalScopes()->find($job->eksporId) !== null,
    );

    // Dua pemeriksaan yang gagal bila pekerjaannya justru dikerjakan di dalam
    // permintaan: berkasnya belum ada, dan barisnya masih menunggu.
    expect($ekspor->status)->toBe(UserExport::STATUS_MENUNGGU)
        ->and($ekspor->path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('mengubah barisnya menjadi selesai begitu jobnya benar-benar dijalankan', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');

    // Tanpa `Queue::fake()`: antreannya `sync`, jadi jobnya berjalan di sini.
    $ekspor = mintaEksporPengguna($this, ['format' => 'csv', 'role' => 'student']);

    expect($ekspor->fresh()->status)->toBe(UserExport::STATUS_SELESAI)
        ->and($ekspor->fresh()->path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($ekspor->path))->toBeTrue();
});

it('menyimpan berkasnya di disk privat dengan nama acak', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');

    $ekspor = mintaEksporPengguna($this, ['format' => 'csv', 'role' => 'student']);

    // Prefiks `pengguna/` memisahkannya dari `laporan/` Fase 8, sehingga
    // pemangkasan yang satu tidak menyentuh berkas yang lain.
    expect($ekspor->path)->toStartWith('pengguna/'.$this->sekolah->id.'/')
        // Dua puluh karakter acak di DEPAN nama nyaman: berkas di server
        // tidak bisa ditebak, berkas yang sudah diunduh tetap bernama jelas.
        ->and($ekspor->nama_berkas)->toMatch('/^[a-zA-Z0-9]{20}-pengguna-student\.csv$/');
});

/* ===========================================================================
 | 2. Kolom - satu sumber kebenaran dengan template impor
 =========================================================================== */

it('menulis kolom yang sama dengan template impor, ditambah username', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');

    $ekspor = mintaEksporPengguna($this, ['format' => 'csv', 'role' => 'student']);

    $baris = bacaCsvEkspor($ekspor->path);

    expect($baris[0])->toBe(PenjajarPengguna::judulEkspor())
        ->and($baris[0][0])->toBe('username')
        ->and($baris[0])->toContain('peran', 'nama', 'nis', 'kelas', 'aktif');
});

it('memuat username dan nomor identitas akun pada barisnya', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');

    $ekspor = mintaEksporPengguna($this, ['format' => 'csv', 'role' => 'student']);

    $baris = bacaCsvEkspor($ekspor->path);
    $kepala = array_flip($baris[0]);
    $data = $baris[1];

    expect($data[$kepala['username']])->toBe('u2024001')
        ->and($data[$kepala['nama']])->toBe('Ani Mulyani')
        ->and($data[$kepala['nis']])->toBe('2024001')
        ->and($data[$kepala['peran']])->toBe('student');
});

it('mengosongkan kolom kelas, karena kelas milik tahun ajaran bukan akun', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');

    $ekspor = mintaEksporPengguna($this, ['format' => 'csv', 'role' => 'student']);

    $baris = bacaCsvEkspor($ekspor->path);
    $kepala = array_flip($baris[0]);

    // Menuliskan kelas tahun ini ke berkas yang akan diimpor tahun depan
    // adalah cara paling halus memindahkan seluruh siswa ke kelas yang salah.
    expect($baris[1][$kepala['kelas']])->toBe('');
});

it('menulis berkas CSV dengan BOM dan pemisah titik koma', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');

    $ekspor = mintaEksporPengguna($this, ['format' => 'csv', 'role' => 'student']);

    $mentah = Storage::disk('local')->get($ekspor->path);

    // BOM: tanpa itu Excel berbahasa Indonesia membaca huruf beraksen sebagai
    // karakter rusak, dan berkasnya terlihat seperti gagal dibuat.
    expect(substr($mentah, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($mentah)->toContain('username;peran;nama');
});

it('menulis berkas XLSX yang bisa dibuka kembali', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');

    $ekspor = mintaEksporPengguna($this, ['format' => 'xlsx', 'role' => 'student']);

    $isi = Storage::disk('local')->get($ekspor->path);

    // XLSX adalah arsip ZIP; empat byte pertama adalah tanda tangannya. Ini
    // pemeriksaan yang murah dan menangkap kegagalan yang paling mungkin:
    // yang tersimpan ternyata berkas sementara yang belum jadi.
    expect(substr($isi, 0, 4))->toBe("PK\x03\x04");

    // Isinya dibaca ulang lewat pembaca yang sama dengan impor, sehingga
    // berkasnya benar-benar terbukti bisa dipakai ulang - bukan sekadar
    // "punya tanda tangan ZIP".
    $jalur = tempnam(sys_get_temp_dir(), 'uji-ekspor-').'.xlsx';
    file_put_contents($jalur, $isi);

    $terbaca = IOFactory::load($jalur)->getActiveSheet()->toArray();
    @unlink($jalur);

    expect($terbaca[0])->toBe(PenjajarPengguna::judulEkspor())
        ->and($terbaca[1])->toContain('Ani Mulyani')
        ->and($terbaca[1])->toContain('2024001');
});

/* ===========================================================================
 | 3. Penyaring - janji yang paling mudah dilanggar diam-diam
 =========================================================================== */

it('hanya mengekspor peran yang diminta', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');
    guruEkspor($this->sekolah, 'Budi Santoso', '1987654321');

    $ekspor = mintaEksporPengguna($this, ['format' => 'csv', 'role' => 'teacher']);

    $baris = bacaCsvEkspor($ekspor->path);
    $kepala = array_flip($baris[0]);

    // Baris judul + satu baris data. Kalau penyaringnya tidak bekerja,
    // berkasnya memuat siswa juga - dan Admin yang mengekspor tab Guru
    // menerima daftar seluruh sekolah tanpa menyadarinya.
    expect($baris)->toHaveCount(2)
        ->and($baris[1][$kepala['nama']])->toBe('Budi Santoso')
        ->and($baris[1][$kepala['peran']])->toBe('teacher');
});

it('menghormati penyaring kata kunci', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');
    siswaEkspor($this->sekolah, 'Budi Santoso', '2024002');

    $ekspor = mintaEksporPengguna($this, ['format' => 'csv', 'role' => 'student', 'cari' => 'Budi']);

    $baris = bacaCsvEkspor($ekspor->path);
    $kepala = array_flip($baris[0]);

    expect($baris)->toHaveCount(2)
        ->and($baris[1][$kepala['nama']])->toBe('Budi Santoso');
});

it('menghormati penyaring status aktif', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');
    siswaEkspor($this->sekolah, 'Budi Santoso', '2024002', ['is_active' => false]);

    $ekspor = mintaEksporPengguna($this, ['format' => 'csv', 'role' => 'student', 'status' => '1']);

    $baris = bacaCsvEkspor($ekspor->path);
    $kepala = array_flip($baris[0]);

    expect($baris)->toHaveCount(2)
        ->and($baris[1][$kepala['nama']])->toBe('Ani Mulyani')
        // Kolom `aktif` ditulis "ya"/"tidak", bukan 1/0: yang membacanya
        // adalah Admin di Excel, dan yang diimpornya kembali adalah kata.
        ->and($baris[1][$kepala['aktif']])->toBe('ya');
});

it('tidak pernah menyentuh akun sekolah lain', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');
    siswaEkspor($this->lain, 'Orang Lain', '2024999');

    $ekspor = mintaEksporPengguna($this, ['format' => 'csv', 'role' => 'student']);

    $baris = bacaCsvEkspor($ekspor->path);

    expect($baris)->toHaveCount(2)
        ->and(implode("\n", array_map(fn (array $b) => implode(';', $b), $baris)))
        ->not->toContain('Orang Lain');
});

/* ===========================================================================
 | 4. Kelengkapan berkas - jebakan keyset `chunkById`
 =========================================================================== */

it('memuat seluruh akun meski jumlahnya melewati satu chunk', function () {
    // 600 > 500, sehingga pembacaannya memerlukan DUA halaman. Inilah satu-
    // satunya tempat di aplikasi ini yang berpaginasi dengan keyset
    // (`chunkById`), dan keyset yang diurutkan menurut kolom lain - misalnya
    // nama - akan MELOMPATI baris tanpa satu pun galat.
    foreach (range(1, 600) as $i) {
        siswaEkspor($this->sekolah, 'Siswa '.str_pad((string) $i, 4, '0', STR_PAD_LEFT), '2024'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
    }

    $ekspor = mintaEksporPengguna($this, ['format' => 'csv', 'role' => 'student']);

    $baris = bacaCsvEkspor($ekspor->path);

    // 600 baris data + 1 baris judul.
    expect($baris)->toHaveCount(601)
        ->and($ekspor->fresh()->total_baris)->toBe(600);

    // Dihitung ulang dari isinya, bukan hanya dari `total_baris`: kolom itu
    // ditulis oleh kode yang sama yang menulis barisnya, sehingga ia setuju
    // dengan dirinya sendiri meski keduanya salah.
    $nis = array_map(fn (array $b) => $b[3], array_slice($baris, 1));
    expect(array_unique($nis))->toHaveCount(600);
});

/* ===========================================================================
 | 5. Kegagalan - yang membedakan "gagal" dari "menunggu selamanya"
 =========================================================================== */

it('mencatat gagal, bukan menggantung menunggu, bila berkasnya tidak bisa ditulis', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');

    $ekspor = new UserExport;
    $ekspor->forceFill([
        'school_id' => $this->sekolah->id,
        'requested_by' => $this->admin->id,
        'format' => 'csv',
        'parameter_json' => ['role' => 'student', 'cari' => '', 'status' => ''],
        'status' => UserExport::STATUS_MENUNGGU,
        'created_at' => now(),
    ])->save();

    // Disk yang selalu menolak menulis. Job yang melempar akan membiarkan
    // barisnya `diproses` selamanya dan Admin melihat "Diproses" tanpa akhir,
    // jadi yang diuji di sini adalah TANGKAPANNYA, bukan penulisannya.
    Storage::shouldReceive('disk')->andThrow(new RuntimeException('Disk penuh'));

    (new BuatEksporPengguna($ekspor->id))->handle();

    $ekspor->refresh();

    expect($ekspor->status)->toBe(UserExport::STATUS_GAGAL)
        ->and($ekspor->pesan_gagal)->toContain('Disk penuh')
        ->and($ekspor->finished_at)->not->toBeNull()
        ->and($ekspor->path)->toBeNull();
});

it('memotong pesan gagalnya, tidak menyimpannya sepanjang apa pun', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');

    $ekspor = new UserExport;
    $ekspor->forceFill([
        'school_id' => $this->sekolah->id,
        'requested_by' => $this->admin->id,
        'format' => 'csv',
        'parameter_json' => ['role' => 'student'],
        'status' => UserExport::STATUS_MENUNGGU,
        'created_at' => now(),
    ])->save();

    // Kolomnya `text`, dan pesan galat MySQL bisa memuat seluruh kueri.
    Storage::shouldReceive('disk')->andThrow(new RuntimeException(str_repeat('a', 5000)));

    (new BuatEksporPengguna($ekspor->id))->handle();

    expect(mb_strlen($ekspor->fresh()->pesan_gagal))->toBe(500);
});

it('berhenti diam bila barisnya sudah tidak ada, bukan melempar', function () {
    // Baris yang dihapus pemangkas masih bisa punya job tertinggal di
    // antrean. Job yang melempar akan masuk `failed_jobs` dan memanggil
    // `failed()` - yang menulis ke baris yang sudah tidak ada.
    (new BuatEksporPengguna(999999))->handle();

    expect(true)->toBeTrue();
});

it('tidak menimpa berkas ekspor yang sudah selesai bila jobnya terkirim dua kali', function () {
    $ekspor = new UserExport;
    $ekspor->forceFill([
        'school_id' => $this->sekolah->id,
        'requested_by' => $this->admin->id,
        'format' => 'csv',
        'parameter_json' => ['role' => 'student'],
        'status' => UserExport::STATUS_SELESAI,
        'nama_berkas' => 'sudah-ada.csv',
        'path' => 'pengguna/'.$this->sekolah->id.'/sudah-ada.csv',
        'created_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
    ])->save();

    Storage::disk('local')->put($ekspor->path, 'isi lama');

    // Antrean tidak menjanjikan sekali kirim; `tries = 1` hanya membatasi
    // percobaan ULANG. Pemeriksaan status di awal `handle()` yang membuat
    // kiriman kedua tidak menimpa berkas yang mungkin sedang diunduh Admin.
    (new BuatEksporPengguna($ekspor->id))->handle();

    expect(Storage::disk('local')->get($ekspor->path))->toBe('isi lama')
        ->and($ekspor->fresh()->nama_berkas)->toBe('sudah-ada.csv');
});

/* ===========================================================================
 | 6. Unduh
 =========================================================================== */

it('mengunduh berkas yang sudah selesai, dengan nama yang bisa dibaca', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');

    $ekspor = mintaEksporPengguna($this, ['format' => 'csv', 'role' => 'student']);

    $unduh = masukEksporPengguna($this, $this->admin)
        ->get("/admin/pengguna/ekspor/{$ekspor->id}/unduh");

    $unduh->assertOk();

    // Nama unduhan membuang dua puluh karakter acak di depan; nama acak itu
    // ada untuk berkas di server, bukan untuk folder unduhan Admin.
    expect($unduh->headers->get('content-disposition'))
        ->toContain('pengguna-student.csv')
        ->not->toContain($ekspor->nama_berkas);
});

it('menolak unduhan sebelum berkasnya selesai', function () {
    $ekspor = UserExport::factory()->untukSekolah($this->sekolah)->create([
        'requested_by' => $this->admin->id,
        'status' => UserExport::STATUS_MENUNGGU,
    ]);

    masukEksporPengguna($this, $this->admin)
        ->get("/admin/pengguna/ekspor/{$ekspor->id}/unduh")
        ->assertNotFound();
});

it('menjawab 404 bila berkasnya sudah dipangkas dari disk', function () {
    // Barisnya `selesai` tetapi berkasnya sudah dihapus `pengguna:pangkas-
    // ekspor`. Tautan yang hidup menuju 404 di sini benar: bagi Admin yang
    // menekannya, berkasnya memang tidak ada lagi.
    $ekspor = UserExport::factory()->untukSekolah($this->sekolah)->selesai()->create([
        'requested_by' => $this->admin->id,
    ]);

    masukEksporPengguna($this, $this->admin)
        ->get("/admin/pengguna/ekspor/{$ekspor->id}/unduh")
        ->assertNotFound();
});

it('tidak membiarkan Admin sekolah lain mengunduh, dan menjawab 404 bukan 403', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');

    $ekspor = mintaEksporPengguna($this, ['format' => 'csv', 'role' => 'student']);

    // 404, bukan 403: bagi sekolah lain barisnya memang tidak ada, dan 403
    // akan membuktikan bahwa ia ada.
    masukEksporPengguna($this, $this->adminLain)
        ->get("/admin/pengguna/ekspor/{$ekspor->id}/unduh")
        ->assertNotFound();
});

it('menolak Guru yang meminta ekspor', function () {
    $guru = guruEkspor($this->sekolah, 'Budi Santoso', '1987654321');

    masukEksporPengguna($this, $guru)
        ->post('/admin/pengguna/ekspor', ['format' => 'csv', 'role' => 'student'])
        ->assertForbidden();

    expect(UserExport::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('menolak format yang tidak ditawarkan', function () {
    masukEksporPengguna($this, $this->admin)
        ->post('/admin/pengguna/ekspor', ['format' => 'pdf', 'role' => 'student'])
        ->assertSessionHasErrors('format');

    // PDF sengaja tidak ada: ia artefak cetak yang tidak bisa diimpor
    // kembali, dan justru "bisa diimpor kembali" itu gunanya ekspor ini.
    expect(UserExport::query()->withoutGlobalScopes()->count())->toBe(0);
});

/* ===========================================================================
 | 7. Riwayat di laman Pengguna
 =========================================================================== */

it('mengirim riwayat ekspor sebagai prop laman Pengguna', function () {
    siswaEkspor($this->sekolah, 'Ani Mulyani', '2024001');

    mintaEksporPengguna($this, ['format' => 'csv', 'role' => 'student']);

    $tanggapan = masukEksporPengguna($this, $this->admin)->get('/admin/pengguna?tab=student');

    $tanggapan->assertOk()->assertInertia(fn ($halaman) => $halaman
        ->component('Admin/Pengguna')
        ->has('ekspor', 1)
        ->where('ekspor.0.status', UserExport::STATUS_SELESAI)
        // `status_nada` dihitung di model, bukan di React: `nadaStatus()` di
        // `ui.tsx` tidak mengenal `menunggu`/`diproses`.
        ->where('ekspor.0.status_nada', 'ok')
        ->where('ekspor.0.format', 'CSV')
        ->has('format', 2)
        ->where('format.0', 'xlsx'));
});

it('hanya mengirim lima ekspor terakhir', function () {
    for ($i = 0; $i < 7; $i++) {
        UserExport::factory()->untukSekolah($this->sekolah)->create([
            'requested_by' => $this->admin->id,
            // Diurutkan menjauh supaya urutannya tidak bergantung pada
            // resolusi jam.
            'created_at' => now()->subMinutes(10 - $i),
        ]);
    }

    masukEksporPengguna($this, $this->admin)
        ->get('/admin/pengguna')
        ->assertOk()
        ->assertInertia(fn ($halaman) => $halaman->has('ekspor', 5));
});

it('tidak mengirim ekspor milik sekolah lain di daftar riwayat', function () {
    UserExport::factory()->untukSekolah($this->lain)->create([
        'requested_by' => $this->adminLain->id,
    ]);

    masukEksporPengguna($this, $this->admin)
        ->get('/admin/pengguna')
        ->assertOk()
        ->assertInertia(fn ($halaman) => $halaman->has('ekspor', 0));
});

/* ===========================================================================
 | 8. Pemangkasan
 =========================================================================== */

it('memangkas berkasnya lebih dulu, lalu barisnya', function () {
    $ekspor = UserExport::factory()->untukSekolah($this->sekolah)->selesai()->create([
        'requested_by' => $this->admin->id,
        'created_at' => now()->subDays(40),
    ]);

    Storage::disk('local')->put($ekspor->path, 'isi lama');

    $this->artisan('pengguna:pangkas-ekspor')->assertSuccessful();

    expect(Storage::disk('local')->exists($ekspor->path))->toBeFalse()
        ->and(UserExport::query()->withoutGlobalScopes()->find($ekspor->id))->toBeNull();
});

it('tidak memangkas ekspor yang masih menunggu atau diproses', function () {
    $menunggu = UserExport::factory()->untukSekolah($this->sekolah)->create([
        'requested_by' => $this->admin->id,
        'status' => UserExport::STATUS_MENUNGGU,
        'created_at' => now()->subDays(40),
    ]);

    // `diproses` = mungkin sedang menulis berkasnya SAAT perintah ini
    // berjalan. Memangkasnya akan menghapus baris di bawah job yang bekerja.
    $diproses = UserExport::factory()->untukSekolah($this->sekolah)->create([
        'requested_by' => $this->admin->id,
        'status' => UserExport::STATUS_DIPROSES,
        'created_at' => now()->subDays(40),
    ]);

    $this->artisan('pengguna:pangkas-ekspor')->assertSuccessful();

    expect(UserExport::query()->withoutGlobalScopes()->find($menunggu->id))->not->toBeNull()
        ->and(UserExport::query()->withoutGlobalScopes()->find($diproses->id))->not->toBeNull();
});

it('tidak menyentuh ekspor yang belum cukup tua', function () {
    $ekspor = UserExport::factory()->untukSekolah($this->sekolah)->selesai()->create([
        'requested_by' => $this->admin->id,
        'created_at' => now()->subDays(3),
    ]);

    Storage::disk('local')->put($ekspor->path, 'isi baru');

    $this->artisan('pengguna:pangkas-ekspor')->assertSuccessful();

    expect(Storage::disk('local')->exists($ekspor->path))->toBeTrue()
        ->and(UserExport::query()->withoutGlobalScopes()->find($ekspor->id))->not->toBeNull();
});

it('mode kering melaporkan tanpa menghapus apa pun', function () {
    $ekspor = UserExport::factory()->untukSekolah($this->sekolah)->selesai()->create([
        'requested_by' => $this->admin->id,
        'created_at' => now()->subDays(40),
    ]);

    Storage::disk('local')->put($ekspor->path, 'isi lama');

    $this->artisan('pengguna:pangkas-ekspor', ['--kering' => true])->assertSuccessful();

    expect(Storage::disk('local')->exists($ekspor->path))->toBeTrue()
        ->and(UserExport::query()->withoutGlobalScopes()->find($ekspor->id))->not->toBeNull();
});

it('tidak menyentuh berkas ekspor laporan', function () {
    // Prefiks `pengguna/` sengaja berbeda dari `laporan/`; test ini yang
    // menjaga supaya perintah yang satu tidak menyapu berkas yang lain.
    Storage::disk('local')->put('laporan/1/penting.csv', 'berkas laporan');

    UserExport::factory()->untukSekolah($this->sekolah)->selesai()->create([
        'requested_by' => $this->admin->id,
        'created_at' => now()->subDays(40),
    ]);

    $this->artisan('pengguna:pangkas-ekspor')->assertSuccessful();

    expect(Storage::disk('local')->exists('laporan/1/penting.csv'))->toBeTrue();
});
