<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\ClassEnrollment;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Admin\Enums\StatusImporPengguna;
use App\Domain\Admin\Import\PenjajarPengguna;
use App\Domain\Admin\Models\UserImportBatch;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\TeacherProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use App\Support\Impor\PembacaBerkas;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Impor pengguna - PRD §5.7, wireframe-admin.html plate 05.
 *
 * =====================================================================
 * Janji yang dijaga berkas ini
 * =====================================================================
 * PRD §5.7: Admin dapat menambah, mengubah, menonaktifkan, MENGIMPOR, dan
 * MENGEKSPOR data Guru, Siswa, dan Pengawas. Wireframe: impor memakai
 * template karena 1.284 akun tidak diketik satu per satu.
 *
 * Dua janji di bawah ini yang paling mudah dipenuhi secara teknis dan paling
 * mudah gagal secara nyata, sehingga keduanya diperiksa dari banyak sisi:
 *
 *   1. BERKAS YANG SALAH TIDAK MERUSAK AKUN YANG ADA. Mode "perbarui akun
 *      lama" berarti setiap unggahan menyentuh akun yang sudah dipakai orang.
 *      Username dan sandi tidak boleh berubah, dan sel kosong tidak boleh
 *      mengosongkan kolom yang sudah terisi.
 *
 *   2. NOMOR BARIS YANG DILAPORKAN ADALAH NOMOR YANG DILIHAT ADMIN DI EXCEL.
 *      Blueprint menuntutnya, dan importer yang melaporkan "baris 2 gagal"
 *      untuk galat di baris 7 lulus setiap pemeriksaan yang hanya
 *      menanyakan "apakah ada galat".
 *
 * Berkasnya dibangun di dalam test, bukan disimpan sebagai fixture: yang
 * menentukan di sini adalah BENTUK berkasnya, dan CSV yang ditulis terbuka
 * bisa dibaca di tempat.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->sekolah = School::factory()->create(['code' => 'smkn1']);
    $this->lain = School::factory()->create(['code' => 'smkn2']);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    $this->kelas = SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'X-1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.impor',
    ]);

    $this->adminLain = User::factory()->admin()->untukSekolah($this->lain)->create([
        'username' => 'admin.lain',
    ]);

    $this->guru = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'guru.impor',
    ]);
});

/** Login yang melewati cache resolver tenant. */
function masukImporPengguna(TestCase $test, User $user): TestCase
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
function berkasPengguna(array $baris, string $nama = 'pengguna.csv'): UploadedFile
{
    $isi = "\xEF\xBB\xBF";

    foreach ($baris as $satu) {
        $isi .= implode(';', array_map(fn (string $s) => '"'.str_replace('"', '""', $s).'"', $satu))."\r\n";
    }

    $jalur = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cbt-impor-pengguna-'.uniqid().'-'.$nama;

    file_put_contents($jalur, $isi);

    return new UploadedFile($jalur, $nama, 'text/csv', null, true);
}

/** Judul kolom sesuai template unduhan. @return array<int, string> */
function judulPengguna(): array
{
    return PenjajarPengguna::judul();
}

/** Satu baris siswa yang sah. @return array<int, string> */
function barisSiswa(
    string $nama = 'Ani Mulyani',
    string $nis = '2024001',
    string $kelas = 'X-1',
): array {
    return ['siswa', $nama, $nis, '', '', '', $kelas, '', '081234567890', 'P', 'Jl. Melati No. 3', 'ya'];
}

/** Satu baris guru yang sah. @return array<int, string> */
function barisGuru(string $nama = 'Budi Santoso', string $nip = '1987654321'): array
{
    return ['guru', $nama, '', '', $nip, '', '', 'budi@sekolah.sch.id', '081298765432', 'L', 'Jl. Kenanga No. 7', 'ya'];
}

/** Unggah + pratinjau, langsung dari controller. */
function unggahPengguna(TestCase $test, array $baris, ?User $admin = null, string $nama = 'pengguna.csv'): UserImportBatch
{
    masukImporPengguna($test, $admin ?? $test->admin);

    $test->post('/admin/pengguna/impor/unggah', ['berkas' => berkasPengguna($baris, $nama)])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    return UserImportBatch::query()->latest('id')->firstOrFail();
}

/** Konfirmasi batch yang sudah dipratinjau. */
function konfirmasiPengguna(TestCase $test, UserImportBatch $batch, ?User $admin = null): void
{
    masukImporPengguna($test, $admin ?? $test->admin);

    $test->post("/admin/pengguna/impor/{$batch->id}/konfirmasi")
        ->assertRedirect()
        ->assertSessionHasNoErrors();
}

/** Profil siswa untuk akun yang sudah ada. */
function profilSiswa(TestCase $test, User $user, ?string $nis, ?string $nisn = null, array $extra = []): void
{
    StudentProfile::create(array_merge([
        'school_id' => $test->sekolah->id,
        'user_id' => $user->id,
        'nis' => $nis,
        'nisn' => $nisn,
    ], $extra));
}

/* ======================================================== Halaman & rute */

it('membuka laman impor pengguna untuk Admin', function () {
    masukImporPengguna($this, $this->admin)
        ->get('/admin/pengguna/impor')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/PenggunaImpor')
            ->where('kolom', PenjajarPengguna::judul())
            ->has('riwayat')
        );
});

it('menolak Guru membuka laman impor dengan 403, bukan 404', function () {
    // 403, bukan 404: Guru memang tahu laman itu ada - yang kurang adalah
    // haknya. Berbeda dengan baris impor Admin lain, yang isinya tidak boleh
    // ia ketahui sama sekali.
    masukImporPengguna($this, $this->guru)
        ->get('/admin/pengguna/impor')
        ->assertForbidden();
});

it('mengunduh template sebagai CSV ber-BOM dengan tiga baris contoh', function () {
    $balasan = masukImporPengguna($this, $this->admin)->get('/admin/pengguna/impor/template');

    $balasan->assertOk();

    $isi = $balasan->getContent();

    expect($isi)->toStartWith("\xEF\xBB\xBF")
        ->and($isi)->toContain(implode(';', PenjajarPengguna::judul()))
        // Tiga contoh, satu per peran - itulah yang menjelaskan kolom mana
        // bermakna bagi siapa tanpa satu halaman penjelasan pun.
        ->and($isi)->toContain('siswa;')
        ->and($isi)->toContain('guru;')
        ->and($isi)->toContain('pengawas;');
});

it('mendahulukan rute template atas rute laporan ber-id', function () {
    // Jebakan urutan rute: `{batch}` akan mencoba mencocokkan "template", dan
    // Admin yang menekan "Unduh template" akan menerima 404 yang tidak
    // menjelaskan apa pun.
    $rute = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($r) => $r->uri())
        ->filter(fn (string $uri) => str_starts_with($uri, 'admin/pengguna/impor'))
        ->values();

    expect($rute->search('admin/pengguna/impor/template'))
        ->toBeLessThan($rute->search('admin/pengguna/impor/{batch}/laporan'));
});

/* ======================================================== Unggah berkas */

it('menyimpan berkasnya di disk privat dengan nama acak', function () {
    $batch = unggahPengguna($this, [judulPengguna(), barisSiswa()], nama: 'rahasia.csv');

    Storage::disk('local')->assertExists($batch->file_path);

    // Nama aslinya di kolomnya sendiri, nama di disk tidak berarti apa-apa:
    // berkas impor memuat nomor identitas dan nama seluruh siswa satu sekolah.
    expect($batch->nama_asli)->toBe('rahasia.csv')
        ->and($batch->file_path)->not->toContain('rahasia')
        ->and($batch->file_path)->toStartWith('impor-pengguna/'.$this->sekolah->id.'/');
});

it('menolak unggahan ulang berkas yang isinya sama', function () {
    $baris = [judulPengguna(), barisSiswa()];

    unggahPengguna($this, $baris);

    masukImporPengguna($this, $this->admin)
        ->post('/admin/pengguna/impor/unggah', ['berkas' => berkasPengguna($baris)])
        ->assertSessionHasErrors('berkas');

    expect(UserImportBatch::query()->count())->toBe(1);
});

it('menolak berkas dengan ekstensi yang tidak didukung', function () {
    masukImporPengguna($this, $this->admin)
        ->post('/admin/pengguna/impor/unggah', [
            'berkas' => UploadedFile::fake()->create('data.xls', 10),
        ])
        ->assertSessionHasErrors('berkas');
});

/* ========================================================= Kolom & judul */

it('menyebut kolom yang terbaca saat kolom wajibnya hilang', function () {
    // Pesan yang hanya menyebut kolom yang HILANG meninggalkan pertanyaan
    // yang tidak bisa dijawab Admin sendiri: apakah namanya salah tulis, atau
    // kolomnya memang tidak ada.
    masukImporPengguna($this, $this->admin)
        ->post('/admin/pengguna/impor/unggah', [
            'berkas' => berkasPengguna([['nama', 'nis'], ['Ani', '2024001']]),
        ])
        ->assertSessionHasErrors('berkas');

    $pesan = session('errors')->first('berkas');

    expect($pesan)->toContain('peran')
        ->and($pesan)->toContain('nama');
});

it('memahami sinonim nama kolom dan urutan yang bebas', function () {
    // Kolom diurutkan terbalik, dan namanya memakai istilah yang dipilih
    // separuh orang: "role", "nama_lengkap", "no_induk", "gender".
    $batch = unggahPengguna($this, [
        ['kelas', 'gender', 'no_induk', 'nama_lengkap', 'role'],
        ['X-1', 'P', '2024001', 'Ani Mulyani', 'siswa'],
    ]);

    expect($batch->statusImpor())->toBe(StatusImporPengguna::Dipratinjau)
        ->and($batch->created_rows)->toBe(1);
});

it('menemukan baris judul meski ada baris judul laporan di atasnya', function () {
    $batch = unggahPengguna($this, [
        ['Daftar siswa SMK Negeri 1', '', '', '', '', '', '', '', '', '', '', ''],
        judulPengguna(),
        barisSiswa(),
    ]);

    expect($batch->created_rows)->toBe(1);
});

/* ============================================================== Peran */

it('menolak peran admin dengan pesan yang menyebut sebabnya', function () {
    // Jalur eskalasi hak lewat spreadsheet. Pesannya harus menyebut SEBABNYA,
    // bukan sekadar "peran tidak dikenal" - Admin yang membacanya perlu tahu
    // bahwa penolakannya bukan salah tulis.
    $batch = unggahPengguna($this, [
        judulPengguna(),
        ['admin', 'Penyusup', '999', '', '', '', '', '', '', '', '', 'ya'],
    ]);

    expect($batch->pratinjau_json['sah'])->toBe(0)
        ->and($batch->pratinjau_json['gagal'])->toBe(1)
        ->and($batch->pratinjau_json['baris'][0]['kolom'])->toBe('peran')
        ->and($batch->pratinjau_json['baris'][0]['pesan'])->toContain('tidak bisa dibuat lewat impor');

    expect(User::query()->where('name', 'Penyusup')->exists())->toBeFalse();
});

it('menerima kosakata peran bahasa Inggris dan huruf besar', function () {
    $batch = unggahPengguna($this, [
        judulPengguna(),
        ['SISWA', 'Ani', '2024001', '', '', '', '', '', '', '', '', ''],
        ['teacher', 'Budi', '', '', '1987654321', '', '', '', '', '', '', ''],
        ['proctor', 'Citra', '', '', '', 'PGW-001', '', '', '', '', '', '', ''],
    ]);

    expect($batch->created_rows)->toBe(3);
});

/* ================================ Akun baru: username, sandi, hitungan */

it('menurunkan username akun baru dari NIS', function () {
    $batch = unggahPengguna($this, [judulPengguna(), barisSiswa()]);

    konfirmasiPengguna($this, $batch);

    expect(User::query()->where('name', 'Ani Mulyani')->value('username'))->toBe('2024001');
});

it('memberi awalan kode sekolah saat NIS sudah dipakai sekolah lain', function () {
    User::factory()->siswa()->untukSekolah($this->lain)->create(['username' => '2024001']);

    $batch = unggahPengguna($this, [judulPengguna(), barisSiswa()]);

    konfirmasiPengguna($this, $batch);

    // Username unik GLOBAL: tanpa awalan, dua sekolah yang NIS-nya kebetulan
    // sama akan bertabrakan - dan kegagalannya muncul di layar login siswa
    // pada hari ujian.
    expect(User::query()->where('name', 'Ani Mulyani')->value('username'))->toBe('smkn1.2024001');
});

it('memberi sandi awal akun baru sebesar password', function () {
    $batch = unggahPengguna($this, [judulPengguna(), barisSiswa()]);

    konfirmasiPengguna($this, $batch);

    $siswa = User::query()->where('name', 'Ani Mulyani')->firstOrFail();

    expect(Hash::check('password', $siswa->password))->toBeTrue();
});

it('memisahkan hitungan akun baru dan akun yang diperbarui sebelum disimpan', function () {
    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'name' => 'Budi Santoso',
        'username' => 'budi',
    ]);

    TeacherProfile::create([
        'school_id' => $this->sekolah->id,
        'user_id' => $guru->id,
        'nip' => '1987654321',
    ]);

    $batch = unggahPengguna($this, [
        judulPengguna(),
        barisSiswa(),
        barisGuru(),
    ]);

    // Angka ini yang membuat keputusan "perbarui akun lama" punya arti bagi
    // Admin: tanpa keduanya, satu-satunya angka yang terlihat adalah "2
    // tersimpan", dan berkas yang salah tidak bisa dibedakan dari impor bersih.
    expect($batch->created_rows)->toBe(1)
        ->and($batch->updated_rows)->toBe(1);

    expect(User::query()->where('username', 'budi')->exists())->toBeTrue();
});

/* ==================================== Perbarui: username, sandi, profil */

it('memperbarui akun lama yang NIS-nya sudah terdaftar', function () {
    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create([
        'name' => 'Ani Mulyani',
        'username' => '2024001',
    ]);

    profilSiswa($this, $siswa, '2024001');

    $batch = unggahPengguna($this, [
        judulPengguna(),
        ['siswa', 'Ani Mulyani Sari', '2024001', '', '', '', '', '', '', '', 'Jl. Baru No. 1', 'ya'],
    ]);

    konfirmasiPengguna($this, $batch);

    // Tidak ada akun kedua: barisnya menempel ke akun yang sudah ada.
    expect(StudentProfile::query()->where('school_id', $this->sekolah->id)->count())->toBe(1)
        ->and($siswa->fresh()->name)->toBe('Ani Mulyani Sari')
        ->and(StudentProfile::query()->where('user_id', $siswa->id)->value('address'))->toBe('Jl. Baru No. 1');
});

it('tidak pernah mengubah username akun lama', function () {
    // Username tertempel di kartu login siswa. Kalau Admin memperbaiki NIS
    // yang salah ketik lalu username diturunkan ulang, nama akun berubah
    // diam-diam - dan setiap siswa yang menghafalnya terkunci pada hari ujian.
    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create([
        'name' => 'Ani Mulyani',
        'username' => '2024001',
    ]);

    profilSiswa($this, $siswa, '2024001');

    $batch = unggahPengguna($this, [
        judulPengguna(),
        // NIS ditulis dengan nol di depan yang salah - kesalahan yang paling
        // sering terjadi, dan yang paling ingin diperbaiki Admin.
        ['siswa', 'Ani Mulyani', '02024001', '', '', '', '', '', '', '', '', 'ya'],
    ]);

    konfirmasiPengguna($this, $batch);

    expect($siswa->fresh()->username)->toBe('2024001');
});

it('tidak pernah mengubah sandi akun lama', function () {
    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create([
        'name' => 'Ani Mulyani',
        'username' => '2024001',
        'password' => 'rahasiaSiswa',
    ]);

    profilSiswa($this, $siswa, '2024001');

    $sandi = $siswa->fresh()->password;

    $batch = unggahPengguna($this, [judulPengguna(), barisSiswa()]);

    konfirmasiPengguna($this, $batch);

    expect($siswa->fresh()->password)->toBe($sandi);
});

it('tidak mengosongkan kolom yang selnya dibiarkan kosong pada baris perbarui', function () {
    // Arah kegagalan yang dipilih dengan sadar: sel kosong berarti "jangan
    // ubah". Alternatifnya membuat berkas yang hanya memuat nama dan kelas
    // MENGHAPUS email, telepon, jenis kelamin, dan alamat seluruh akun yang
    // disentuhnya - tanpa satu pun baris galat yang berbunyi.
    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create([
        'name' => 'Ani Mulyani',
        'username' => '2024001',
        'email' => 'ani@sekolah.sch.id',
        'phone' => '0811111111',
    ]);

    profilSiswa($this, $siswa, '2024001', extra: ['gender' => 'P', 'address' => 'Jl. Melati No. 3']);

    $batch = unggahPengguna($this, [
        judulPengguna(),
        // Hanya nama dan NIS - seluruh kolom lain dibiarkan kosong.
        ['siswa', 'Ani Mulyani Sari', '2024001', '', '', '', '', '', '', '', '', ''],
    ]);

    konfirmasiPengguna($this, $batch);

    $segar = $siswa->fresh();

    expect($segar->email)->toBe('ani@sekolah.sch.id')
        ->and($segar->phone)->toBe('0811111111')
        ->and(StudentProfile::query()->where('user_id', $siswa->id)->value('gender'))->toBe('P')
        ->and(StudentProfile::query()->where('user_id', $siswa->id)->value('address'))->toBe('Jl. Melati No. 3');
});

it('mencari siswa lama lewat NISN bila NIS-nya belum pernah diisi', function () {
    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create([
        'name' => 'Ani Mulyani',
        'username' => 'ani.nisn',
    ]);

    profilSiswa($this, $siswa, null, '0012345678');

    $batch = unggahPengguna($this, [
        judulPengguna(),
        ['siswa', 'Ani Mulyani Sari', '', '0012345678', '', '', '', '', '', '', '', 'ya'],
    ]);

    konfirmasiPengguna($this, $batch);

    // Tidak ada akun ketiga: barisnya menemukan siswa lama lewat NISN.
    expect(StudentProfile::query()->where('school_id', $this->sekolah->id)->count())->toBe(1)
        ->and($siswa->fresh()->name)->toBe('Ani Mulyani Sari');
});

/* ============================================================ Duplikat */

it('menolak dua baris ber-NIS sama dan menyebut nomor baris pertama', function () {
    $batch = unggahPengguna($this, [
        judulPengguna(),
        barisSiswa(nama: 'Ani Mulyani', nis: '2024001'),                                  // baris 2
        ['siswa', 'Ani Mulyani Lain', '2024001', '', '', '', '', '', '', '', '', 'ya'],   // baris 3
    ]);

    expect($batch->pratinjau_json['sah'])->toBe(1)
        ->and($batch->pratinjau_json['baris'][1]['nomor'])->toBe(3)
        ->and($batch->pratinjau_json['baris'][1]['pesan'])->toContain('baris 2');
});

it('menolak NIP yang dipakai lebih dari satu akun alih-alih memilih salah satu', function () {
    // `teacher_profiles` TIDAK punya unique pada nip - hanya pada
    // (school_id, user_id). Memanggil first() di sini berarti memperbarui
    // orang yang salah secara diam-diam, dan kerusakannya muncul di layar
    // login orang lain berhari-hari kemudian.
    foreach (['Budi Santoso', 'Budi Santoso Kedua'] as $nama) {
        $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create(['name' => $nama]);

        TeacherProfile::create([
            'school_id' => $this->sekolah->id,
            'user_id' => $guru->id,
            'nip' => '1987654321',
        ]);
    }

    $batch = unggahPengguna($this, [judulPengguna(), barisGuru()]);

    expect($batch->pratinjau_json['sah'])->toBe(0)
        ->and($batch->pratinjau_json['baris'][0]['pesan'])->toContain('lebih dari satu akun');

    // Keduanya utuh - tidak ada yang diperbarui.
    expect(User::query()->whereIn('name', ['Budi Santoso', 'Budi Santoso Kedua'])->count())->toBe(2);
});

it('menolak email yang sudah dipakai akun lain di sekolah yang sama', function () {
    User::factory()->siswa()->untukSekolah($this->sekolah)->create([
        'username' => 'pemilik.email',
        'email' => 'ani@sekolah.sch.id',
    ]);

    $batch = unggahPengguna($this, [
        judulPengguna(),
        ['siswa', 'Ani Mulyani', '2024001', '', '', '', '', 'ani@sekolah.sch.id', '', '', '', 'ya'],
    ]);

    expect($batch->pratinjau_json['sah'])->toBe(0)
        ->and($batch->pratinjau_json['baris'][0]['pesan'])->toContain('sudah dipakai akun lain');
});

it('membiarkan akun lama mempertahankan emailnya sendiri', function () {
    // Email yang sama pada akun yang SAMA bukan bentrok - dan menganggapnya
    // bentrok berarti berkas ekspor tidak bisa diimpor kembali.
    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create([
        'username' => '2024001',
        'email' => 'ani@sekolah.sch.id',
    ]);

    profilSiswa($this, $siswa, '2024001');

    $batch = unggahPengguna($this, [
        judulPengguna(),
        ['siswa', 'Ani Mulyani', '2024001', '', '', '', '', 'ani@sekolah.sch.id', '', '', '', 'ya'],
    ]);

    expect($batch->pratinjau_json['sah'])->toBe(1);
});

/* =============================================================== Kelas */

it('menulis keanggotaan kelas siswa', function () {
    $batch = unggahPengguna($this, [judulPengguna(), barisSiswa(kelas: 'X-1')]);

    expect($batch->pratinjau_json['baris'][0]['kelas_aksi'])->toBe('baru');

    konfirmasiPengguna($this, $batch);

    $siswa = User::query()->where('name', 'Ani Mulyani')->firstOrFail();

    expect(ClassEnrollment::query()
        ->where('student_id', $siswa->id)
        ->where('class_id', $this->kelas->id)
        ->where('status', 'aktif')
        ->exists())->toBeTrue();
});

it('memindahkan siswa dari kelas lamanya pada tahun ajaran yang sama', function () {
    $baru = SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'X-2']);

    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create(['name' => 'Ani Mulyani']);

    profilSiswa($this, $siswa, '2024001');

    ClassEnrollment::create([
        'school_id' => $this->sekolah->id,
        'class_id' => $this->kelas->id,
        'student_id' => $siswa->id,
        'academic_year_id' => $this->tahun->id,
        'status' => 'aktif',
    ]);

    $batch = unggahPengguna($this, [judulPengguna(), barisSiswa(kelas: 'X-2')]);

    // Pratinjaunya WAJIB menyebutkan perpindahannya: Admin yang mengimpor
    // daftar kelas baru perlu tahu bahwa 12 siswa berpindah, bukan hanya
    // bahwa 12 baris tersimpan.
    expect($batch->pratinjau_json['baris'][0]['kelas_aksi'])->toBe('pindah')
        ->and($batch->pratinjau_json['baris'][0]['kelas_dari'])->toBe('X-1');

    konfirmasiPengguna($this, $batch);

    expect(ClassEnrollment::query()->where('class_id', $this->kelas->id)->where('student_id', $siswa->id)->value('status'))->toBe('pindah')
        ->and(ClassEnrollment::query()->where('class_id', $baru->id)->where('student_id', $siswa->id)->value('status'))->toBe('aktif');
});

it('memakai ulang baris keanggotaan lama alih-alih gagal unique', function () {
    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create(['name' => 'Ani Mulyani']);

    profilSiswa($this, $siswa, '2024001');

    // Siswa yang pernah keluar dari kelas ini punya barisnya.
    ClassEnrollment::create([
        'school_id' => $this->sekolah->id,
        'class_id' => $this->kelas->id,
        'student_id' => $siswa->id,
        'academic_year_id' => $this->tahun->id,
        'status' => 'pindah',
    ]);

    $batch = unggahPengguna($this, [judulPengguna(), barisSiswa()]);

    konfirmasiPengguna($this, $batch);

    expect(ClassEnrollment::query()->where('student_id', $siswa->id)->count())->toBe(1)
        ->and(ClassEnrollment::query()->where('student_id', $siswa->id)->value('status'))->toBe('aktif');
});

it('menolak seluruh berkas bila kolom kelas diisi tanpa tahun ajaran aktif', function () {
    // Keadaannya tidak bisa diperbaiki per baris: Admin harus MENGAKTIFKAN
    // tahun ajarannya lebih dulu - satu tindakan. 1.284 baris gagal untuk satu
    // tindakan adalah laporan yang menyiksa pembacanya tanpa memberinya jalan
    // keluar.
    $this->tahun->forceFill(['is_active' => false])->save();

    masukImporPengguna($this, $this->admin)
        ->post('/admin/pengguna/impor/unggah', ['berkas' => berkasPengguna([judulPengguna(), barisSiswa()])])
        ->assertSessionHasErrors('berkas');

    $batch = UserImportBatch::query()->latest('id')->firstOrFail();

    expect($batch->statusImpor())->toBe(StatusImporPengguna::Gagal)
        ->and(session('errors')->first('berkas'))->toContain('tahun ajaran aktif');
});

it('tetap menerima berkas tanpa kolom kelas meski tidak ada tahun ajaran aktif', function () {
    $this->tahun->forceFill(['is_active' => false])->save();

    // Guru dan pengawas tidak punya kelas, dan itu keadaan normal.
    $batch = unggahPengguna($this, [judulPengguna(), barisGuru()]);

    expect($batch->created_rows)->toBe(1);
});

it('menjadikan kelas yang tidak dikenal sebagai galat baris, bukan kelas baru', function () {
    // Satu salah ketik melahirkan kelas hantu berisi satu siswa, dan itu baru
    // ketahuan saat cetak daftar hadir.
    $batch = unggahPengguna($this, [judulPengguna(), barisSiswa(kelas: 'X-9')]);

    expect($batch->pratinjau_json['sah'])->toBe(0)
        ->and($batch->pratinjau_json['baris'][0]['pesan'])->toContain('X-9')
        ->and($batch->pratinjau_json['baris'][0]['pesan'])->toContain('2026/2027');

    expect(SchoolClass::query()->where('name', 'X-9')->exists())->toBeFalse();
});

it('mengabaikan kolom kelas pada baris guru, bukan menolaknya', function () {
    // Templatenya satu, jadi kolomnya ada di semua baris.
    $batch = unggahPengguna($this, [
        judulPengguna(),
        ['guru', 'Budi Santoso', '', '', '1987654321', '', 'X-1', '', '', '', '', 'ya'],
    ]);

    expect($batch->pratinjau_json['sah'])->toBe(1)
        ->and($batch->pratinjau_json['baris'][0]['kelas_id'])->toBeNull();
});

/* ======================================================= Nomor baris */

it('melaporkan nomor baris yang benar untuk galat di tengah berkas', function () {
    // Nomor yang dilaporkan harus nomor yang dibaca Admin di tepi kiri Excel.
    $batch = unggahPengguna($this, [
        judulPengguna(),                                                              // 1
        barisSiswa('Ani Mulyani', '2024001'),                                          // 2
        barisSiswa('Budi Santoso', '2024002'),                                         // 3
        ['siswa', 'Citra Dewi', '2024003', '', '', '', 'X-9', '', '', '', '', 'ya'],    // 4 - kelas tidak ada
        barisSiswa('Dewi Lestari', '2024004'),                                         // 5
    ]);

    $gagal = collect($batch->pratinjau_json['baris'])->firstWhere('sah', false);

    expect($gagal['nomor'])->toBe(4)
        ->and($gagal['nama'])->toBe('Citra Dewi')
        ->and($gagal['kolom'])->toBe('kelas')
        ->and($batch->pratinjau_json['sah'])->toBe(3);
});

it('mempertahankan nomor baris meski ada baris yang dikosongkan', function () {
    $batch = unggahPengguna($this, [
        judulPengguna(),                                                                // 1
        barisSiswa('Ani Mulyani', '2024001'),                                            // 2
        ['', '', '', '', '', '', '', '', '', '', '', ''],                                // 3 - dikosongkan
        ['siswa', 'Budi Santoso', '2024002', '', '', '', 'X-9', '', '', '', '', 'ya'],    // 4
    ]);

    $gagal = collect($batch->pratinjau_json['baris'])->firstWhere('sah', false);

    expect($gagal['nomor'])->toBe(4);
});

/* =========================================== Pratinjau tidak menulis */

it('tidak menulis satu pun akun pada langkah pratinjau', function () {
    // Dihitung lewat `tanpaTenant()`: Admin sekolah LAIN ikut terhitung bila
    // memakai scope biasa, dan test ini akan gagal karena sekolah lain -
    // bukan karena ada akun yang ditulis pratinjau.
    $sebelum = User::tanpaTenant()->count();

    unggahPengguna($this, [
        judulPengguna(),
        barisSiswa(),
        barisGuru(),
        ['pengawas', 'Citra Dewi', '', '', '', 'PGW-001', '', '', '', '', '', 'ya'],
    ]);

    expect(User::tanpaTenant()->count())->toBe($sebelum);
});

it('hanya menyimpan baris yang sah', function () {
    $batch = unggahPengguna($this, [
        judulPengguna(),
        barisSiswa('Ani Mulyani', '2024001'),                                          // sah
        ['siswa', 'Budi Santoso', '2024002', '', '', '', 'X-9', '', '', '', '', 'ya'],    // kelas tidak ada
        barisSiswa('Citra Dewi', '2024003'),                                           // sah
    ]);

    konfirmasiPengguna($this, $batch);

    $segar = $batch->fresh();

    expect($segar->success_rows)->toBe(2)
        ->and($segar->failed_rows)->toBe(1)
        ->and($segar->error_report_path)->not->toBeNull();

    expect(User::query()->whereIn('name', ['Ani Mulyani', 'Citra Dewi'])->count())->toBe(2)
        ->and(User::query()->where('name', 'Budi Santoso')->exists())->toBeFalse();
});

/* ===================================================== Laporan galat */

it('menulis laporan galat di disk privat yang bisa diunduh', function () {
    $batch = unggahPengguna($this, [
        judulPengguna(),
        ['siswa', 'Budi Santoso', '2024002', '', '', '', 'X-9', '', '', '', '', 'ya'],
    ]);

    konfirmasiPengguna($this, $batch);

    $batch->refresh();

    Storage::disk('local')->assertExists($batch->error_report_path);

    masukImporPengguna($this, $this->admin)
        ->get("/admin/pengguna/impor/{$batch->id}/laporan")
        ->assertOk();
});

it('mengembalikan 404 saat laporan galatnya tidak ada', function () {
    $batch = UserImportBatch::factory()->untukSekolah($this->sekolah)->create([
        'user_id' => $this->admin->id,
        'error_report_path' => null,
    ]);

    masukImporPengguna($this, $this->admin)
        ->get("/admin/pengguna/impor/{$batch->id}/laporan")
        ->assertNotFound();
});

/* ============================================================ Konfirmasi */

it('menolak konfirmasi kedua atas berkas yang sama', function () {
    $batch = unggahPengguna($this, [judulPengguna(), barisSiswa()]);

    konfirmasiPengguna($this, $batch);

    masukImporPengguna($this, $this->admin)
        ->post("/admin/pengguna/impor/{$batch->id}/konfirmasi")
        ->assertSessionHasErrors('berkas');

    // Akunnya tidak bertambah.
    expect(User::query()->where('name', 'Ani Mulyani')->count())->toBe(1);
});

it('menolak konfirmasi berkas yang belum dipratinjau', function () {
    $batch = UserImportBatch::factory()->untukSekolah($this->sekolah)->create([
        'user_id' => $this->admin->id,
    ]);

    masukImporPengguna($this, $this->admin)
        ->post("/admin/pengguna/impor/{$batch->id}/konfirmasi")
        ->assertSessionHasErrors('berkas');
});

/* ============================================================ Tenant */

it('memberi Admin sekolah lain 404, bukan 403', function () {
    // Barisnya memuat NAMA ASLI berkasnya - "siswa-kelas-x-2026.xlsx" - dan
    // bagi sekolah lain itu sudah cukup untuk memberi tahu apa yang sedang
    // disusun sekolah lain.
    $batch = unggahPengguna($this, [judulPengguna(), barisSiswa()]);

    masukImporPengguna($this, $this->adminLain)
        ->get("/admin/pengguna/impor/{$batch->id}/laporan")
        ->assertNotFound();

    masukImporPengguna($this, $this->adminLain)
        ->post("/admin/pengguna/impor/{$batch->id}/konfirmasi")
        ->assertNotFound();
});

it('tidak menemukan akun sekolah lain dengan NIS yang sama', function () {
    // NIS unik per sekolah, bukan global - dua sekolah boleh punya NIS yang
    // kebetulan sama, dan itu memang terjadi.
    $asing = User::factory()->siswa()->untukSekolah($this->lain)->create([
        'name' => 'Ani Sekolah Lain',
        'username' => 'asing.2024001',
    ]);

    StudentProfile::create([
        'school_id' => $this->lain->id,
        'user_id' => $asing->id,
        'nis' => '2024001',
    ]);

    $batch = unggahPengguna($this, [judulPengguna(), barisSiswa()]);

    // Akun sekolah lain tidak ditemukan, jadi barisnya menjadi akun BARU.
    expect($batch->pratinjau_json['baris'][0]['aksi'])->toBe('baru');

    konfirmasiPengguna($this, $batch);

    expect($asing->fresh()->name)->toBe('Ani Sekolah Lain');
});

/* ============================================================ Batas & jejak */

it('menolak berkas yang melebihi batas baris sebelum menyimpannya', function () {
    $sebelum = User::tanpaTenant()->count();

    $baris = [judulPengguna()];

    foreach (range(1, PembacaBerkas::BARIS_MAKS + 5) as $i) {
        $baris[] = barisSiswa('Siswa '.$i, '90'.$i);
    }

    masukImporPengguna($this, $this->admin)
        ->post('/admin/pengguna/impor/unggah', ['berkas' => berkasPengguna($baris)])
        ->assertSessionHasErrors('berkas');

    // Batasnya diperiksa SEBELUM berkasnya di-parse: 2.005 baris yang ditolak
    // setelah dibaca seluruhnya tetap menghabiskan waktu dan memori server.
    expect(User::tanpaTenant()->count())->toBe($sebelum);
});

it('mencatat jejak impor di log aktivitas', function () {
    $batch = unggahPengguna($this, [judulPengguna(), barisSiswa()]);

    konfirmasiPengguna($this, $batch);

    expect(DB::table('activity_logs')
        ->where('action', 'like', '%impor pengguna%')
        ->count())->toBeGreaterThan(0);
});

it('menonaktifkan akun baru bila kolom aktif berisi tidak', function () {
    $batch = unggahPengguna($this, [
        judulPengguna(),
        ['siswa', 'Ani Mulyani', '2024001', '', '', '', 'X-1', '', '', '', '', 'tidak'],
    ]);

    konfirmasiPengguna($this, $batch);

    expect(User::query()->where('name', 'Ani Mulyani')->firstOrFail()->isAktif())->toBeFalse();
});

it('menolak jenis kelamin yang tidak dikenal', function () {
    $batch = unggahPengguna($this, [
        judulPengguna(),
        ['siswa', 'Ani Mulyani', '2024001', '', '', '', 'X-1', '', '', 'X', '', 'ya'],
    ]);

    expect($batch->pratinjau_json['sah'])->toBe(0)
        ->and($batch->pratinjau_json['baris'][0]['kolom'])->toBe('jenis_kelamin');
});
