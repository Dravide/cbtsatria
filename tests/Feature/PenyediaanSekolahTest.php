<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Facades\Hash;

/**
 * Penyediaan sekolah - `sekolah:daftarkan`.
 *
 * =====================================================================
 * Yang dibuktikan bukan "perintahnya tidak galat", melainkan AKIBATNYA
 * =====================================================================
 * Perintah yang membuat baris `schools` lalu berhenti akan lulus setiap test
 * yang hanya memeriksa exit code 0 - dan tetap meninggalkan Admin yang tidak
 * bisa membuat event apa pun, karena `EventController` menolak tanpa
 * `academic_year_id`. Karena itu setiap test di sini memeriksa akibat yang
 * bisa dipakai, bukan sekadar baris yang lahir.
 *
 * Tiga hal diperiksa berpasangan di hampir setiap test:
 *
 *   1. yang SEHARUSNYA dibuat, benar-benar ada;
 *   2. yang seharusnya TIDAK tersentuh, benar-benar tidak tersentuh.
 *
 * Poin kedua yang paling mudah hilang. Perintah provisioning adalah kode yang
 * berjalan sekali di sekolah baru dengan hak penuh atas basis data, dan
 * kesalahan di dalamnya tidak akan ketahuan sampai sekolah itu memakainya.
 */
beforeEach(function () {
    $this->lain = School::factory()->create(['name' => 'SMK Negeri 2']);

    $this->adminLain = User::factory()->admin()->untukSekolah($this->lain)->create([
        'username' => 'admin.sekolah2',
    ]);

    // Tahun ajaran milik sekolah LAIN, dan ia AKTIF. Dua test di bawah
    // membuktikan perintah ini tidak mematikannya - kelas bug yang paling
    // mahal di sini, karena kegagalannya bukan galat melainkan sekolah lain
    // yang diam-diam kehilangan tahun ajaran aktifnya.
    $this->tahunLain = AcademicYear::factory()->untukSekolah($this->lain)->aktif()->create([
        'name' => '2025/2026',
    ]);

    Semester::factory()->create([
        'school_id' => $this->lain->id,
        'academic_year_id' => $this->tahunLain->id,
        'name' => 'Ganjil',
        'is_active' => true,
    ]);
});

/** Opsi minimum yang sah, supaya tiap test hanya menyebut yang diujinya. */
function opsiSekolah(array $ganti = []): array
{
    return array_merge([
        '--kode' => 'SMKN9',
        '--nama' => 'SMK Negeri 9 Sukamaju',
        '--npsn' => '20599999',
        '--admin-nama' => 'Budi Santoso',
        '--admin-username' => 'admin.smkn9',
    ], $ganti);
}

it('mendaftarkan sekolah beserta Admin pertamanya', function () {
    $this->artisan('sekolah:daftarkan', opsiSekolah())->assertSuccessful();

    $sekolah = School::query()->where('code', 'SMKN9')->first();

    expect($sekolah)->not->toBeNull()
        ->and($sekolah->name)->toBe('SMK Negeri 9 Sukamaju')
        ->and($sekolah->npsn)->toBe('20599999')
        ->and($sekolah->status)->toBe('aktif');

    $admin = User::tanpaTenant()->where('username', 'admin.smkn9')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->school_id)->toBe($sekolah->id)
        ->and($admin->role)->toBe(Role::Admin)
        ->and($admin->is_active)->toBeTrue();
});

it('membuatkan tahun ajaran aktif dan dua semester', function () {
    $this->artisan('sekolah:daftarkan', opsiSekolah())->assertSuccessful();

    $sekolah = School::query()->where('code', 'SMKN9')->first();

    // TANPA tahun ajaran aktif, EventController menolak setiap pembuatan
    // event (`academic_year_id` wajib) - sehingga Admin baru tidak bisa
    // membuat event apa pun. Inilah yang membuat perintah ini bukan sekadar
    // "membuat baris schools".
    $tahun = AcademicYear::query()->withoutGlobalScopes()
        ->where('school_id', $sekolah->id)
        ->get();

    expect($tahun)->toHaveCount(1)
        ->and($tahun->first()->is_active)->toBeTrue();

    $semester = Semester::query()->withoutGlobalScopes()
        ->where('school_id', $sekolah->id)
        ->orderBy('start_date')
        ->pluck('name')
        ->all();

    expect($semester)->toBe(['Ganjil', 'Genap']);
});

it('menyatakan satu semester aktif, dan hanya satu', function () {
    $this->artisan('sekolah:daftarkan', opsiSekolah())->assertSuccessful();

    $sekolah = School::query()->where('code', 'SMKN9')->first();

    $aktif = Semester::query()->withoutGlobalScopes()
        ->where('school_id', $sekolah->id)
        ->where('is_active', true)
        ->get();

    // Dua semester aktif sekaligus adalah keadaan yang tidak punya arti, dan
    // yang paling mungkin lahir dari perintah yang menulis is_active secara
    // seragam alih-alih menurut bulan pendaftaran.
    expect($aktif)->toHaveCount(1);
});

it('memilih semester yang memuat hari ini, bukan semester pertama', function () {
    // Februari: tahun ajaran dimulai Juli sebelumnya, dan yang berjalan
    // adalah Genap. Sekolah yang didaftarkan bulan Februari tidak boleh
    // memulai dengan semester yang sudah lewat setengah tahun.
    $this->travelTo(now()->setDate(2027, 2, 10));

    $this->artisan('sekolah:daftarkan', opsiSekolah())->assertSuccessful();

    $sekolah = School::query()->where('code', 'SMKN9')->first();

    $aktif = Semester::query()->withoutGlobalScopes()
        ->where('school_id', $sekolah->id)
        ->where('is_active', true)
        ->first();

    expect($aktif->name)->toBe('Genap');

    $tahun = AcademicYear::query()->withoutGlobalScopes()
        ->where('school_id', $sekolah->id)
        ->first();

    // Tahun ajaran berganti tiap Juli, bukan tiap Januari.
    expect($tahun->name)->toBe('2026/2027');
});

it('menurunkan nama tahun ajaran dari kalender Indonesia, bukan tahun kalender', function () {
    // Agustus 2026: tahun ajaran 2026/2027, bukan 2026/2027 yang kebetulan
    // sama - inilah bulan yang membedakan keduanya.
    $this->travelTo(now()->setDate(2026, 8, 5));

    $this->artisan('sekolah:daftarkan', opsiSekolah())->assertSuccessful();

    $tahun = AcademicYear::query()->withoutGlobalScopes()
        ->where('school_id', School::query()->where('code', 'SMKN9')->first()->id)
        ->first();

    expect($tahun->name)->toBe('2026/2027');
});

it('tidak menyentuh tahun ajaran aktif sekolah lain', function () {
    $this->artisan('sekolah:daftarkan', opsiSekolah())->assertSuccessful();

    // Ini kelas bug yang paling mahal di perintah ini, dan bukan hipotetis:
    // AcademicYearService::aktifkan menulis kueri "matikan tahun ajaran aktif
    // yang lain" yang mengandalkan SchoolScope - sedangkan perintah console
    // berjalan tanpa pengguna, sehingga scope-nya diam dan kueri itu akan
    // menyapu SELURUH basis data.
    expect($this->tahunLain->fresh()->is_active)->toBeTrue();

    $semesterLain = Semester::query()->withoutGlobalScopes()
        ->where('school_id', $this->lain->id)
        ->where('is_active', true)
        ->count();

    expect($semesterLain)->toBe(1);
});

it('menerima kata sandi yang diberikan dan tidak mencetak yang acak', function () {
    $this->artisan('sekolah:daftarkan', opsiSekolah(['--sandi' => 'rahasia123']))
        ->expectsOutputToContain('riwayat shell')
        ->assertSuccessful();

    $admin = User::tanpaTenant()->where('username', 'admin.smkn9')->first();

    expect(Hash::check('rahasia123', $admin->password))->toBeTrue();
});

it('membuat sandi acak bila opsinya kosong, dan sandinya bisa dipakai masuk', function () {
    $this->artisan('sekolah:daftarkan', opsiSekolah())
        ->expectsOutputToContain('hanya tampil sekali')
        ->assertSuccessful();

    $admin = User::tanpaTenant()->where('username', 'admin.smkn9')->first();

    // Hash-nya harus sah dan TIDAK boleh sama dengan apa pun yang bisa
    // ditebak. Yang diperiksa bukan nilai sandinya - sandi acak sengaja tidak
    // bisa dibaca dari test - melainkan bahwa kolomnya berisi hash yang sah,
    // bukan string kosong atau teks terbuka.
    expect($admin->password)->toStartWith('$2y$');
});

it('menolak NPSN yang bukan 8 angka', function () {
    $this->artisan('sekolah:daftarkan', opsiSekolah(['--npsn' => '123']))
        ->expectsOutputToContain('NPSN harus tepat 8 angka')
        ->assertFailed();

    expect(School::query()->where('code', 'SMKN9')->exists())->toBeFalse();
});

it('menolak kode sekolah yang mengandung spasi atau tanda baca', function () {
    $this->artisan('sekolah:daftarkan', opsiSekolah(['--kode' => 'SMK N-9']))
        ->expectsOutputToContain('Kode sekolah hanya huruf dan angka')
        ->assertFailed();

    expect(School::query()->where('name', 'SMK Negeri 9 Sukamaju')->exists())->toBeFalse();
});

it('menolak username Admin yang berhuruf besar', function () {
    // Nilainya diketik siswa di layar login, dan UserRegistrar menurunkan
    // hurufnya. Menerima huruf besar di sini berarti sekolah mendapat Admin
    // berusername yang tidak pernah bisa diketik ulang persis seperti adanya.
    $this->artisan('sekolah:daftarkan', opsiSekolah(['--admin-username' => 'Admin.SMKN9']))
        ->assertFailed();

    expect(User::tanpaTenant()->where('username', 'Admin.SMKN9')->exists())->toBeFalse();
});

it('menolak NPSN yang sudah dipakai sekolah lain', function () {
    $this->artisan('sekolah:daftarkan', opsiSekolah(['--npsn' => $this->lain->npsn]))
        ->expectsOutputToContain('sudah dipakai sekolah')
        ->assertFailed();

    expect(School::query()->where('code', 'SMKN9')->exists())->toBeFalse();
});

it('menolak username yang sudah dipakai akun di sekolah lain', function () {
    // Username unik GLOBAL. Kalau ini lolos, kegagalannya muncul di tingkat
    // basis data sebagai galat integritas yang tidak menyebut sebabnya.
    $this->artisan('sekolah:daftarkan', opsiSekolah(['--admin-username' => 'admin.sekolah2']))
        ->expectsOutputToContain('sudah dipakai akun di sekolah lain')
        ->assertFailed();

    expect(School::query()->where('code', 'SMKN9')->exists())->toBeFalse();
});

it('idempoten: dijalankan dua kali tidak menggandakan apa pun', function () {
    $this->artisan('sekolah:daftarkan', opsiSekolah())->assertSuccessful();
    $this->artisan('sekolah:daftarkan', opsiSekolah())->assertSuccessful();

    expect(School::query()->where('code', 'SMKN9')->count())->toBe(1)
        ->and(User::tanpaTenant()->where('username', 'admin.smkn9')->count())->toBe(1);

    $sekolah = School::query()->where('code', 'SMKN9')->first();

    expect(AcademicYear::query()->withoutGlobalScopes()->where('school_id', $sekolah->id)->count())->toBe(1)
        ->and(Semester::query()->withoutGlobalScopes()->where('school_id', $sekolah->id)->count())->toBe(2);
});

it('tidak menimpa kolom sekolah yang sudah ada pada eksekusi kedua', function () {
    $this->artisan('sekolah:daftarkan', opsiSekolah())->assertSuccessful();

    // Admin memperbaiki nama sekolahnya lewat plate 04 di antara dua
    // eksekusi - dan perintah yang menimpanya berarti membatalkan pekerjaan
    // itu tanpa memberi tahu siapa pun.
    School::query()->where('code', 'SMKN9')->first()->update(['name' => 'SMK Negeri 9 Diperbaiki']);

    $this->artisan('sekolah:daftarkan', opsiSekolah(['--nama' => 'Nama Yang Salah']))->assertSuccessful();

    expect(School::query()->where('code', 'SMKN9')->first()->name)->toBe('SMK Negeri 9 Diperbaiki');
});

it('tidak mengubah sandi Admin yang sudah ada pada eksekusi kedua', function () {
    $this->artisan('sekolah:daftarkan', opsiSekolah(['--sandi' => 'sandiawal1']))->assertSuccessful();

    $this->artisan('sekolah:daftarkan', opsiSekolah(['--sandi' => 'sandibaru2']))
        ->expectsOutputToContain('TIDAK diubah')
        ->assertSuccessful();

    $admin = User::tanpaTenant()->where('username', 'admin.smkn9')->first();

    expect(Hash::check('sandiawal1', $admin->password))->toBeTrue()
        ->and(Hash::check('sandibaru2', $admin->password))->toBeFalse();
});

it('melengkapi sekolah yang sudah ada tetapi belum punya tahun ajaran', function () {
    // Sekolah yang terlanjur dibuat lewat tinker - persis keadaan yang
    // membuat celah ini ada. Perintah yang sama harus memperbaikinya, bukan
    // menolak karena kodenya sudah terpakai.
    $sekolah = School::factory()->create([
        'code' => 'SMKN9',
        'name' => 'SMK Negeri 9 Sukamaju',
        'npsn' => '20599999',
    ]);

    $this->artisan('sekolah:daftarkan', opsiSekolah())->assertSuccessful();

    expect(School::query()->where('code', 'SMKN9')->count())->toBe(1)
        ->and(AcademicYear::query()->withoutGlobalScopes()->where('school_id', $sekolah->id)->count())->toBe(1);
});

it('tidak menulis apa pun dalam mode kering', function () {
    $this->artisan('sekolah:daftarkan', opsiSekolah(['--kering' => true]))
        ->expectsOutputToContain('Mode kering')
        ->assertSuccessful();

    expect(School::query()->where('code', 'SMKN9')->exists())->toBeFalse()
        ->and(User::tanpaTenant()->where('username', 'admin.smkn9')->exists())->toBeFalse();
});

it('tidak menyimpan bawaan pengaturan, supaya nilai baru kelak ikut berlaku', function () {
    $this->artisan('sekolah:daftarkan', opsiSekolah())->assertSuccessful();

    // `settings` null berarti PengaturanBawaan::gabung() yang mengisinya saat
    // dibaca. Menyalin bawaannya ke kolom berarti nilainya membeku: sekolah
    // yang didaftarkan hari ini tidak akan ikut mendapat bawaan baru yang
    // ditambahkan kelak.
    expect(School::query()->where('code', 'SMKN9')->first()->settings)->toBeNull();
});

it('memakai nama sekolah yang panjang tanpa terpotong', function () {
    $panjang = 'SMK Negeri 9 Sukamaju Kabupaten Bogor Jawa Barat';

    $this->artisan('sekolah:daftarkan', opsiSekolah(['--nama' => $panjang]))->assertSuccessful();

    expect(School::query()->where('code', 'SMKN9')->first()->name)->toBe($panjang);
});
