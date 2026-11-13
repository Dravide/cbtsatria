<?php

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use App\Domain\Tenancy\Support\IdentitasAplikasi;
use Illuminate\Http\UploadedFile;

/**
 * Identitas aplikasi di kepala dokumen - favicon, manifest, dan meta.
 *
 * Yang diuji bukan "tag ini ada", melainkan empat janji yang bisa rusak tanpa
 * ada yang menyadarinya:
 *
 *   1. Setiap halaman menyatakan `noindex`. Aplikasi ini memuat nilai,
 *      presensi, dan jawaban ujian siswa; halaman yang lolos ke indeks mesin
 *      pencari membocorkan data anak ke mesin yang menyimpannya bertahun-tahun.
 *      `public/robots.txt` saja tidak cukup - ia hanya dibaca perayap yang
 *      menghormatinya, dan berkasnya bisa hilang saat deploy tanpa satu test
 *      pun gagal. `noindex` juga TIDAK bisa dimatikan dari Admin.
 *
 *   2. Berkas ikon yang DITUNJUK benar-benar ada. Tag yang menunjuk berkas
 *      tidak ada menghasilkan ikon kosong di tab dan permintaan 404 di setiap
 *      pembukaan halaman - kegagalan yang tidak terlihat di lokal karena
 *      peramban menyimpan kegagalannya.
 *
 *   3. Manifest yang dibaca peramban memuat identitas SEKOLAH itu, bukan nama
 *      produk. Satu basis data bisa memuat lebih dari satu sekolah.
 *
 *   4. Ikon yang diunggah Admin benar-benar dipakai - dan yang ditolak
 *      (bukan PNG persegi >= 192px) tidak meninggalkan berkas yatim di disk.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1', 'code' => 'smkn1']);
    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create(['username' => 'admin.identitas']);
});

/**
 * Masuk sekaligus melepas konteks tenant yang tertinggal dari request lain.
 *
 * Parameternya tidak diberi tipe `TestCase`: Pest mengikat `$this` ke kelas
 * test yang dibangkitkan per berkas, bukan ke `Tests\TestCase` - dan
 * typehint yang lebih sempit menolaknya sebelum satu permintaan pun dibuat.
 */
function masukIdentitas($test, User $user)
{
    app(SchoolResolver::class)->forget();

    return $test->actingAs($user);
}

/* ===========================================================================
 | Kepala dokumen
 =========================================================================== */

it('menyatakan noindex di setiap halaman', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false);
});

it('menunjuk berkas ikon yang benar-benar ada', function () {
    $halaman = $this->get('/login')->assertOk()->getContent();

    preg_match_all('#(?:href|content)="(/(?:favicon[^"]*|apple-touch-icon\.png|icon-\d+\.png))"#', $halaman, $cocok);

    $dirujuk = array_values(array_unique($cocok[1]));

    expect($dirujuk)->not->toBeEmpty();

    foreach ($dirujuk as $jalur) {
        expect(public_path(ltrim($jalur, '/')))->toBeFile();
    }
});

it('menyajikan manifest yang sah dan menyebut nama aplikasi', function () {
    $isi = $this->get('/site.webmanifest')->assertOk()->json();

    expect($isi['name'])->toBe(config('app.name'))
        ->and($isi['short_name'])->not->toBeEmpty()
        ->and($isi['start_url'])->toBe('/')
        ->and($isi['icons'])->not->toBeEmpty();

    foreach ($isi['icons'] as $ikon) {
        expect(public_path(ltrim($ikon['src'], '/')))->toBeFile();
    }
});

it('melarang seluruh perayap lewat robots.txt', function () {
    $isi = file_get_contents(public_path('robots.txt'));

    // `Disallow: /` dengan garis miringnya, bukan `Disallow:` kosong -
    // yang kedua berarti SELURUH situs boleh dirayapi.
    expect($isi)->toMatch('/^User-agent: \*$/m')
        ->and($isi)->toMatch('/^Disallow: \/$/m');
});

it('memakai bawaan selama sekolah belum mengisi identitasnya', function () {
    $nilai = IdentitasAplikasi::nilai($this->sekolah);

    expect($nilai['nama'])->toBe(config('app.name'))
        ->and($nilai['warna'])->toBe('#1e4fa3')
        ->and($nilai['ikon'][0]['src'])->toBe('/icon-192.png');
});

/* ===========================================================================
 | Pengaturan Admin
 =========================================================================== */

it('menyimpan identitas dan memakainya di halaman', function () {
    masukIdentitas($this, $this->admin)
        ->put('/admin/sekolah/identitas', [
            'nama' => 'CBT SMK Negeri 1',
            'nama_pendek' => 'CBT SMKN1',
            'deskripsi' => 'Ujian sekolah daring SMK Negeri 1.',
            'warna' => '#9B2530',
        ])
        ->assertSessionHas('sukses');

    $this->assertDatabaseHas('activity_logs', [
        'school_id' => $this->sekolah->id,
        'action' => 'Memperbarui identitas aplikasi',
    ]);

    // Warna disimpan huruf kecil: `theme_color` manifest dibandingkan
    // persis, dan `#9B2530` vs `#9b2530` adalah dua nilai berbeda.
    expect($this->sekolah->fresh()->branding['warna'])->toBe('#9b2530');

    // `/login`, bukan `/admin/sekolah/identitas`: yang diuji adalah KEPALA
    // DOKUMEN, dan kepala dokumen hanya bisa diperiksa dari respons HTML
    // penuh - laman Inertia mengirim JSON. Sesi loginnya dilepas lebih dulu,
    // karena middleware `guest` mengalihkan pengguna yang masih masuk keluar
    // dari `/login` - dan yang ingin dibaca di sini justru halaman tamu,
    // tempat setiap siswa mendarat sebelum ia punya akun.
    $this->app['auth']->forgetGuards();

    $halaman = $this->get('/login')->assertOk()->getContent();

    expect($halaman)->toContain('<title inertia>CBT SMK Negeri 1</title>')
        ->and($halaman)->toContain('content="#9b2530"');
});

it('menyajikan manifest dari identitas sekolah, bukan nama produk', function () {
    $this->sekolah->forceFill([
        'branding' => [
            'nama' => 'CBT SMK Negeri 1',
            'nama_pendek' => 'CBT SMKN1',
            'deskripsi' => 'Ujian daring.',
            'warna' => '#9b2530',
        ],
    ])->save();

    $isi = masukIdentitas($this, $this->admin)->get('/site.webmanifest')->assertOk()->json();

    expect($isi['name'])->toBe('CBT SMK Negeri 1')
        ->and($isi['short_name'])->toBe('CBT SMKN1')
        ->and($isi['theme_color'])->toBe('#9b2530');
});

it('menolak warna yang bukan hex enam digit', function () {
    masukIdentitas($this, $this->admin)
        ->put('/admin/sekolah/identitas', [
            'nama' => 'CBT',
            'nama_pendek' => 'CBT',
            'deskripsi' => 'Ujian.',
            'warna' => 'rebeccapurple',
        ])
        ->assertSessionHasErrors('warna');
});

/* ===========================================================================
 | Ikon
 =========================================================================== */

/** PNG persegi berukuran tertentu, tanpa bergantung pada GD di mesin uji. */
function pngPersegi(int $ukuran): string
{
    $gambar = imagecreatetruecolor($ukuran, $ukuran);
    imagefill($gambar, 0, 0, imagecolorallocate($gambar, 30, 79, 163));
    ob_start();
    imagepng($gambar);
    $isi = (string) ob_get_clean();
    imagedestroy($gambar);

    return $isi;
}

it('menyimpan ikon manifest beserta ukuran sebenarnya', function () {
    $ini = tempnam(sys_get_temp_dir(), 'ikon-');
    file_put_contents($ini, pngPersegi(256));

    masukIdentitas($this, $this->admin)
        ->post('/admin/sekolah/identitas/ikon/ikon', [
            'berkas' => new UploadedFile($ini, 'ikon.png', 'image/png', null, true),
        ])
        ->assertSessionHas('sukses');

    $branding = $this->sekolah->fresh()->branding;

    // Ukuran DISIMPAN, bukan ditebak 512x512: manifest yang menjanjikan
    // ukuran lebih besar daripada berkasnya membuat Android memasang ikon
    // buram di layar beranda.
    expect($branding['ikon_sizes'])->toBe('256x256');
    expect($branding['ikon_path'])->not->toBeNull();

    $isi = masukIdentitas($this, $this->admin)->get('/site.webmanifest')->assertOk()->json();

    expect($isi['icons'][0]['sizes'])->toBe('256x256');
});

it('menolak ikon yang bukan persegi dan tidak meninggalkan berkas', function () {
    $ini = tempnam(sys_get_temp_dir(), 'ikon-');
    $gambar = imagecreatetruecolor(300, 200);
    ob_start();
    imagepng($gambar);
    file_put_contents($ini, (string) ob_get_clean());
    imagedestroy($gambar);

    $sebelum = glob(storage_path('app/public/identitas/*')) ?: [];

    masukIdentitas($this, $this->admin)
        ->post('/admin/sekolah/identitas/ikon/ikon', [
            'berkas' => new UploadedFile($ini, 'ikon.png', 'image/png', null, true),
        ])
        ->assertSessionHas('galat');

    // Berkas yang sudah tersimpan sebelum ukurannya diperiksa harus
    // dihapus: tanpa itu setiap penolakan meninggalkan satu PNG yatim.
    expect(glob(storage_path('app/public/identitas/*')) ?: [])->toHaveCount(count($sebelum));
});

it('menolak ikon yang lebih kecil dari 192px', function () {
    $ini = tempnam(sys_get_temp_dir(), 'ikon-');
    file_put_contents($ini, pngPersegi(128));

    masukIdentitas($this, $this->admin)
        ->post('/admin/sekolah/identitas/ikon/ikon', [
            'berkas' => new UploadedFile($ini, 'ikon.png', 'image/png', null, true),
        ])
        ->assertSessionHas('galat');
});

it('menolak pengguna yang bukan admin', function () {
    // Tidak ada id sekolah di URL mana pun di laman ini - sekolahnya selalu
    // milik pengguna yang masuk. Karena itu yang perlu dijaga bukan
    // "sekolah lain", melainkan "peran lain": Guru yang mengetik alamat ini
    // tidak boleh mengubah nama aplikasi seluruh sekolah.
    $guru = User::factory()->guru()->untukSekolah($this->sekolah)->create();

    masukIdentitas($this, $guru)
        ->put('/admin/sekolah/identitas', [
            'nama' => 'Diambil alih',
            'nama_pendek' => 'X',
            'deskripsi' => 'X',
            'warna' => '#000000',
        ])
        ->assertForbidden();
});
