<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Admin\Services\KartuAkunGuru;
use App\Domain\Identity\Models\TeacherProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;

/**
 * Kartu akun guru - `/admin/pengguna/kartu`, tab Guru.
 *
 * =====================================================================
 * Empat janji yang dijaga berkas ini
 * =====================================================================
 *
 *   1. SANDI HANYA UNTUK AKUN YANG MASIH MEMAKAI SANDI BAWAAN. Kartu yang
 *      mencetak "password" pada akun yang sandinya sudah diganti adalah
 *      kartu yang menuntun guru memakai sandi yang salah. Pemeriksaannya
 *      `Hash::check`, sumber yang sama dengan peringatan di laman Akun.
 *
 *   2. HANYA GURU. Id siswa yang dikirim ke alamat ini tidak boleh
 *      menghasilkan kartu apa pun - kartu ini memuat kredensial, dan
 *      saringannya tidak boleh bergantung pada tombol yang kebetulan
 *      disembunyikan React.
 *
 *   3. SATU ID -> PDF, BANYAK ID -> ZIP. Dua bentuk dari SATU alamat; yang
 *      membedakan berapa id yang dikirim, bukan jalur yang berbeda.
 *
 *   4. NAMA BERKAS DI DALAM ZIP ADALAH `nama_nomor.pdf`. Inilah yang dibaca
 *      Admin saat membagikan berkasnya satu per satu, dan akhiran angka
 *      menutup dua guru bernama sama yang nomornya juga kosong.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1', 'code' => 'smkn1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.kartu',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();

    $this->mapel = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'MTK',
        'name' => 'Matematika',
    ]);

    $this->kelas = SchoolClass::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'X-1',
    ]);
});

/**
 * Masuk sebagai pengguna, dengan resolver tenant yang direset.
 *
 * Parameternya tidak diberi tipe `TestCase`: Pest mengikat `$this` ke kelas
 * test yang dibangkitkan per berkas, bukan ke `Tests\TestCase` - dan
 * typehint yang lebih sempit menolaknya sebelum satu permintaan pun dibuat.
 */
function masukKartuAkun($test, User $user)
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/** Satu guru lengkap dengan profil dan penugasan. */
function guruKartu(School $sekolah, string $nama, string $nip, ?string $sandi = null): User
{
    $guru = User::factory()->guru()->untukSekolah($sekolah)->create([
        'name' => $nama,
        'username' => 'guru.'.strtolower(str_replace('.', '', $nip)),
        // `null` berarti sandi bawaan - factory sudah menulis 'password',
        // dan menimpanya dengan literal yang sama membuat testnya tidak
        // membuktikan apa pun tentang keadaan bawaan.
        ...($sandi === null ? [] : ['password' => $sandi]),
    ]);

    TeacherProfile::create([
        'school_id' => $sekolah->id,
        'user_id' => $guru->id,
        'nip' => $nip,
    ]);

    return $guru;
}

/* ===========================================================================
 | Data kartu
 =========================================================================== */

it('mengelompokkan mapel dan kelas per guru', function () {
    $guru = guruKartu($this->sekolah, 'Budi Santoso', '198704122010011002');

    $kelasLain = SchoolClass::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'X-2',
    ]);

    foreach ([$this->kelas, $kelasLain] as $kelas) {
        TeachingAssignment::create([
            'school_id' => $this->sekolah->id,
            'academic_year_id' => $this->tahun->id,
            'teacher_id' => $guru->id,
            'subject_id' => $this->mapel->id,
            'class_id' => $kelas->id,
        ]);
    }

    $kartu = app(KartuAkunGuru::class)->susun(collect([$guru->load('teacherProfile')]));

    expect($kartu)->toHaveCount(1)
        ->and($kartu[0]['nip'])->toBe('198704122010011002')
        ->and($kartu[0]['mapel'])->toHaveCount(1)
        ->and($kartu[0]['mapel'][0]['nama'])->toBe('Matematika')
        // Dua penugasan pada mapel yang sama menjadi SATU baris mapel -
        // kartu yang mengulang "Matematika" dua kali terbaca sebagai dua
        // mapel berbeda.
        ->and($kartu[0]['mapel'][0]['kelas'])->toBe(['X-1', 'X-2']);
});

it('tidak mencetak sandi pada guru yang sudah mengganti sandinya', function () {
    $baru = guruKartu($this->sekolah, 'Sari Dewi', '199002032015032001', 'sandi-rahasia');
    $bawaan = guruKartu($this->sekolah, 'Ana Wijaya', '199505172018012003');

    $kartu = collect(app(KartuAkunGuru::class)->susun(
        collect([$baru->load('teacherProfile'), $bawaan->load('teacherProfile')]),
    ))->keyBy('nama');

    expect($kartu['Sari Dewi']['sandi'])->toBeNull()
        ->and($kartu['Ana Wijaya']['sandi'])->toBe(User::SANDI_BAWAAN);
});

it('memberi nama berkas nama_nomor, dengan username sebagai cadangan', function () {
    $kartu = app(KartuAkunGuru::class);

    expect($kartu->namaBerkas(['nama' => 'Budi Santoso', 'nip' => '19870412', 'username' => 'budi']))
        ->toBe('budi-santoso_19870412.pdf')
        // Guru tanpa NIP mendapat username-nya, bukan `nama_.pdf`.
        ->and($kartu->namaBerkas(['nama' => 'Budi Santoso', 'nip' => null, 'username' => 'budi']))
        ->toBe('budi-santoso_budi.pdf')
        // Nama dan nomor yang sama persis tetap menghasilkan dua berkas -
        // salinan kedua menghapus yang pertama di dalam ZIP.
        ->and($kartu->namaBerkas(['nama' => 'Budi Santoso', 'nip' => null, 'username' => 'budi'], 2))
        ->toBe('budi-santoso_budi_2.pdf');
});

/* ===========================================================================
 | Unduhan
 =========================================================================== */

it('mengunduh satu kartu sebagai PDF dan mencatatnya', function () {
    $guru = guruKartu($this->sekolah, 'Budi Santoso', '19870412');

    $respon = masukKartuAkun($this, $this->admin)
        ->get("/admin/pengguna/kartu?guru[]={$guru->id}");

    $respon->assertOk();

    expect($respon->headers->get('content-type'))->toContain('application/pdf')
        ->and(substr($respon->getContent(), 0, 4))->toBe('%PDF');

    // Kartu memuat kredensial, jadi pengunduhannya dicatat.
    $this->assertDatabaseHas('activity_logs', [
        'school_id' => $this->sekolah->id,
        'action' => 'Mencetak kartu akun guru',
    ]);
});

it('membungkus banyak kartu sebagai ZIP berisi satu PDF per guru', function () {
    $budi = guruKartu($this->sekolah, 'Budi Santoso', '19870412');
    $sari = guruKartu($this->sekolah, 'Sari Dewi', '19900203');

    $respon = masukKartuAkun($this, $this->admin)
        ->get("/admin/pengguna/kartu?guru[]={$budi->id}&guru[]={$sari->id}");

    $respon->assertOk();

    $jalur = tempnam(sys_get_temp_dir(), 'uji-');
    file_put_contents($jalur, $respon->streamedContent());

    $zip = new ZipArchive;
    expect($zip->open($jalur))->toBeTrue();

    $isi = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nama = $zip->getNameIndex($i);
        $isi[$nama] = substr((string) $zip->getFromIndex($i), 0, 4);
    }

    $zip->close();
    @unlink($jalur);

    expect($isi)->toHaveCount(2)
        ->and($isi['budi-santoso_19870412.pdf'])->toBe('%PDF')
        ->and($isi['sari-dewi_19900203.pdf'])->toBe('%PDF');
});

it('mencetak seluruh guru pada penyaring yang sedang aktif', function () {
    guruKartu($this->sekolah, 'Budi Santoso', '19870412');
    guruKartu($this->sekolah, 'Budi Hartono', '19880303');
    guruKartu($this->sekolah, 'Sari Dewi', '19900203');

    $respon = masukKartuAkun($this, $this->admin)
        ->get('/admin/pengguna/kartu?semua=1&cari=budi');

    $respon->assertOk();

    $jalur = tempnam(sys_get_temp_dir(), 'uji-');
    file_put_contents($jalur, $respon->streamedContent());

    $zip = new ZipArchive;
    $zip->open($jalur);

    expect($zip->numFiles)->toBe(2);

    $zip->close();
    @unlink($jalur);
});

it('menolak id siswa - kartu ini memuat kredensial guru', function () {
    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create();

    masukKartuAkun($this, $this->admin)
        ->get("/admin/pengguna/kartu?guru[]={$siswa->id}")
        ->assertSessionHas('galat');
});

it('menolak guru sekolah lain', function () {
    $lain = School::factory()->create(['code' => 'smkn2']);
    $guruLain = User::factory()->guru()->untukSekolah($lain)->create();

    masukKartuAkun($this, $this->admin)
        ->get("/admin/pengguna/kartu?guru[]={$guruLain->id}")
        ->assertSessionHas('galat');
});

it('menolak pengguna yang bukan admin', function () {
    $guru = guruKartu($this->sekolah, 'Budi Santoso', '19870412');

    masukKartuAkun($this, $guru)
        ->get("/admin/pengguna/kartu?guru[]={$guru->id}")
        ->assertForbidden();
});
