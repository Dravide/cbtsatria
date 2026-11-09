<?php

use App\Domain\Admin\Services\UserRegistrar;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ganti username - jalur yang dibuka karena NIS dan NIP memang salah ketik.
 *
 * =====================================================================
 * Janji yang dijaga berkas ini
 * =====================================================================
 * Username adalah satu-satunya identitas yang diketik saat login, dan ia
 * unik GLOBAL sementara NIS hanya unik per sekolah. Dua hal itu yang membuat
 * penggantian username bukan sekadar "menyimpan satu kolom lagi":
 *
 *   1. BENTROKAN ANTAR SEKOLAH HARUS TERLIHAT. Sekolah A tidak akan pernah
 *      melihat akun sekolah B di layarnya, jadi pemeriksaan yang ter-scope
 *      tenant akan melaporkan "bebas" untuk username yang sudah dipakai -
 *      lalu gagal di tingkat basis data dengan galat yang membingungkan.
 *
 *   2. AKUN YANG SEDANG DIUBAH TIDAK BOLEH MENGHALANGI DIRINYA SENDIRI.
 *      Menyimpan tanpa mengubah username adalah hal yang wajar; menolaknya
 *      berarti form yang tidak mengubah apa pun berbunyi sebagai galat.
 *
 *   3. SANDI TIDAK PERNAH TERSENTUH. Yang diganti adalah nama akunnya, bukan
 *      kuncinya - dan sandi yang ikut ter-reset berarti seluruh siswa
 *      kehilangan aksesnya tanpa satu pun permintaan.
 *
 *   4. YANG DICATAT LOG ADALAH USERNAME LAMA. Saat siswa melapor tidak bisa
 *      masuk karena masih mengetik nomor dari kartunya, yang dicari Admin
 *      adalah username lama itu - dan setelah tersimpan, ia tidak ada lagi di
 *      mana pun selain log.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['code' => 'smkn1']);
    $this->lain = School::factory()->create(['code' => 'smkn2']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.username',
    ]);

    $this->adminLain = User::factory()->admin()->untukSekolah($this->lain)->create([
        'username' => 'admin.sekolah.lain',
    ]);

    $this->siswa = User::factory()->untukSekolah($this->sekolah)->create([
        'role' => 'student',
        'name' => 'Ani Mulyani',
        'username' => '20240017',
    ]);

    StudentProfile::create([
        'school_id' => $this->sekolah->id,
        'user_id' => $this->siswa->id,
        'nis' => '20240017',
    ]);
});

function masukGantiUsername(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

it('mengganti username akun', function () {
    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => '20240071'])
        ->assertRedirect()
        ->assertSessionHas('sukses');

    expect($this->siswa->fresh()->username)->toBe('20240071');
});

it('memperbarui basis data, bukan hanya objek di memori', function () {
    // Diperiksa langsung ke tabel: `fresh()` bisa menipu bila ada yang salah
    // di urutan penyimpanan, dan yang menentukan di sini adalah apa yang
    // benar-benar tersimpan - karena itulah yang dibaca layar login.
    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => '20240071']);

    expect(DB::table('users')->where('id', $this->siswa->id)->value('username'))->toBe('20240071');
});

it('menurunkan huruf besar menjadi kecil', function () {
    // Bentuk yang didokumentasikan blueprint adalah huruf kecil, dan dua
    // username yang berbeda hanya pada kasus huruf tampak identik saat dicetak
    // di kartu - sehingga siswa mengetiknya dengan cara yang salah dan tidak
    // pernah tahu mengapa gagal.
    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => 'SMKN1.Ani']);

    expect($this->siswa->fresh()->username)->toBe('smkn1.ani');
});

it('memangkas spasi di tepi', function () {
    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => '  ani.mulyani  ']);

    expect($this->siswa->fresh()->username)->toBe('ani.mulyani');
});

it('menolak spasi di tengah username', function () {
    // Mustahil diketik ulang dengan benar dari kartu: siswa mengetik satu
    // spasi, melihat "username atau sandi salah", dan tidak tahu di mana.
    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => 'ani mulyani'])
        ->assertSessionHasErrors('username');

    expect($this->siswa->fresh()->username)->toBe('20240017');
});

it('menolak username yang memuat garis miring atau at', function () {
    foreach (['ani/mulyani', 'ani@smkn1', 'ani+mulyani', 'ani\\mulyani'] as $buruk) {
        masukGantiUsername($this, $this->admin)
            ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => $buruk])
            ->assertSessionHasErrors('username');
    }

    expect($this->siswa->fresh()->username)->toBe('20240017');
});

it('menolak username yang diawali titik', function () {
    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => '.ani'])
        ->assertSessionHasErrors('username');
});

it('menolak username yang terlalu pendek', function () {
    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => 'ab'])
        ->assertSessionHasErrors('username');
});

it('menolak username kosong', function () {
    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => ''])
        ->assertSessionHasErrors('username');
});

it('menolak username yang sudah dipakai akun lain di sekolah yang sama', function () {
    $lain = User::factory()->untukSekolah($this->sekolah)->create([
        'role' => 'student',
        'username' => 'sudah.dipakai',
    ]);

    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => 'sudah.dipakai'])
        ->assertSessionHasErrors('username');

    expect($this->siswa->fresh()->username)->toBe('20240017')
        ->and($lain->fresh()->username)->toBe('sudah.dipakai');
});

it('menolak username yang dipakai akun SEKOLAH LAIN', function () {
    // Inti dari berkas ini. Sekolah A tidak melihat akun sekolah B di
    // layarnya, jadi pemeriksaan yang ter-scope tenant akan melaporkan
    // "bebas" - lalu penggunaan berikutnya gagal di tingkat basis data saat
    // siswa mengetik username itu di layar login.
    User::factory()->untukSekolah($this->lain)->create([
        'role' => 'student',
        'username' => 'milik.sekolah.lain',
    ]);

    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => 'milik.sekolah.lain'])
        ->assertSessionHasErrors('username');

    expect($this->siswa->fresh()->username)->toBe('20240017');
});

it('menyebut sebab bentrokan lintas sekolah di pesannya', function () {
    User::factory()->untukSekolah($this->lain)->create([
        'role' => 'student',
        'username' => 'milik.sekolah.lain',
    ]);

    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => 'milik.sekolah.lain'])
        ->assertSessionHasErrors('username', fn (string $pesan) => str_contains($pesan, 'sekolah lain'));
});

it('mengizinkan akun menyimpan username-nya sendiri', function () {
    // Menyimpan tanpa mengubah apa pun adalah hal yang wajar. Menolaknya
    // berarti form yang Admin buka untuk MEMERIKSA berbunyi sebagai galat.
    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => '20240017'])
        ->assertRedirect()
        ->assertSessionHas('sukses');

    expect($this->siswa->fresh()->username)->toBe('20240017');
});

it('tidak mencatat log saat username tidak berubah', function () {
    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => '20240017']);

    expect(DB::table('activity_logs')->where('action', 'Mengganti username pengguna')->count())->toBe(0);
});

it('tidak menyentuh sandi', function () {
    $sebelum = $this->siswa->password;

    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => '20240071']);

    // Yang diganti adalah nama akunnya, bukan kuncinya. Sandi yang ikut
    // ter-reset berarti seluruh siswa kehilangan aksesnya tanpa satu pun
    // permintaan - dan yang mereka lihat hanyalah form login yang menolak.
    expect($this->siswa->fresh()->password)->toBe($sebelum);
});

it('tetap bisa masuk dengan sandi lamanya sesudah username diganti', function () {
    $this->siswa->forceFill(['password' => 'rahasia-lama'])->save();

    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => '20240071']);

    // Keluar dulu. Tanpa ini permintaannya masih membawa sesi Admin, dan
    // rute login menolak tamu yang sudah masuk - sehingga yang diperiksa
    // adalah sesi Admin yang tidak pernah berubah, bukan siswa yang berhasil
    // masuk dengan username barunya.
    auth()->logout();
    app(SchoolResolver::class)->forget();

    $this->post('/login', [
        'username' => '20240071',
        'password' => 'rahasia-lama',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($this->siswa->fresh());
});

it('membuat username lama tidak bisa lagi dipakai masuk', function () {
    $this->siswa->forceFill(['password' => 'rahasia-lama'])->save();

    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => '20240071']);

    auth()->logout();
    app(SchoolResolver::class)->forget();

    $this->post('/login', [
        'username' => '20240017',
        'password' => 'rahasia-lama',
    ])->assertSessionHasErrors();

    $this->assertGuest();
});

it('tidak menyentuh nama, email, atau profilnya', function () {
    $this->siswa->forceFill(['email' => 'ani@example.test'])->save();

    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => '20240071']);

    $sesudah = $this->siswa->fresh();

    expect($sesudah->name)->toBe('Ani Mulyani')
        ->and($sesudah->email)->toBe('ani@example.test')
        // NIS-nya juga TIDAK ikut berubah. Memperbaiki NIS adalah tindakan
        // tersendiri di form Ubah; mengganti username tidak boleh diam-diam
        // menuliskannya juga.
        ->and(StudentProfile::where('user_id', $this->siswa->id)->value('nis'))->toBe('20240017');
});

it('mencatat username lama dan baru di log aktivitas', function () {
    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => '20240071']);

    $log = DB::table('activity_logs')
        ->where('action', 'Mengganti username pengguna')
        ->first();

    expect($log)->not->toBeNull();

    $metadata = json_decode($log->metadata_json, true);

    // Username LAMA yang dicatat, dan itu bukan kelengkapan: setelah
    // tersimpan, ia tidak ada lagi di mana pun selain log - dan justru itulah
    // yang dicari saat siswa melapor masih mengetik nomor dari kartunya.
    expect($metadata['username_lama'])->toBe('20240017')
        ->and($metadata['username_baru'])->toBe('20240071')
        ->and($log->entity_id)->toBe($this->siswa->id);
});

it('menolak Guru mengubah username siapa pun', function () {
    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create();

    masukGantiUsername($this, $guru)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => 'dibajak'])
        ->assertForbidden();

    expect($this->siswa->fresh()->username)->toBe('20240017');
});

it('menolak Admin sekolah lain dengan 404, bukan 403', function () {
    // 404, bukan 403: 403 mengonfirmasi bahwa akunnya ada, dan yang diubah
    // di sini adalah nama akun - bukan sekadar kolom biasa.
    masukGantiUsername($this, $this->adminLain)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => 'dibajak'])
        ->assertNotFound();

    expect($this->siswa->fresh()->username)->toBe('20240017');
});

it('mengizinkan titik di tengah username', function () {
    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => 'smkn1.20240071']);

    expect($this->siswa->fresh()->username)->toBe('smkn1.20240071');
});

it('mengizinkan angka saja sebagai username', function () {
    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => '99999']);

    expect($this->siswa->fresh()->username)->toBe('99999');
});

it('menolak username yang hanya berisi titik', function () {
    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => '...'])
        ->assertSessionHasErrors('username');
});

it('melaporkan galat pada kolom username, bukan sebagai galat umum', function () {
    // Pesannya milik kolom itu, dan `Field` sudah punya tempat untuk
    // menampilkannya tepat di bawah input yang salah. Galat umum memaksa
    // Admin menebak kolom mana yang ditolak.
    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => 'ani mulyani'])
        ->assertSessionHasErrors(['username']);

    // Lebih dari sekadar "ada galat": pesannya harus menyebut aturannya,
    // bukan kalimat bawaan framework. Aplikasi ini tidak punya direktori
    // `lang/`, jadi pesan bawaan keluar dalam bahasa Inggris - dan Admin
    // berbahasa Indonesia yang membacanya tidak mendapat aturan apa pun.
    $pesan = session('errors')->getBag('default')->first('username');

    expect($pesan)->toContain('huruf, angka, dan titik');
});

it('menormalkan sebelum memeriksa ketersediaan', function () {
    // Kalau pemeriksaannya dilakukan sebelum normalisasi, `SMKN1.20240071`
    // akan lolos - karena bentuk mentahnya belum terpakai - lalu tersimpan
    // sebagai `smkn1.20240071` yang sudah dipakai. Dua akun, satu username.
    User::factory()->untukSekolah($this->sekolah)->create([
        'role' => 'student',
        'username' => 'smkn1.20240071',
    ]);

    masukGantiUsername($this, $this->admin)
        ->put("/admin/pengguna/{$this->siswa->id}/username", ['username' => '  SMKN1.20240071  '])
        ->assertSessionHasErrors('username');

    expect($this->siswa->fresh()->username)->toBe('20240017');
});

it('menyimpan lewat service langsung juga menormalkan dan memeriksa', function () {
    // Aturan bentuknya hidup di service, bukan di form, dan itu disengaja:
    // form yang memeriksanya sendiri hanya akan sepakat dengan server sampai
    // keduanya berbeda diam-diam. Diperiksa di sini supaya pemanggil lain
    // (importer, seeder, perintah artisan) tidak bisa melewatinya.
    $registrar = app(UserRegistrar::class);

    expect(fn () => $registrar->gantiUsername($this->siswa, 'ani mulyani'))
        ->toThrow(InvalidArgumentException::class);

    expect($this->siswa->fresh()->username)->toBe('20240017');

    $lama = $registrar->gantiUsername($this->siswa, '  ANI.BARU  ');

    expect($lama)->toBe('20240017')
        ->and($this->siswa->fresh()->username)->toBe('ani.baru');
});
