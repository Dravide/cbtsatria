<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Semester;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\Auth;

/**
 * Pantau wali per kelas - `/wali/{slug}`. `wireframe-pantau-wali.html`.
 *
 * =====================================================================
 * Yang dijaga di sini BUKAN tampilannya, melainkan tiga batasnya
 * =====================================================================
 * Ini halaman TANPA LOGIN yang menyebut NAMA ANAK - satu-satunya di seluruh
 * aplikasi. Karena itu setiap test di bawah memeriksa satu dari tiga hal,
 * dan tidak satu pun memeriksa "halamannya terbuka":
 *
 *   1. Kelas tanpa `wali_slug` tidak punya alamat sama sekali.
 *   2. Sebelum kode benar, JAM tidak ikut dikirim - walau field-nya ada di
 *      server dan siap.
 *   3. NILAI tidak pernah keluar, dalam bentuk apa pun.
 *
 * Poin ketiga yang paling mudah dilanggar tanpa sengaja: baris
 * `exam_results` sudah terisi begitu attempt dikumpulkan, dan satu `with()`
 * yang ditambahkan dengan niat baik akan membocorkannya ke internet sebelum
 * dipublikasikan.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1 Cipanas']);
    // `papan_slug` dipakai BERSAMA dengan `/papan` dan `/pantau` - satu
    // sekolah satu alamat publik.
    $this->sekolah->forceFill(['papan_slug' => 'smkn1-cipanas-ab12'])->save();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.wali',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'Ganjil',
    ]);
});

/** Fixture ujian + kelas, dengan slug wali opsional. */
function siapkanWali(object $test, bool $tayang = true, ?string $kode = '123456'): array
{
    Auth::logout();
    app(SchoolResolver::class)->forget();

    $data = siapkanUjianSiswa(
        $test->sekolah,
        $test->tahun,
        $test->semester,
        $test->admin,
    );

    // Kelas yang dipakai fixture: satu-satunya kelas di event itu.
    $kelas = SchoolClass::query()
        ->withoutGlobalScopes()
        ->where('school_id', $test->sekolah->id)
        ->firstOrFail();

    if ($tayang) {
        $kelas->forceFill([
            'wali_slug' => 'kelas-'.$kelas->id.'-a1b2',
            'wali_code' => $kode,
            'wali_code_at' => now(),
        ])->save();
    }

    return [...$data, 'kelas' => $kelas->refresh()];
}

it('tidak punya alamat sebelum kelasnya dinyalakan', function () {
    $data = siapkanWali($this, tayang: false);

    expect($data['kelas']->wali_slug)->toBeNull();

    // Rute yang tidak pernah lahir tidak bisa ditebak - lebih kuat daripada
    // 403 yang justru membuktikan alamatnya ada.
    $this->get('/wali/'.$this->sekolah->papan_slug.'/kelas/'.$data['kelas']->id)
        ->assertNotFound();
});

it('menampilkan daftar kelas yang dibuka, dan tidak yang lain', function () {
    $data = siapkanWali($this);

    $this->get('/wali/'.$this->sekolah->papan_slug)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Publik/Wali')
            ->has('kelas', 1)
            ->where('kelas.0.id', $data['kelas']->id),
        );
});

it('menampilkan nama dan ruang TANPA jam sebelum kodenya dimasukkan', function () {
    $data = siapkanWali($this);

    $this->get('/wali/'.$this->sekolah->papan_slug.'/kelas/'.$data['kelas']->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Publik/WaliKelas')
            ->where('terbuka', false)
            // Nama ada - orang tua harus bisa menemukan anaknya.
            ->has('siswa', 2)
            ->has('siswa.0.nama')
            // Jam TIDAK ada. Ini yang dijaga seluruh laman.
            ->missing('siswa.0.mulai')
            ->missing('siswa.0.absen')
            ->missing('siswa.0.menit_kerja')
            ->missing('rekap'),
        );
});

it('membuka rincian dengan kode yang benar', function () {
    $data = siapkanWali($this);

    $this->post('/wali/'.$this->sekolah->papan_slug.'/kelas/'.$data['kelas']->id, [
        'kode' => '123456',
    ])
        ->assertRedirect()
        ->assertSessionHas('sukses');

    $this->get('/wali/'.$this->sekolah->papan_slug.'/kelas/'.$data['kelas']->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('terbuka', true)
            ->has('rekap')
            // Jam sekarang ADA - dan hanya sesudah kode benar.
            ->has('siswa.0.mulai')
            ->has('siswa.0.absen')
            ->has('siswa.0.menit_kerja'),
        );
});

it('menolak kode yang salah, dan tidak membuka apa pun', function () {
    $data = siapkanWali($this);

    $this->post('/wali/'.$this->sekolah->papan_slug.'/kelas/'.$data['kelas']->id, [
        'kode' => '000000',
    ])->assertSessionHasErrors('kode');

    $this->get('/wali/'.$this->sekolah->papan_slug.'/kelas/'.$data['kelas']->id)
        ->assertInertia(fn ($page) => $page
            ->where('terbuka', false)
            ->missing('siswa.0.mulai'),
        );
});

it('tidak pernah mengirim nilai, dalam bentuk apa pun', function () {
    $data = siapkanWali($this);

    // Attempt yang SUDAH dikumpulkan - keadaan yang membuat `exam_results`
    // terisi dan paling mudah bocor lewat satu `with()` yang ditambahkan
    // kemudian.
    app(SesiService::class)->mulai($data['sesi']);
    app(SesiService::class)->tutup($data['sesi']);

    $this->post('/wali/'.$this->sekolah->papan_slug.'/kelas/'.$data['kelas']->id, [
        'kode' => '123456',
    ]);

    $halaman = $this->get('/wali/'.$this->sekolah->papan_slug.'/kelas/'.$data['kelas']->id);

    $halaman->assertOk();

    // Serialisasi props diperiksa sebagai TEKS: kolom yang tidak ada bernama
    // `nilai` tetapi bernama lain akan lolos pemeriksaan per-kunci.
    $isi = json_encode($halaman->viewData('page')['props']);

    expect($isi)->not->toContain('"score"')
        ->and($isi)->not->toContain('"nilai"')
        ->and($isi)->not->toContain('"total_skor"')
        ->and($isi)->not->toContain('exam_results');
});

it('tidak membocorkan username siswa, walau namanya tampil', function () {
    $data = siapkanWali($this);

    $this->post('/wali/'.$this->sekolah->papan_slug.'/kelas/'.$data['kelas']->id, [
        'kode' => '123456',
    ]);

    $props = json_encode(
        $this->get('/wali/'.$this->sekolah->papan_slug.'/kelas/'.$data['kelas']->id)
            ->viewData('page')['props'],
    );

    // Username hanya berbeda satu digit dari NIS, dan ia kunci masuk ujian.
    $username = $data['siswa'][0]->username;

    expect($props)->not->toContain($username)
        ->and($props)->not->toContain('email');
});

it('menolak kelas milik sekolah lain, walau sekolahnya ada', function () {
    $data = siapkanWali($this);

    $sekolahLain = School::factory()->create([
        'name' => 'Sekolah Lain',
        'papan_slug' => 'sekolah-lain-zz99',
    ]);

    // Kelas milik sekolah fixture dibuka lewat slug sekolah LAIN. Binding
    // yang mencari id di seluruh tabel akan menemukannya - dan namanya
    // tayang.
    $this->get('/wali/'.$sekolahLain->papan_slug.'/kelas/'.$data['kelas']->id)
        ->assertNotFound();
});

it('menyalakan pantau membangkitkan slug, dan mematikan tidak menghapusnya', function () {
    $data = siapkanWali($this, tayang: false);

    $this->actingAs($this->admin)
        ->patch('/admin/kelas/'.$data['kelas']->id.'/pantau', ['tayang' => true])
        ->assertRedirect()
        ->assertSessionHas('sukses');

    $slug = $data['kelas']->refresh()->wali_slug;

    expect($slug)->not->toBeNull();

    // Mematikan TIDAK menghapus slug: alamat lama harus tetap hidup, kalau
    // tidak menyalakan kembali berarti tautan baru yang harus ditempel
    // ulang di grup wali kelas.
    $this->actingAs($this->admin)
        ->patch('/admin/kelas/'.$data['kelas']->id.'/pantau', ['tayang' => false])
        ->assertRedirect();

    expect($data['kelas']->refresh()->wali_slug)->toBe($slug);
});

it('menerbitkan kode tanpa mengganti tautannya', function () {
    $data = siapkanWali($this, kode: '111111');

    $slug = $data['kelas']->wali_slug;

    $this->actingAs($this->admin)
        ->post('/admin/kelas/'.$data['kelas']->id.'/kode-wali')
        ->assertRedirect()
        ->assertSessionHas('sukses');

    $segar = $data['kelas']->refresh();

    // Tautannya TETAP - itu yang membuat penerbitan ulang kode bisa
    // dilakukan di tengah ujian tanpa membagikan tautan baru.
    expect($segar->wali_slug)->toBe($slug)
        ->and($segar->wali_code)->not->toBe('111111')
        ->and($segar->wali_code)->toHaveLength(6);
});

it('menolak menerbitkan kode sebelum kelasnya dinyalakan', function () {
    $data = siapkanWali($this, tayang: false);

    $this->actingAs($this->admin)
        ->post('/admin/kelas/'.$data['kelas']->id.'/kode-wali')
        ->assertSessionHas('galat');

    expect($data['kelas']->refresh()->wali_code)->toBeNull();
});

it('menolak menyalakan foto bila sekolah belum mengizinkannya', function () {
    $data = siapkanWali($this);

    expect($this->sekolah->refresh()->wali_foto_boleh)->toBeFalse();

    $this->actingAs($this->admin)
        ->patch('/admin/kelas/'.$data['kelas']->id.'/pantau', [
            'tayang' => true,
            'foto' => true,
        ])
        ->assertSessionHas('galat');

    // Saklar fotonya TETAP mati. Tombol yang menyala lalu ditolak server
    // lebih buruk daripada tombol yang menolak lebih dulu.
    expect($data['kelas']->refresh()->wali_foto)->toBeFalse();
});

it('mengirim kode kelas di props laman Admin, supaya bisa dibaca ulang', function () {
    $data = siapkanWali($this, kode: '654321');

    $props = $this->actingAs($this->admin)
        ->get('/admin/kelas')
        ->viewData('page')['props'];

    $baris = collect($props['baris']['data'] ?? $props['baris'])->firstWhere('id', $data['kelas']->id);

    // Kode DIKIRIM, dan itu membalik keputusan versi pertama test ini.
    //
    // Alasan lamanya berbunyi "kode hanya lewat flash SESUDAH diterbitkan",
    // dan itu salah untuk pemakaian nyata: Admin yang lupa menyalinnya pada
    // respons itu harus menerbitkan ulang - dan penerbitan ulang MENGGANTI
    // kode yang mungkin sudah beredar di grup wali kelas. Pola yang benar
    // sudah ada di `EventCommitteeController::index()`, yang mengirim kode
    // pengawas setiap kali karena Admin mencetaknya menjadi kartu.
    //
    // Yang dijaga `[Hidden]` tetap berlaku dan tidak dilanggar di sini:
    // kolomnya tidak ikut serialisasi MODEL mana pun. Prop ini ditulis
    // eksplisit, satu per satu, di satu tempat yang bisa diperiksa.
    expect($baris['wali_kode'])->toBe('654321');
});

it('mengirim tautan wali yang bisa langsung dibagikan', function () {
    $data = siapkanWali($this);

    $props = $this->actingAs($this->admin)
        ->get('/admin/kelas')
        ->viewData('page')['props'];

    $baris = collect($props['baris']['data'] ?? $props['baris'])->firstWhere('id', $data['kelas']->id);

    // Tautan disusun SERVER, bukan peramban: yang menyusunnya di layar harus
    // menebak bentuk alamat, dan `/wali/x-1-a3f9` tidak bisa ditebak dari
    // ingatan.
    expect($baris['wali_url'])->toContain('/wali/'.$this->sekolah->papan_slug.'/kelas/'.$data['kelas']->id);
});

it('tidak memberi tautan wali bila sekolah belum punya slug papan', function () {
    $data = siapkanWali($this);

    // `papan_slug` dipakai bersama `/papan` dan `/pantau`; sekolah yang
    // belum pernah menyalakan papannya tidak punya alamat publik sama
    // sekali, sehingga tautan wali pun tidak bisa disusun.
    $this->sekolah->forceFill(['papan_slug' => null])->save();

    $props = $this->actingAs($this->admin)
        ->get('/admin/kelas')
        ->viewData('page')['props'];

    $baris = collect($props['baris']['data'] ?? $props['baris'])->firstWhere('id', $data['kelas']->id);

    expect($baris['wali_url'])->toBeNull();
});

it('menolak foto sebelum kodenya dimasukkan', function () {
    $data = siapkanWali($this);
    $this->sekolah->forceFill(['wali_foto_boleh' => true])->save();
    $data['kelas']->forceFill(['wali_foto' => true])->save();

    // Rute foto memeriksa kode LEBIH DULU daripada apa pun. Tanpa kode,
    // id berkas yang benar pun berakhir 404.
    $this->get('/wali/'.$this->sekolah->papan_slug.'/foto/1')->assertNotFound();
});

/* ------------------------------------------------------------ Massal */

it('menyalakan seluruh kelas dalam satu tahun ajaran, dan melewati yang sudah tayang', function () {
    $data = siapkanWali($this);

    // Kelas kedua di tahun yang sama, belum tayang.
    $kedua = SchoolClass::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => '7B',
    ]);

    $this->actingAs($this->admin)
        ->post('/admin/kelas/pantau-massal', ['academic_year_id' => $this->tahun->id])
        ->assertRedirect()
        ->assertSessionHas('sukses');

    $segar = $kedua->refresh();

    // Kelas kedua mendapat slug DAN kode - keduanya lahir bersama, karena
    // tautan tanpa kode membuka nama saja dan Admin harus menekan dua
    // tombol untuk satu pekerjaan.
    expect($segar->wali_slug)->not->toBeNull()
        ->and($segar->wali_code)->toHaveLength(6);

    // Kelas pertama SUDAH tayang, dan kodenya TIDAK diganti: kode yang
    // sudah beredar di grup wali kelas harus tetap berlaku.
    expect($data['kelas']->refresh()->wali_code)->toBe('123456');
});

it('tidak menyalakan foto lewat aksi massal', function () {
    $data = siapkanWali($this);

    $kedua = SchoolClass::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => '7C',
    ]);

    // Bahkan ketika sekolah SUDAH mengizinkan foto, aksi massal tidak
    // menyalakannya: foto memuat wajah siswa lain, dan ia tidak boleh
    // menyala sebagai akibat sampingan dari tombol yang ditekan orang yang
    // sedang memikirkan tautan.
    $this->sekolah->forceFill(['wali_foto_boleh' => true])->save();

    $this->actingAs($this->admin)
        ->post('/admin/kelas/pantau-massal', ['academic_year_id' => $this->tahun->id]);

    expect($kedua->refresh()->wali_foto)->toBeFalse();
});

it('hanya menyentuh kelas di tahun ajaran yang diminta', function () {
    $data = siapkanWali($this);

    $tahunLain = AcademicYear::factory()->untukSekolah($this->sekolah)->create([
        'name' => '2027/2028',
    ]);

    $lain = SchoolClass::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $tahunLain->id,
        'name' => '8A',
    ]);

    $this->actingAs($this->admin)
        ->post('/admin/kelas/pantau-massal', ['academic_year_id' => $this->tahun->id]);

    // Kelas tahun depan TIDAK ikut tayang: menyalakan seluruh tahun
    // sekaligus berarti kelas yang jadwalnya belum ada ikut terbuka.
    expect($lain->refresh()->wali_slug)->toBeNull();
});

it('menolak aksi massal tanpa tahun ajaran', function () {
    $data = siapkanWali($this);

    $this->actingAs($this->admin)
        ->post('/admin/kelas/pantau-massal', [])
        ->assertSessionHasErrors('academic_year_id');
});
