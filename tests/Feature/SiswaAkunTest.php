<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Semester;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Support\PembatasLaju;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

/**
 * Tab "Akun" konsol siswa - `/siswa/akun` dan `PUT /siswa/akun/sandi`.
 *
 * =====================================================================
 * Laporan yang melahirkan laman ini
 * =====================================================================
 * "menu akun tidak bisa di klik". Tabnya dirender sebagai teks abu sejak
 * laman itu belum ada, dan kini keempat tab menunjuk laman yang benar-benar
 * ada. Test pertama di bawah menjaga hal itu dari sisi SERVER (rutenya hidup
 * dan mengirim prop yang benar); sisanya menjaga perilaku ganti sandi.
 *
 * =====================================================================
 * Yang paling menentukan di berkas ini bukan "apakah sandinya berganti"
 * =====================================================================
 * Tiga hal di bawah lebih mudah rusak diam-diam daripada ganti sandi itu
 * sendiri, dan masing-masing punya testnya:
 *
 *   1. **Sandi tidak boleh bocor ke `_old_input`.** Nama field sengaja
 *      bahasa Inggris karena `Handler::$dontFlash` hanya memuat tiga nama
 *      itu; mengubahnya menjadi `sandi_lama` akan menulis ketiga sandi
 *      sebagai teks terbuka ke dalam sesi, dan tidak ada yang gagal.
 *   2. **Percobaan yang BENAR pada percobaan keenam tetap ditolak.** Pembatas
 *      laju yang hanya memperlambat lolos dari test ini - dan di laman ganti
 *      sandi, "hanya memperlambat" berarti pencuri sesi boleh terus menebak.
 *   3. **Sesi lain benar-benar terputus.** `logoutOtherDevices()` tidak
 *      memutus apa pun di aplikasi ini (tidak ada `AuthenticateSession`),
 *      jadi memakainya alih-alih menghapus barisnya akan lulus review sambil
 *      tidak mengubah apa pun.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.akun',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'Ganjil',
    ]);

    // Sandi default factory adalah `User::SANDI_BAWAAN`, sehingga setiap
    // siswa di berkas ini MULAI sebagai pemegang sandi bawaan - keadaan yang
    // justru ingin diuji, bukan yang perlu dihindari.
    $this->siswa = User::factory()->siswa()->untukSekolah($this->sekolah)->create([
        'username' => '2024001',
        'name' => 'Ani Lestari',
    ]);

    StudentProfile::create([
        'school_id' => $this->sekolah->id,
        'user_id' => $this->siswa->id,
        'nis' => '2024001',
        'nisn' => '0012345678',
    ]);

    // Hitungan pembatas laju disimpan di cache, dan cache TIDAK dibersihkan
    // antar test di berkas ini. Tanpa baris ini, test keenam di bawah akan
    // mewarisi percobaan test kelima dan gagal di tempat yang menyesatkan.
    PembatasLaju::bersihkan(PembatasLaju::SANDI, PembatasLaju::kunciSandi($this->siswa->id));
});

/* =====================================================================
 | Laman Akun
 |===================================================================== */

it('membuka laman akun dengan identitas siswa dan penanda sandi bawaan', function () {
    masukUji($this, $this->siswa)
        ->get('/siswa/akun')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Siswa/Akun')
            ->where('nama', 'Ani Lestari')
            ->where('username', '2024001')
            ->where('nis', '2024001')
            ->where('nisn', '0012345678')
            ->where('sekolah', 'SMK Negeri 1')
            // Belum punya keanggotaan kelas - dan laman ini TIDAK boleh gagal
            // karena itu. Kelas dibaca dari keanggotaan kelas, bukan dari
            // penempatan ujian, justru supaya keadaan ini sah.
            ->where('kelas', null)
            ->where('sandi_bawaan', true)
        );
});

it('membaca kelas dari keanggotaan kelas, bukan dari penempatan ujian', function () {
    $kelas = SchoolClass::factory()
        ->untukTahun($this->tahun)->create(['name' => 'X-1']);

    $kelas->enrollments()->create([
        'school_id' => $this->sekolah->id,
        'student_id' => $this->siswa->id,
        'academic_year_id' => $this->tahun->id,
        'status' => 'aktif',
    ]);

    masukUji($this, $this->siswa)
        ->get('/siswa/akun')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('kelas', 'X-1'));
});

it('menandai sandi bukan lagi sandi bawaan sesudah diganti', function () {
    masukUji($this, $this->siswa)
        ->put('/siswa/akun/sandi', [
            'current_password' => 'password',
            'password' => 'sandi-baru-2026',
            'password_confirmation' => 'sandi-baru-2026',
        ])
        ->assertRedirect();

    masukUji($this, $this->siswa)
        ->get('/siswa/akun')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sandi_bawaan', false));
});

/* =====================================================================
 | Ganti sandi
 |===================================================================== */

it('mengganti sandi dan mencatatnya di log aktivitas', function () {
    masukUji($this, $this->siswa)
        ->put('/siswa/akun/sandi', [
            'current_password' => 'password',
            'password' => 'sandi-baru-2026',
            'password_confirmation' => 'sandi-baru-2026',
        ])
        ->assertRedirect()
        ->assertSessionHas('sukses', 'Sandi berhasil diganti.');

    expect(Hash::check('sandi-baru-2026', $this->siswa->fresh()->password))->toBeTrue();

    // Log ditulis dengan nama AKUN sebagai entitasnya, sehingga riwayatnya
    // bisa dibaca per akun - bukan sekadar "seseorang mengganti sandi".
    expect(DB::table('activity_logs')->where('action', 'Mengganti sandi sendiri')->count())->toBe(1);
});

it('menolak sandi lama yang salah dan tidak mengubah sandinya', function () {
    $sebelum = $this->siswa->password;

    masukUji($this, $this->siswa)
        ->put('/siswa/akun/sandi', [
            'current_password' => 'bukan-sandi-saya',
            'password' => 'sandi-baru-2026',
            'password_confirmation' => 'sandi-baru-2026',
        ])
        ->assertSessionHasErrors('current_password');

    expect($this->siswa->fresh()->password)->toBe($sebelum)
        ->and(Hash::check('password', $this->siswa->fresh()->password))->toBeTrue();
});

it('menolak sandi baru yang tidak diulang sama, terlalu pendek, atau sama dengan yang lama', function () {
    // Tidak diulang sama.
    masukUji($this, $this->siswa)
        ->put('/siswa/akun/sandi', [
            'current_password' => 'password',
            'password' => 'sandi-baru-2026',
            'password_confirmation' => 'sandi-baru-2027',
        ])
        ->assertSessionHasErrors('password');

    // Terlalu pendek - `min:6`, sama dengan aturan laman Pengguna Admin.
    masukUji($this, $this->siswa)
        ->put('/siswa/akun/sandi', [
            'current_password' => 'password',
            'password' => '12345',
            'password_confirmation' => '12345',
        ])
        ->assertSessionHasErrors('password');

    // Sama dengan sandi lama: `different:current_password`. Tanpa aturan ini,
    // siswa bisa "mengganti" sandinya ke sandi bawaan itu sendiri dan
    // peringatan di laman Akun akan berbunyi selamanya.
    masukUji($this, $this->siswa)
        ->put('/siswa/akun/sandi', [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertSessionHasErrors('password');

    expect(Hash::check('password', $this->siswa->fresh()->password))->toBeTrue();
});

it('TIDAK menulis sandi apa pun ke _old_input saat validasinya gagal', function () {
    $respon = masukUji($this, $this->siswa)
        ->put('/siswa/akun/sandi', [
            'current_password' => 'password',
            'password' => 'sandi-baru-2026',
            'password_confirmation' => 'sandi-baru-2027',
        ]);

    $respon->assertSessionHasErrors('password');

    // Inilah gerbang bagi cacat yang dijelaskan di docblock
    // `GantiSandiRequest`: nama field SENGAJA bahasa Inggris karena
    // `Handler::$dontFlash` hanya memuat ketiga nama itu. Dengan nama
    // `sandi_lama`/`sandi_baru`, ketiga sandi di bawah akan ada di sini -
    // sebagai teks terbuka yang dikirim kembali ke peramban.
    $lama = $respon->getSession()->get('_old_input') ?? [];

    expect($lama)->not->toHaveKey('password')
        ->and($lama)->not->toHaveKey('current_password')
        ->and($lama)->not->toHaveKey('password_confirmation');

    // Dan nilainya memang tidak muncul di mana pun pada muatan sesi - bukan
    // hanya tidak di kunci yang tiga itu.
    expect(json_encode($respon->getSession()->all()))->not->toContain('sandi-baru-2026');
});

/* =====================================================================
 | Pembatas laju - per akun, dan terkunci bukan diperlambat
 |===================================================================== */

it('menolak percobaan keenam walau sandi lamanya BENAR', function () {
    // Lima kesalahan sampai ke controller: galatnya galat validasi sandi lama,
    // bukan pesan pembatas laju. Ini yang membuktikan batasnya lima, bukan nol.
    for ($i = 0; $i < PembatasLaju::SANDI_PER_SISWA; $i++) {
        $respon = masukUji($this, $this->siswa)
            ->put('/siswa/akun/sandi', [
                'current_password' => 'salah-terus',
                'password' => 'sandi-baru-2026',
                'password_confirmation' => 'sandi-baru-2026',
            ]);

        // Lima percobaan pertama sampai ke CONTROLLER: galatnya galat
        // validasi sandi lama, BUKAN pesan pembatas laju. Ini yang
        // membuktikan batasnya lima, bukan nol.
        $respon->assertSessionHasErrors('current_password');
        $respon->assertSessionMissing('galat');
    }

    // Yang keenam: sandi lamanya BENAR, dan ia tetap ditolak.
    //
    // Ini test yang paling menentukan di berkas ini. Pembatas laju yang hanya
    // MEMPERLAMBAT akan meloloskan baris ini: lima tebakan salah diikuti satu
    // yang kebetulan benar, dan sandinya berganti. Justru itu yang harus
    // dicegah - sesi yang dicuri dipakai mempelajari sandi korban.
    $keenam = masukUji($this, $this->siswa)
        ->put('/siswa/akun/sandi', [
            'current_password' => 'password',
            'password' => 'sandi-baru-2026',
            'password_confirmation' => 'sandi-baru-2026',
        ]);

    $keenam->assertRedirect(route('siswa.akun'));
    $keenam->assertSessionHas('galat', PembatasLaju::PESAN_SANDI);

    // Dan buktinya bukan sekadar pesannya: sandinya memang tidak berganti.
    expect(Hash::check('password', $this->siswa->fresh()->password))->toBeTrue();
});

it('membersihkan hitungannya sesudah sandi benar-benar berganti', function () {
    for ($i = 0; $i < PembatasLaju::SANDI_PER_SISWA - 1; $i++) {
        masukUji($this, $this->siswa)->put('/siswa/akun/sandi', [
            'current_password' => 'salah-terus',
            'password' => 'sandi-baru-2026',
            'password_confirmation' => 'sandi-baru-2026',
        ]);
    }

    masukUji($this, $this->siswa)
        ->put('/siswa/akun/sandi', [
            'current_password' => 'password',
            'password' => 'sandi-baru-2026',
            'password_confirmation' => 'sandi-baru-2026',
        ])
        ->assertSessionHas('sukses', 'Sandi berhasil diganti.');

    // Sesudah berhasil, jatahnya penuh kembali: dua kesalahan berikutnya
    // masih dijawab sebagai galat validasi, bukan sebagai pesan pembatas laju.
    // Tanpa `PembatasLaju::bersihkan()`, empat salah + satu berhasil + satu
    // salah sudah mencapai batas lima.
    for ($i = 0; $i < 2; $i++) {
        $respon = masukUji($this, $this->siswa)->put('/siswa/akun/sandi', [
            'current_password' => 'salah-terus',
            'password' => 'sandi-lain-2026',
            'password_confirmation' => 'sandi-lain-2026',
        ]);

        expect($respon->getSession()->get('galat'))->toBeNull();
    }
});

/* =====================================================================
 | Sesi lain diputus
 |===================================================================== */

it('memutus sesi lain dan mempertahankan sesi yang sedang dipakai', function () {
    // `phpunit.xml` memakai driver `array`, yang tidak menyimpan sesi di
    // tabel `sessions` sama sekali. Tanpa baris ini, test di bawah akan lulus
    // sambil menguji `SesiPengguna` yang sejak awal mengembalikan 0.
    config(['session.driver' => 'database']);

    // Satu permintaan lebih dulu: ia membuat baris sesi untuk ponsel ini,
    // sehingga yang diuji benar-benar "satu bertahan, sisanya hilang".
    masukUji($this, $this->siswa)->get('/siswa/akun')->assertOk();

    DB::table('sessions')->insert([
        [
            'id' => 'sesi-ponsel-lain',
            'user_id' => $this->siswa->id,
            'payload' => 'x',
            'last_activity' => now()->timestamp,
        ],
        [
            'id' => 'sesi-laptop',
            'user_id' => $this->siswa->id,
            'payload' => 'x',
            'last_activity' => now()->timestamp,
        ],
    ]);

    masukUji($this, $this->siswa)
        ->put('/siswa/akun/sandi', [
            'current_password' => 'password',
            'password' => 'sandi-baru-2026',
            'password_confirmation' => 'sandi-baru-2026',
        ])
        ->assertSessionHas('sukses', 'Sandi berhasil diganti.');

    expect(DB::table('sessions')->whereIn('id', ['sesi-ponsel-lain', 'sesi-laptop'])->count())->toBe(0)
        // Dan sesi yang sedang dipakai TIDAK ikut terhapus. Inilah alasan
        // `regenerate()` dipanggil SEBELUM id-nya dibaca: id yang dibaca
        // sebelum regenerate sudah tidak menunjuk baris mana pun, sehingga
        // penyaring `id != $sesiSekarang` tidak menyaring apa pun.
        ->and(DB::table('sessions')->where('user_id', $this->siswa->id)->count())->toBe(1);
});

it('tidak menyentuh sesi siswa lain di sekolah yang sama', function () {
    config(['session.driver' => 'database']);

    $lain = User::factory()->siswa()->untukSekolah($this->sekolah)->create([
        'username' => '2024002',
    ]);

    DB::table('sessions')->insert([
        'id' => 'sesi-siswa-lain',
        'user_id' => $lain->id,
        'payload' => 'x',
        'last_activity' => now()->timestamp,
    ]);

    masukUji($this, $this->siswa)
        ->put('/siswa/akun/sandi', [
            'current_password' => 'password',
            'password' => 'sandi-baru-2026',
            'password_confirmation' => 'sandi-baru-2026',
        ])
        ->assertSessionHas('sukses');

    expect(DB::table('sessions')->where('id', 'sesi-siswa-lain')->exists())->toBeTrue();
});

/* =====================================================================
 | Peran lain, dan siswa lain
 |===================================================================== */

it('menolak peran selain Siswa pada kedua verb', function () {
    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create(['username' => 'guru.akun']);
    $pengawas = User::factory()->pengawas()->untukSekolah($this->sekolah)->create(['username' => 'pengawas.akun']);

    foreach ([$this->admin, $guru, $pengawas] as $bukanSiswa) {
        masukUji($this, $bukanSiswa)->get('/siswa/akun')->assertForbidden();

        masukUji($this, $bukanSiswa)->put('/siswa/akun/sandi', [
            'current_password' => 'password',
            'password' => 'sandi-baru-2026',
            'password_confirmation' => 'sandi-baru-2026',
        ])->assertForbidden();
    }

    // Dan yang menentukan: siswanya sendiri tetap masuk. Tanpa baris ini,
    // gerbang yang menolak SEMUA orang akan lulus test di atas.
    masukUji($this, $this->siswa)->get('/siswa/akun')->assertOk();

    // Sandi Admin pun tidak tersentuh - 403 di atas bukan sekadar status.
    expect(Hash::check('password', $this->admin->fresh()->password))->toBeTrue();
});

it('mengubah sandi siswa yang bersangkutan saja', function () {
    $lain = User::factory()->siswa()->untukSekolah($this->sekolah)->create([
        'username' => '2024002',
    ]);

    masukUji($this, $lain)
        ->put('/siswa/akun/sandi', [
            'current_password' => 'password',
            'password' => 'sandi-baru-2026',
            'password_confirmation' => 'sandi-baru-2026',
        ])
        ->assertSessionHas('sukses');

    expect(Hash::check('sandi-baru-2026', $lain->fresh()->password))->toBeTrue()
        ->and(Hash::check('password', $this->siswa->fresh()->password))->toBeTrue();
});
