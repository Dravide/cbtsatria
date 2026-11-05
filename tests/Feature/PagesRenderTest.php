<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;

/**
 * Laman Fase 1 benar-benar bisa dibuka.
 *
 * Backend yang hijau tanpa layar bukan fase yang selesai. Test ini memastikan
 * setiap laman Fase 1 merender komponen Inertia yang benar dan membawa data
 * yang dijanjikan - bukan sekadar status 200.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.uji',
        'password' => 'rahasia-benar',
    ]);
});

it('menampilkan laman login', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Login'));
});

it('menampilkan laman lupa password', function () {
    $this->get('/lupa-password')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/LupaPassword'));
});

it('menampilkan dashboard Admin dengan angka dari sekolahnya sendiri', function () {
    // Dua guru, satu pengawas di sekolah ini; satu siswa di sekolah lain yang
    // TIDAK boleh ikut terhitung.
    User::factory()->guru()->untukSekolah($this->sekolah)->count(2)->create();
    User::factory()->pengawas()->untukSekolah($this->sekolah)->create();

    $sekolahLain = School::factory()->create();
    User::factory()->siswa()->untukSekolah($sekolahLain)->create();

    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Dashboard')
            ->where('sekolah.nama', 'SMK Negeri 1')
            ->where('sekolah.npsn', $this->sekolah->npsn)
            ->where('ringkasan.guru', 2)
            ->where('ringkasan.siswa', 0)
            ->where('ringkasan.pengawas', 1)
            ->where('ringkasan.ruang', 0)
        );
});

/*
|--------------------------------------------------------------------------
| Test "kerangka peran non-Admin" DIHAPUS, dan alasannya bukan kelalaian
|--------------------------------------------------------------------------
| Test itu dulu mengunci `Kerangka` untuk Guru dan Siswa. Keduanya keluar -
| Guru di Fase 4, Siswa di Fase 7 - dan sejak itu daftar `->with()`-nya
| kosong. Daftar data yang kosong bukan test yang lulus tanpa baris: PHPUnit
| melaporkannya sebagai galat tingkat RUNNER ("Empty data set provided by
| data provider"), dan galat runner membuat seluruh berkas dilaporkan GAGAL
| meski 29 test di dalamnya hijau. `->todo()` tidak menolong: providernya
| sudah dievaluasi saat test ditemukan, sebelum penanda apa pun dibaca.
|
| Menghapusnya bukan kehilangan cakupan. `Kerangka` kini tidak dirender rute
| mana pun - `grep -rn "Kerangka'" app/ routes/` tidak menemukan apa-apa -
| jadi test itu menjaga laman yang tidak dapat dibuka siapa pun. Yang benar-
| benar menjaga peran-peran itu adalah test penggantinya di bawah: satu test
| per laman yang SUNGGUH dirender, bukan satu test untuk daftar peran yang
| tinggal menunggu fase berikutnya.
*/

/*
|--------------------------------------------------------------------------
| Guru keluar dari Kerangka di Fase 4
|--------------------------------------------------------------------------
| `/guru` berhenti menjadi halaman Kerangka dan menjadi konsol Guru yang
| sesungguhnya - persis seperti `/pengawas` di Fase 6. Alasan keluarnya sama:
| test yang mengunci keadaan fase sebelumnya akan pecah JUSTRU karena fasenya
| tiba, dan test yang pecah karena pekerjaannya selesai adalah test yang
| salah tempat.
|
| Yang menggantikannya bukan penghapusan, melainkan test yang memeriksa
| komponen yang BENAR-BENAR dirender sekarang. Tanpa penggantinya, satu baris
| yang hilang dari daftar berarti rute itu berhenti diperiksa sama sekali -
| dan rute yang tidak diperiksa adalah rute yang bisa menjadi 500 tanpa ada
| yang tahu.
*/
it('menampilkan konsol Guru yang sesungguhnya', function () {
    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'u.guru.konsol',
    ]);

    $this->actingAs($guru)
        ->get('/guru')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guru/Dashboard')
        );
});

/*
|--------------------------------------------------------------------------
| Siswa keluar dari Kerangka di Fase 7
|--------------------------------------------------------------------------
| Sama seperti Guru di atas, dan dengan konsekuensi yang sama: test-nya
| diganti, bukan dihapus.
|
| Perhatikan middleware `siswa` diuji terpisah di SiswaTest - yang diperiksa
| di sini hanya bahwa rutenya hidup, merender komponen yang benar, dan
| membawa data siswa itu SENDIRI. Siswa tanpa penempatan apa pun adalah
| keadaan yang sah dan justru yang paling sering terjadi pada hari pertama:
| akunnya ada, jadwalnya belum diisi Admin.
*/
it('menampilkan jadwal siswa dengan komponen yang sesungguhnya', function () {
    $siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create([
        'username' => 'u.siswa.jadwal',
    ]);

    $this->actingAs($siswa)
        ->get('/siswa')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Siswa/Jadwal')
            ->where('nama', $siswa->name)
            ->where('jadwal', [])
            ->where('utama', null)
        );
});

/*
|--------------------------------------------------------------------------
| Laman Fase 4 yang tidak punya data
|--------------------------------------------------------------------------
| Bank soal, paket, impor, dan pratinjau diperiksa isinya di BankSoalTest,
| PaketSoalTest, ImporSoalTest, dan PratinjauSoalTest - masing-masing
| menyiapkan guru, mapel, dan penugasannya sendiri.
|
| Yang diperiksa di sini adalah pertanyaan yang berbeda: apakah rutenya masih
| ADA dan menunjuk komponen yang sama. Dua laman bertaut (`/guru/bank-soal`
| dan `/guru/paket`) tidak bisa diperiksa di sini karena keduanya menuntut
| penugasan mengajar lebih dulu; keduanya diperiksa di BankSoalTest dan
| PaketSoalTest. Yang bisa berdiri tanpa data apa pun adalah impor - dan
| laman impor justru HARUS bisa dibuka Guru yang belum punya satu soal pun,
| karena di situlah ia memulainya.
*/
it('membuka laman impor soal tanpa satu soal pun', function () {
    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create([
        'username' => 'u.guru.impor',
    ]);

    $this->actingAs($guru)
        ->get('/guru/bank-soal/impor')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Guru/BankSoal/Impor')
        );
});

it('menolak laman konsol Guru bagi tamu', function () {
    $this->get('/guru')->assertRedirect('/login');
    $this->get('/guru/bank-soal/impor')->assertRedirect('/login');
});

/*
|--------------------------------------------------------------------------
| Pengawas keluar dari Kerangka di Fase 6
|--------------------------------------------------------------------------
| `/pengawas` berhenti menjadi halaman Kerangka dan menjadi beranda pengawas
| yang sesungguhnya. Ia karena itu keluar dari daftar di atas: test yang
| mengunci keadaan Fase 1 akan pecah justru karena fasenya tiba, dan test
| yang pecah karena pekerjaannya selesai adalah test yang salah tempat.
|
| Yang diperiksa di sini BUKAN bahwa lamannya 200 - melainkan bahwa propnya
| ada dan bentuknya benar. Pengawas tanpa penugasan menerima daftar KOSONG,
| bukan galat: beranda yang menolak dibuka bagi pengawas yang belum
| ditugaskan adalah beranda yang menyalahkan orang yang tidak berbuat salah.
*/
it('membuka beranda pengawas sebagai komponen Pengawas/RuangTugas', function () {
    $pengawas = User::factory()->pengawas()->untukSekolah($this->sekolah)->create([
        'username' => 'pengawas.beranda',
    ]);

    $this->actingAs($pengawas)
        ->get('/pengawas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Pengawas/RuangTugas')
            ->where('pelaku', 'akun')
            ->has('ruang', 0)
        );
});

it('membuka pintu ruang tanpa akun, dan menolak slug yang tidak ada', function () {
    // Pintu ruang SENGAJA di luar middleware auth: yang membukanya belum
    // tentu punya akun, dan itu alasan keberadaannya. Slug yang tidak
    // dikenal berakhir 404 - bukan halaman kosong yang membuat pengawas di
    // depan pintu mengira tautannya salah salin.
    $this->get('/p/pintu-yang-tidak-pernah-diterbitkan')->assertNotFound();
});

it('membagikan identitas pengguna ke setiap laman', function () {
    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.pengguna.nama', $this->admin->name)
            ->where('auth.pengguna.peran', 'admin')
            ->where('auth.pengguna.peran_label', 'Admin')
        );
});

it('mengalihkan pengguna yang sudah masuk menjauh dari laman tamu', function () {
    RateLimiter::clear('login:admin.uji|127.0.0.1');

    // Middleware guest mengarahkan ke rute bernama `dashboard`, yang lalu
    // bercabang menurut peran. Dua lompatan, bukan satu - dan itu memang
    // rancangannya: satu rute tujuan, bukan empat rute yang dipilih di UI.
    $this->actingAs($this->admin)->get('/login')->assertRedirect('/dashboard');

    $this->actingAs($this->admin)->get('/dashboard')->assertRedirect('/admin');
});

/*
|--------------------------------------------------------------------------
| Laman Fase 2
|--------------------------------------------------------------------------
| Yang diperiksa di sini adalah bahwa laman-lamanya BENAR-BENAR ADA dan
| merender komponen yang benar. Isinya diperiksa di MasterDataTest, yang
| menyiapkan master data lengkap untuk setiap kasus.
|
| Pemisahan itu disengaja: test ini akan tetap lulus meski data berubah -
| dan justru itu gunanya. Ia menjawab pertanyaan yang berbeda: "apakah
| rutenya masih ada dan masih menunjuk layar yang sama". Test yang mencampur
| keduanya gagal karena alasan yang salah, dan orang berhenti mempercayainya.
*/
it('membuka setiap laman master data Fase 2', function (string $jalur, string $komponen) {
    $this->actingAs($this->admin)
        ->get($jalur)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component($komponen));
})->with([
    ['/admin/sekolah', 'Admin/Sekolah'],
    ['/admin/pengaturan', 'Admin/Pengaturan'],
    ['/admin/log', 'Admin/Log'],
    ['/admin/pengguna', 'Admin/Pengguna'],
    ['/admin/tahun-ajaran', 'Admin/TahunAjaran'],
    ['/admin/kelas', 'Admin/Kelas'],
    ['/admin/mapel', 'Admin/Mapel'],
    ['/admin/ruang', 'Admin/Ruang'],
]);

it('mengirim nama tahun ajaran aktif ke laman non-Admin juga', function () {
    // Year ajaran aktif dibuat di sini, bukan di beforeEach: test ini
    // memeriksa bahwa nilainya benar-benar mengalir, dan sekolah tanpa tahun
    // ajaran aktif adalah kasus yang berbeda - "TA belum diatur" di bar atas.
    AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create();

    $this->actingAs($guru)
        ->get('/guru')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tahunAktif', '2026/2027'));
});

it('tidak mengirim tahun ajaran aktif ke tamu di layar login', function () {
    AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create();

    // Bar atas konsol menampilkannya di semua laman - tetapi hanya untuk
    // pengguna yang sudah masuk. Tamu tidak membayar query itu, dan tidak
    // menerima nama tahun ajaran sekolah mana pun.
    $this->get('/login')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tahunAktif', null)
            ->where('auth.pengguna', null)
        );
});

it('menolak laman master data bagi tamu', function (string $jalur) {
    $this->get($jalur)->assertRedirect('/login');
})->with([
    '/admin/sekolah',
    '/admin/pengaturan',
    '/admin/log',
    '/admin/pengguna',
    '/admin/tahun-ajaran',
    '/admin/kelas',
    '/admin/mapel',
    '/admin/ruang',
]);
