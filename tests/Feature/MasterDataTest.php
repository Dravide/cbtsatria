<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Services\PenempatanKelas;
use App\Domain\Facility\Models\Room;
use App\Domain\Facility\Models\RoomEntryLink;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\TeacherProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/**
 * Laman Fase 2 benar-benar bisa dibuka, dan membawa data yang benar.
 *
 * Backend yang hijau tanpa layar bukan fase yang selesai - dan laman yang
 * membalas 200 tanpa prop yang benar belum "selesai" juga. Test ini memeriksa
 * keduanya: komponen Inertia yang tepat, dan isi yang dijanjikan.
 *
 * Perhatikan apa yang diperiksa pada setiap test: bukan hanya bahwa laman
 * terbuka, tetapi bahwa angkanya berasal dari SEKOLAH YANG BENAR. Laman yang
 * menampilkan data sekolah lain akan lulus test "apakah 200" dengan mudah.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);
    $this->lain = School::factory()->create(['name' => 'SMK Negeri 2']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.fase2',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'Ganjil',
        'is_active' => true,
    ]);
});

it('membuka profil sekolah dengan data sekolahnya sendiri', function () {
    $this->actingAs($this->admin)
        ->get('/admin/sekolah')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Sekolah')
            ->where('sekolah.nama', 'SMK Negeri 1')
            ->where('sekolah.npsn', $this->sekolah->npsn)
            ->has('zonaWaktu')
        );
});

it('menyimpan profil sekolah tanpa menyentuh NPSN', function () {
    $this->actingAs($this->admin)
        ->put('/admin/sekolah', [
            'nama' => 'SMK Negeri 1 Sukamaju',
            'alamat' => 'Jl. Baru No. 1',
            'email' => 'baru@smkn1.sch.id',
            'telepon' => '021-111',
            'zona_waktu' => 'Asia/Makassar',
            // npsn sengaja disertakan di sini untuk membuktikan request
            // langsung pun tidak mengubahnya - penguncian di UI saja tidak
            // cukup.
            'npsn' => '99999999',
        ])
        ->assertRedirect();

    $sekolah = $this->sekolah->fresh();

    expect($sekolah->name)->toBe('SMK Negeri 1 Sukamaju')
        ->and($sekolah->timezone)->toBe('Asia/Makassar')
        ->and($sekolah->npsn)->toBe($this->sekolah->npsn);
});

/* =====================================================================
 | Logo sekolah - plate 04, kotak "Logo"
 |===================================================================== */

it('mengunggah logo dan menyimpannya di disk publik', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)
        ->post('/admin/sekolah/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])
        ->assertRedirect();

    $sekolah = $this->sekolah->fresh();

    expect($sekolah->logo_path)->not->toBeNull();

    // Disk yang diperiksa adalah `public`, dan itu bagian dari yang diuji:
    // logo harus tampil di halaman pintu ruang yang dibuka TANPA login.
    // Berkas yang mendarat di disk `local` akan lulus pemeriksaan
    // "logo_path terisi" sambil menghasilkan gambar rusak di sana.
    Storage::disk('public')->assertExists($sekolah->logo_path);
});

it('mengunggah logo SVG, bukan hanya PNG', function () {
    Storage::fake('public');

    $svg = UploadedFile::fake()->createWithContent(
        'logo.svg',
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>',
    );

    $this->actingAs($this->admin)
        ->post('/admin/sekolah/logo', ['logo' => $svg])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->sekolah->fresh()->logo_path)->not->toBeNull();
});

it('menghapus berkas lama saat logo diganti', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)
        ->post('/admin/sekolah/logo', ['logo' => UploadedFile::fake()->image('satu.png')]);

    $lama = $this->sekolah->fresh()->logo_path;

    $this->actingAs($this->admin)
        ->post('/admin/sekolah/logo', ['logo' => UploadedFile::fake()->image('dua.png')]);

    $baru = $this->sekolah->fresh()->logo_path;

    expect($baru)->not->toBe($lama);
    Storage::disk('public')->assertMissing($lama);
    Storage::disk('public')->assertExists($baru);
});

it('menghapus logo beserta berkasnya', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)
        ->post('/admin/sekolah/logo', ['logo' => UploadedFile::fake()->image('logo.png')]);

    $path = $this->sekolah->fresh()->logo_path;

    $this->actingAs($this->admin)
        ->delete('/admin/sekolah/logo')
        ->assertRedirect();

    expect($this->sekolah->fresh()->logo_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('menolak logo yang bukan PNG atau SVG', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)
        ->post('/admin/sekolah/logo', [
            'logo' => UploadedFile::fake()->create('dokumen.pdf', 10, 'application/pdf'),
        ])
        ->assertSessionHasErrors('logo');

    expect($this->sekolah->fresh()->logo_path)->toBeNull();
});

it('menolak logo yang lebih besar dari 1 MB', function () {
    Storage::fake('public');

    // 1025 KB, satu kilobyte di atas batas. Ukuran BERKAS diuji, bukan
    // dimensi gambarnya - wireframe plate 04 menulis "maks 1 MB".
    $this->actingAs($this->admin)
        ->post('/admin/sekolah/logo', [
            'logo' => UploadedFile::fake()->image('besar.png')->size(1025),
        ])
        ->assertSessionHasErrors('logo');

    expect($this->sekolah->fresh()->logo_path)->toBeNull();
});

it('tidak membiarkan admin sekolah lain menyentuh logo sekolah ini', function () {
    Storage::fake('public');

    $adminLain = User::factory()->admin()->untukSekolah($this->lain)->create([
        'username' => 'admin.sekolah2',
    ]);

    // Rutenya tidak menerima id sekolah, jadi yang diuji bukan "menyebut
    // sekolah lain di URL" - melainkan bahwa sekolah yang dipakai adalah
    // sekolah PENGGUNA, dan Admin sekolah lain tidak bisa menulis ke sini.
    $this->actingAs($adminLain)
        ->post('/admin/sekolah/logo', ['logo' => UploadedFile::fake()->image('x.png')]);

    expect($this->sekolah->fresh()->logo_path)->toBeNull();
    expect($this->lain->fresh()->logo_path)->not->toBeNull();
});

it('mengirim url logo, bukan path mentahnya, ke peramban', function () {
    $this->sekolah->update(['logo_path' => 'logos/contoh.png']);

    $this->actingAs($this->admin)
        ->get('/admin/sekolah')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Sekolah')
            // Path mentah tidak berguna bagi peramban; yang dikirim adalah
            // URL yang bisa langsung dipasang di <img src>.
            ->where('sekolah.logo_url', asset('storage/logos/contoh.png'))
        );
});

it('mengirim null sebagai logo_url saat sekolah belum punya logo', function () {
    $this->actingAs($this->admin)
        ->get('/admin/sekolah')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Sekolah')
            ->where('sekolah.logo_url', null)
        );
});

it('membuka pengaturan dengan nilai bawaan saat sekolah belum mengisi', function () {
    $this->actingAs($this->admin)
        ->get('/admin/pengaturan')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Pengaturan')
            ->where('pengaturan.ambang_pelanggaran', 5)
            ->where('pengaturan.kkm', 70)
        );
});

it('menyimpan pengaturan sebagai nilai bawaan, bukan sebagai nilai event', function () {
    $this->actingAs($this->admin)
        ->put('/admin/pengaturan', [
            'durasi_token_menit' => 20,
            'ambang_pelanggaran' => 3,
            'simpan_otomatis_detik' => 30,
            'kkm' => 75,
            'kunci_klik_kanan' => true,
            'peringatkan_keluar_tab' => true,
            'kunci_setelah_ambang' => false,
            'izinkan_tempel_uraian' => true,
        ])
        ->assertRedirect();

    expect($this->sekolah->fresh()->settings['kkm'])->toBe(75)
        ->and($this->sekolah->fresh()->settings['ambang_pelanggaran'])->toBe(3);
});

it('membuka log aktivitas', function () {
    $this->actingAs($this->admin)
        ->get('/admin/log')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Log')
            ->has('baris')
        );
});

it('membuka laman pengguna dengan tab siswa sebagai bawaan', function () {
    User::factory()->siswa()->untukSekolah($this->sekolah)->count(3)->create();
    User::factory()->siswa()->untukSekolah($this->lain)->count(5)->create();

    $this->actingAs($this->admin)
        ->get('/admin/pengguna')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Pengguna')
            ->where('tab', 'student')
            // Hitungan dari sekolah ini saja. Lima siswa sekolah lain tidak
            // boleh muncul di sini - dan itulah alasan test ini ada.
            ->where('tabnya.student', 3)
            ->where('jumlah', 3)
        );
});

it('menyaring pengguna menurut tab peran', function () {
    User::factory()->guru()->untukSekolah($this->sekolah)->count(2)->create();

    $this->actingAs($this->admin)
        ->get('/admin/pengguna?tab=teacher')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tab', 'teacher')
            ->where('jumlah', 2)
        );
});

it('mengirim isi profil setiap baris ke laman pengguna', function () {
    // -----------------------------------------------------------------
    // Yang dijaga di sini bukan "lamannya terbuka", melainkan isinya
    // -----------------------------------------------------------------
    // Sampai 2026-11-05 laman ini hanya mengirim `nis` untuk SELURUH peran,
    // dan hanya dari `studentProfile`. Akibatnya dua hal, dan keduanya lolos
    // dari setiap test yang hanya memeriksa status 200:
    //
    //   1. Tab Guru selalu menampilkan "-" di kolom NIS/NIP, karena NIP ada
    //      di `teacher_profiles` dan tidak pernah dibaca;
    //   2. Modal "Ubah" membuka form yang field identitasnya KOSONG. Form
    //      yang tampak berhasil dimuat, lalu Admin menekan Simpan dan
    //      menghapus data yang tidak pernah ia lihat.
    // -----------------------------------------------------------------
    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'name' => 'Budi Guru',
    ]);

    TeacherProfile::create([
        'school_id' => $this->sekolah->id,
        'user_id' => $guru->id,
        'nip' => '1987654321',
        'gender' => 'L',
        'address' => 'Jl. Melati 3',
    ]);

    $this->actingAs($this->admin)
        ->get('/admin/pengguna?tab=teacher')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tab', 'teacher')
            ->has('baris.data', 1)
            // Nomornya datang dari `teacher_profiles`, bukan dari profil siswa
            // yang tidak ada.
            ->where('baris.data.0.nomor', '1987654321')
            ->where('baris.data.0.profil.nip', '1987654321')
            ->where('baris.data.0.profil.gender', 'L')
            ->where('baris.data.0.profil.address', 'Jl. Melati 3')
        );
});

it('mengirim NISN bila siswa belum punya NIS', function () {
    // NISN tidak dimiliki setiap sekolah, dan sebagian mengisinya lebih dulu.
    // Baris yang nomornya kosong berarti Admin tidak punya cara membedakan
    // dua siswa bernama sama.
    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create();

    StudentProfile::create([
        'school_id' => $this->sekolah->id,
        'user_id' => $siswa->id,
        'nis' => null,
        'nisn' => '0012345678',
    ]);

    $this->actingAs($this->admin)
        ->get('/admin/pengguna')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('baris.data.0.nomor', '0012345678')
        );
});

it('menurunkan username dari NIS saat menambah siswa', function () {
    $this->actingAs($this->admin)
        ->post('/admin/pengguna', [
            'role' => 'student',
            'name' => 'Siswa Baru',
            'nis' => '2024999',
            'is_active' => true,
        ])
        ->assertRedirect();

    $siswa = User::tanpaTenant()->where('username', '2024999')->first();

    expect($siswa)->not->toBeNull()
        ->and($siswa->username)->toBe('2024999')
        ->and($siswa->studentProfile->nis)->toBe('2024999');
});

it('memberi awalan kode sekolah saat NIS sudah dipakai sekolah lain', function () {
    // NIS yang sama sudah ada di sekolah lain. Username unik GLOBAL, jadi
    // menambahkannya apa adanya akan menabrak - dan kegagalannya muncul
    // jauh kemudian, di layar login siswa pada hari ujian.
    $lain = User::factory()->siswa()->untukSekolah($this->lain)->create([
        'username' => '2024888',
    ]);
    StudentProfile::create([
        'school_id' => $this->lain->id,
        'user_id' => $lain->id,
        'nis' => '2024888',
    ]);

    $this->actingAs($this->admin)
        ->post('/admin/pengguna', [
            'role' => 'student',
            'name' => 'Siswa Bentrok',
            'nis' => '2024888',
            'is_active' => true,
        ])
        ->assertRedirect();

    $baru = User::tanpaTenant()->where('name', 'Siswa Bentrok')->first();

    expect($baru->username)->toBe(strtolower($this->sekolah->code).'.2024888')
        ->and($baru->username)->not->toBe('2024888');
});

it('menonaktifkan pengguna secara massal tanpa menghapusnya', function () {
    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->count(3)->create();

    $this->actingAs($this->admin)
        ->post('/admin/pengguna/massal', [
            'id' => $siswa->pluck('id')->all(),
            'aksi' => 'nonaktifkan',
        ])
        ->assertRedirect();

    expect(User::query()->whereIn('id', $siswa->pluck('id'))->where('is_active', true)->count())
        ->toBe(0)
        // Barisnya masih ada - akun menonaktifkan, bukan menghapus.
        ->and(User::query()->whereIn('id', $siswa->pluck('id'))->count())->toBe(3);
});

it('membuka tahun ajaran dengan semester bersarang di induknya', function () {
    $this->actingAs($this->admin)
        ->get('/admin/tahun-ajaran')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/TahunAjaran')
            ->has('tahun', 1)
            ->where('tahun.0.nama', '2026/2027')
            ->where('tahun.0.aktif', true)
            ->has('tahun.0.semester', 1)
            ->where('tahun.0.semester.0.nama', 'Ganjil')
        );
});

it('hanya mengizinkan satu tahun ajaran aktif', function () {
    $lama = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2025/2026',
    ]);

    $baru = AcademicYear::factory()->untukSekolah($this->sekolah)->create([
        'name' => '2027/2028',
    ]);

    // Yang lama aktif, yang baru belum. Mengaktifkan yang baru harus
    // mematikan yang lama - dalam satu transaksi, sehingga tidak pernah ada
    // saat di mana keduanya sama-sama aktif.
    $this->actingAs($this->admin)
        ->post("/admin/tahun-ajaran/{$baru->id}/aktifkan")
        ->assertRedirect();

    expect($baru->fresh()->is_active)->toBeTrue()
        ->and($lama->fresh()->is_active)->toBeFalse()
        ->and(AcademicYear::query()->where('is_active', true)->count())->toBe(1);
});

it('menutup tahun ajaran tanpa menghapus data apa pun', function () {
    $kelas = SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'X-1']);

    $this->actingAs($this->admin)
        ->post("/admin/tahun-ajaran/{$this->tahun->id}/tutup")
        ->assertRedirect();

    // Inilah janji yang ditulis di laman itu: menutup tidak menghapus.
    expect($this->tahun->fresh()->is_active)->toBeFalse()
        ->and(SchoolClass::query()->find($kelas->id))->not->toBeNull();
});

it('membuka kelas dengan pemilih TA di query, bukan di dalam daftar', function () {
    SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'X-1']);

    $lain = AcademicYear::factory()->untukSekolah($this->sekolah)->create(['name' => '2025/2026']);
    SchoolClass::factory()->untukTahun($lain)->create(['name' => 'XI-1']);

    // Tanpa ?ta, yang dibuka adalah tahun ajaran AKTIF.
    $this->actingAs($this->admin)
        ->get('/admin/kelas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Kelas')
            ->where('tahunDipilih.nama', '2026/2027')
            ->where('baris.total', 1)
            ->where('baris.data.0.nama', 'X-1')
        );

    // Dengan ?ta, daftarnya BENAR-BENAR berganti - bukan disaring.
    $this->actingAs($this->admin)
        ->get("/admin/kelas?ta={$lain->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tahunDipilih.nama', '2025/2026')
            ->where('baris.total', 1)
            ->where('baris.data.0.nama', 'XI-1')
        );
});

it('menghitung jumlah siswa per kelas dari sekolah yang benar', function () {
    $kelas = SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'X-1']);

    foreach (range(1, 4) as $i) {
        $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create();
        $kelas->enrollments()->create([
            'school_id' => $this->sekolah->id,
            'student_id' => $siswa->id,
            'academic_year_id' => $this->tahun->id,
            'status' => 'aktif',
        ]);
    }

    $this->actingAs($this->admin)
        ->get('/admin/kelas')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('baris.data.0.siswa', 4));
});

it('menyimpan daftar anggota kelas dan menandai yang dikeluarkan sebagai pindah', function () {
    $kelas = SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'X-1']);

    $tetap = User::factory()->siswa()->untukSekolah($this->sekolah)->create(['name' => 'Ani']);
    $keluar = User::factory()->siswa()->untukSekolah($this->sekolah)->create(['name' => 'Budi']);

    foreach ([$tetap, $keluar] as $siswa) {
        $kelas->enrollments()->create([
            'school_id' => $this->sekolah->id,
            'student_id' => $siswa->id,
            'academic_year_id' => $this->tahun->id,
            'status' => 'aktif',
        ]);
    }

    $this->actingAs($this->admin)
        ->put("/admin/kelas/{$kelas->id}/anggota", ['id' => [$tetap->id]])
        ->assertRedirect();

    // Yang keluar ditandai `pindah`, bukan dihapus: keanggotaan kelas adalah
    // riwayat, dan daftar hadir ujian menunjuk ke sana.
    expect($kelas->enrollments()->where('student_id', $tetap->id)->first()->status)->toBe('aktif')
        ->and($kelas->enrollments()->where('student_id', $keluar->id)->first()->status)->toBe('pindah');
});

it('menutup keanggotaan lama saat siswa dipindah ke kelas lain lewat layar', function () {
    $lama = SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'X-1']);
    $baru = SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'X-2']);

    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create();

    $lama->enrollments()->create([
        'school_id' => $this->sekolah->id,
        'student_id' => $siswa->id,
        'academic_year_id' => $this->tahun->id,
        'status' => 'aktif',
    ]);

    $this->actingAs($this->admin)
        ->put("/admin/kelas/{$baru->id}/anggota", ['id' => [$siswa->id]])
        ->assertRedirect();

    // Perubahan perilaku yang disengaja. Versi lama membiarkan X-1 tetap
    // aktif, sehingga siswa yang sama muncul di dua daftar hadir pada tahun
    // ajaran yang sama - keadaan yang tidak dicegah skema.
    expect($lama->enrollments()->where('student_id', $siswa->id)->first()->status)->toBe('pindah')
        ->and($baru->enrollments()->where('student_id', $siswa->id)->first()->status)->toBe('aktif');
});

it('mengaktifkan kembali baris keanggotaan siswa yang pernah keluar', function () {
    $kelas = SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'X-1']);

    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create();

    // Siswa yang pernah keluar dari kelas ini lalu dimasukkan kembali sudah
    // punya barisnya. Versi lama membandingkan SELURUH baris - bukan hanya
    // yang aktif - sehingga siswa ini terlihat "sudah ada", tidak pernah
    // ditempatkan ulang, dan statusnya tetap `pindah` selamanya.
    $kelas->enrollments()->create([
        'school_id' => $this->sekolah->id,
        'student_id' => $siswa->id,
        'academic_year_id' => $this->tahun->id,
        'status' => 'pindah',
    ]);

    $this->actingAs($this->admin)
        ->put("/admin/kelas/{$kelas->id}/anggota", ['id' => [$siswa->id]])
        ->assertRedirect();

    expect($kelas->enrollments()->where('student_id', $siswa->id)->count())->toBe(1)
        ->and($kelas->enrollments()->where('student_id', $siswa->id)->first()->status)->toBe('aktif');
});

it('membaca kelas siswa pada tahun ajaran berjalan, dan null bila belum punya', function () {
    $kelas = SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'X-1']);

    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create();
    $tanpaKelas = User::factory()->siswa()->untukSekolah($this->sekolah)->create();

    $kelas->enrollments()->create([
        'school_id' => $this->sekolah->id,
        'student_id' => $siswa->id,
        'academic_year_id' => $this->tahun->id,
        'status' => 'aktif',
    ]);

    $penempatan = app(PenempatanKelas::class);

    expect($penempatan->kelasSekarang($siswa->id)?->name)->toBe('X-1')
        // Bukan galat: siswa yang belum ditempatkan di kelas mana pun adalah
        // keadaan yang sah, dan laman Akun harus tetap bisa dibuka.
        ->and($penempatan->kelasSekarang($tanpaKelas->id))->toBeNull();
});

it('melewati keanggotaan yang sudah pindah saat membaca kelas sekarang', function () {
    $lama = SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'X-1']);
    $baru = SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'X-2']);

    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create();

    // Dua baris untuk satu siswa di satu tahun ajaran - keadaan yang TIDAK
    // dicegah skema (`class_enrollments` tidak punya unique per siswa+tahun).
    // Yang menentukan kelasnya adalah `status`, bukan urutan id atau tanggal.
    $lama->enrollments()->create([
        'school_id' => $this->sekolah->id,
        'student_id' => $siswa->id,
        'academic_year_id' => $this->tahun->id,
        'status' => 'pindah',
    ]);

    $baru->enrollments()->create([
        'school_id' => $this->sekolah->id,
        'student_id' => $siswa->id,
        'academic_year_id' => $this->tahun->id,
        'status' => 'aktif',
    ]);

    expect(app(PenempatanKelas::class)->kelasSekarang($siswa->id)?->name)->toBe('X-2');
});

it('membuka mata pelajaran beserta penugasan mengajarnya', function () {
    $mapel = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'MTK',
        'name' => 'Matematika',
    ]);
    $kelas = SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'X-1']);
    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create(['name' => 'Ani Mulyani']);

    $mapel->teachingAssignments()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'teacher_id' => $guru->id,
        'class_id' => $kelas->id,
    ]);

    $this->actingAs($this->admin)
        ->get('/admin/mapel')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Mapel')
            // Laman ini TIDAK dipaginasi: barisnya dikelompokkan tingkatnya,
            // dan kepala kelompok yang berbunyi "12 mapel" di atas tiga baris
            // adalah angka yang salah. Bentuknya karena itu daftar biasa.
            ->where('baris.0.kode', 'MTK')
            // Penugasan menempel di baris mapelnya - itulah yang membuat
            // "siapa mengajar MTK di X-1" terjawab dari satu layar.
            ->where('baris.0.penugasan.0.guru', 'Ani Mulyani')
            ->where('baris.0.penugasan.0.kelas', 'X-1')
        );
});

it('mengelompokkan mata pelajaran per tingkat dengan jumlah yang benar', function () {
    // Tiga puluh enam mapel yang bertumpuk ke bawah adalah tiga ribu piksel
    // untuk pekerjaan yang sebenarnya tiga keputusan - dan yang ketiga
    // biasanya terlewat.
    foreach (['7' => 2, '8' => 3, '9' => 1] as $tingkat => $jumlah) {
        for ($i = 1; $i <= $jumlah; $i++) {
            Subject::factory()->untukSekolah($this->sekolah)->create([
                'code' => "M{$tingkat}-{$i}",
                'name' => "Mapel {$tingkat}-{$i}",
                'grade_level' => $tingkat,
            ]);
        }
    }

    Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'MXX',
        'name' => 'Mapel Tanpa Tingkat',
        'grade_level' => null,
    ]);

    $this->actingAs($this->admin)
        ->get('/admin/mapel')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Mapel')
            // Tiga tingkat + "Tanpa tingkat" di paling bawah.
            ->has('kelompok', 4)
            ->where('kelompok.0.label', 'Tingkat 7')
            ->where('kelompok.0.kunci', '7')
            ->where('kelompok.0.jumlah', 2)
            ->where('kelompok.1.label', 'Tingkat 8')
            ->where('kelompok.1.jumlah', 3)
            ->where('kelompok.2.label', 'Tingkat 9')
            ->where('kelompok.2.jumlah', 1)
            // Tidak dilebur ke salah satu tingkat: kelas tanpa tingkat bukan
            // tingkat yang belum diberi nama, ia data yang belum lengkap - dan
            // meleburnya menyembunyikan justru hal itu.
            ->where('kelompok.3.label', 'Tanpa tingkat')
            ->where('kelompok.3.kunci', 'tanpa')
            ->where('kelompok.3.tingkat', null)
            ->where('kelompok.3.jumlah', 1)
            // Isinya ikut, bukan hanya hitungannya: tanpa barisnya, kepala
            // kelompok yang bisa dilipat adalah angkanya saja.
            ->has('kelompok.0.baris', 2)
            ->where('kelompok.0.baris.0.kode', 'M7-1')
            ->where('kelompok.3.baris.0.kode', 'MXX')
        );
});

it('menghitung penugasan tiap kelompok, bukan mapel yang punya penugasan', function () {
    // Pertanyaan kepalanya "berapa izin mengajar di tingkat ini". Satu mapel
    // dengan dua kelas berarti dua izin, dan angka yang berbunyi "1" membuat
    // Admin mengira satu kelasnya belum tertugaskan.
    $mapel = Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'MTK',
        'grade_level' => '7',
    ]);

    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create();
    $lain = User::factory()->guru()->untukSekolah($this->sekolah)->create();

    foreach ([$guru, $lain] as $i => $g) {
        $kelas = SchoolClass::factory()->untukTahun($this->tahun)->create(['name' => 'X-'.$i]);

        $mapel->teachingAssignments()->create([
            'school_id' => $this->sekolah->id,
            'academic_year_id' => $this->tahun->id,
            'teacher_id' => $g->id,
            'class_id' => $kelas->id,
        ]);
    }

    $this->actingAs($this->admin)
        ->get('/admin/mapel')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kelompok.0.jumlah', 1)
            ->where('kelompok.0.penugasan', 2)
            // "Tanpa tingkat" tetap ada meski kosong: menyembunyikannya saat
            // kosong menghilangkan satu-satunya tempat Admin menyadari bahwa
            // tidak ada mapel yang bertingkat kosong.
            ->where('kelompok.1.label', 'Tanpa tingkat')
            ->where('kelompok.1.jumlah', 0)
            ->where('kelompok.1.penugasan', 0)
        );
});

it('mengelompokkan hasil pencarian, bukan menampilkannya kosong', function () {
    // Hasil pencarian yang tersembunyi di balik lipatan terbaca sebagai
    // "tidak ketemu". Yang membedakannya adalah `kelompok`, yang selalu
    // dikirim lengkap - layar yang memutuskan kelompok mana yang dibuka.
    Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'MTK',
        'name' => 'Matematika',
        'grade_level' => '7',
    ]);
    Subject::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'BIN',
        'name' => 'Bahasa Indonesia',
        'grade_level' => '8',
    ]);

    $this->actingAs($this->admin)
        ->get('/admin/mapel?cari=MTK')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('jumlah', 1)
            // Tingkat 7 + "Tanpa tingkat", yang selalu ikut. Yang TIDAK ikut
            // adalah Tingkat 8: kelompoknya dibangun dari hasil saringannya,
            // dan kepala kelompok kosong di daftar hasil pencarian terbaca
            // sebagai tingkat yang kehilangan mapelnya.
            ->has('kelompok', 2)
            ->where('kelompok.0.label', 'Tingkat 7')
            ->where('kelompok.0.jumlah', 1)
            ->where('kelompok.0.baris.0.kode', 'MTK')
            ->where('kelompok.1.label', 'Tanpa tingkat')
            ->where('kelompok.1.jumlah', 0)
        );
});

it('membuka ruang dengan ringkasan dari seluruh ruang, bukan dari satu halaman', function () {
    Room::factory()->untukSekolah($this->sekolah)->create([
        'code' => 'R-01',
        'capacity' => 32,
        'device_count' => 32,
    ]);
    Room::factory()->untukSekolah($this->sekolah)->byod()->create([
        'code' => 'R-04',
        'capacity' => 60,
    ]);
    Room::factory()->untukSekolah($this->lain)->create([
        'code' => 'R-99',
        'capacity' => 999,
        'device_count' => 999,
    ]);

    $this->actingAs($this->admin)
        ->get('/admin/ruang')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Ruang')
            ->where('ringkasan.kapasitas', 92)
            ->where('ringkasan.pc', 32)
            ->where('ringkasan.byod', 1)
            ->where('jumlah', 2)
        );
});

it('membuka rincian ruang beserta tautan pintunya', function () {
    $ruang = Room::factory()->untukSekolah($this->sekolah)->create(['code' => 'R-01']);

    RoomEntryLink::factory()->untukRuang($ruang)->create(['use_count' => 2]);

    $this->actingAs($this->admin)
        ->get("/admin/ruang/{$ruang->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/RuangDetail')
            ->where('ruang.kode', 'R-01')
            ->has('tautan', 1)
            ->where('tautan.0.dipakai', 2)
            ->where('aktif.dipakai', 2)
        );
});

it('menerbitkan tautan ruang dan tidak lagi mengirim kredensial apa pun lewat flash', function () {
    $ruang = Room::factory()->untukSekolah($this->sekolah)->create(['code' => 'R-01']);

    $this->actingAs($this->admin)
        ->post("/admin/ruang/{$ruang->id}/terbitkan")
        ->assertRedirect()
        ->assertSessionHas('sukses')
        // Kode pengawas TIDAK lagi terbit di sini sejak 2026-11-07: ia
        // menempel pada ORANG, dan terbit di laman Kepanitiaan. Kalau salah
        // satu dari dua assertion di bawah ini lolos, berarti laman ruang
        // diam-diam menerbitkan kredensial lagi - dan kredensial yang bisa
        // menerbitkan dirinya sendiri dari kartu pintu adalah persis
        // kebocoran yang perubahan ini tutup.
        ->assertSessionMissing('kode_pengawas')
        ->assertSessionMissing('slug_baru');

    // Tautannya sendiri tetap terbit, dan slug-nya bisa dibaca dari rincian
    // ruangnya - `aktif.url` sudah cukup, sehingga `slug_baru` yang dulu
    // dikirim lewat flash tidak punya pembaca lagi.
    $tautan = RoomEntryLink::query()->first();

    expect($tautan->slug)->not->toBeEmpty()
        ->and($tautan->berlaku())->toBeTrue();
});

it('mencabut tautan lama saat menerbitkan yang baru', function () {
    $ruang = Room::factory()->untukSekolah($this->sekolah)->create();

    $this->actingAs($this->admin)->post("/admin/ruang/{$ruang->id}/terbitkan");
    $pertama = RoomEntryLink::query()->orderBy('id')->first();

    $this->actingAs($this->admin)->post("/admin/ruang/{$ruang->id}/terbitkan");
    $kedua = RoomEntryLink::query()->orderByDesc('id')->first();

    // Tanpa pencabutan otomatis, kartu pintu yang difoto orang lain tetap
    // berfungsi, dan tidak ada yang tahu berapa banyak tautan sah yang
    // beredar untuk satu pintu.
    expect($pertama->fresh()->revoked_at)->not->toBeNull()
        ->and($kedua->revoked_at)->toBeNull()
        ->and($kedua->id)->not->toBe($pertama->id);
});

it('menolak ruang ber-PC yang lebih banyak daripada kapasitasnya', function () {
    $this->actingAs($this->admin)
        ->post('/admin/ruang', [
            'code' => 'R-77',
            'capacity' => 10,
            'device_count' => 32,
            'status' => 'aktif',
        ])
        ->assertSessionHasErrors('device_count');
});

it('menolak guru menambah master data meski route-nya ada', function () {
    // Route tidak punya middleware peran - Policy yang memutuskan. Guru boleh
    // MELIHAT kelas dan mapel (itulah batas bank soalnya di Fase 4), tetapi
    // tidak boleh mengubahnya.
    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create();

    $this->actingAs($guru)->get('/admin/kelas')->assertOk();

    $this->actingAs($guru)
        ->post('/admin/mapel', [
            'code' => 'BARU',
            'name' => 'Mapel Selundupan',
            'status' => 'aktif',
        ])
        ->assertForbidden();
});

it('membagikan nama tahun ajaran aktif ke setiap laman', function () {
    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tahunAktif', '2026/2027'));
});
