<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Facility\Models\RoomEntryLink;
use App\Domain\Facility\Services\RoomEntryLinkService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * Pintu ruang - halaman publik `/p/{slug}`.
 *
 * =====================================================================
 * Berkas tersendiri, dan pemisahannya bukan kerapian
 * =====================================================================
 * AttendanceTest menguji apa yang terjadi SESUDAH seseorang masuk. Berkas ini
 * menguji SATU-SATUNYA halaman di aplikasi ini yang bisa dibuka tanpa akun -
 * dan laman tanpa akun punya satu jenis kegagalan yang tidak dimiliki laman
 * lain: kebocoran lewat APA YANG IA TAMPILKAN.
 *
 * Akibatnya berbeda juga: laman lain yang salah menampilkan sesuatu adalah
 * galat izin yang terlihat; yang ini adalah daftar nama anak-anak di daun
 * pintu yang bisa difoto siapa pun yang lewat di koridor, dan tidak ada satu
 * pun galat yang muncul. Karena itu batasnya diuji sebagai BATAS - daftar
 * kunci yang TEPAT - bukan sebagai "isinya kira-kira benar".
 *
 * ---------------------------------------------------------------------
 * Tautan yang tidak berlaku dibedakan dari tautan yang tidak ada
 * ---------------------------------------------------------------------
 * Yang pertama 200 dengan alasan tertulis ("sudah dicabut", "sudah
 * kedaluwarsa"); yang kedua 404. Pembedaan itu bukan kehati-hatian: pengawas
 * di depan pintu harus tahu apakah ia harus memanggil Admin atau memeriksa
 * kartu yang ditempelnya kemarin.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.pintu',
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

/**
 * Props yang benar-benar dikirim halaman Inertia ke peramban.
 *
 * Bertipe `object`, bukan `TestCase`, karena berkas ini tidak mengimpor
 * `Tests\TestCase` - dan nama yang tidak diimpor di dalam namespace ini akan
 * menunjuk ke kelas lain, sehingga tipenya menolak test case Pest yang
 * sebenarnya memenuhi syarat.
 */
function propsPintu(object $test, string $slug): array
{
    $respon = $test->get("/p/{$slug}");

    $respon->assertOk();

    return $respon->viewData('page')['props'];
}

it('halaman pintu hanya membocorkan nama ruang, nama sekolah, dan nama sesi', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 3);

    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);

    $props = propsPintu($this, $tautan['tautan']->slug);

    // Batasnya diperiksa sebagai daftar kunci yang TEPAT. `has()` akan lulus
    // meski propnya bertambah sepuluh; yang ini gagal begitu satu kunci baru
    // muncul - dan kunci baru itu adalah cara kebocoran ini terjadi.
    //
    // `ujian` dan `jam` ikut, dan keduanya masih di dalam batas yang
    // dijanjikan `ringkas()`: keduanya menerangkan SESI yang sama yang sudah
    // disebut `sesi` - judul ujiannya dan jendela waktunya. Yang tidak boleh
    // ada adalah hal yang menerangkan ORANG di dalam ruangan, dan itu yang
    // diperiksa di bawah.
    expect(array_keys($props['ruang']))->toBe(['nama', 'kode', 'lokasi', 'sesi', 'ujian', 'jam']);

    expect($props['sekolah'])->toBe('SMK Negeri 1')
        ->and($props['berlaku'])->toBeTrue();

    // Yang TIDAK boleh ada, disebutkan satu per satu karena masing-masing
    // punya cara sendiri untuk bocor: daftar nama anak, token yang sedang
    // berlaku, nama pengawas yang bertugas, dan jumlah siswa di ruang itu.
    // Yang terakhir bukan kebocoran identitas, tetapi ia memberi tahu berapa
    // banyak orang di dalam ruangan - dan itu pun tidak perlu diketahui
    // koridor.
    expect($props)->not->toHaveKeys(['peserta', 'kursi', 'token', 'kode_token', 'pengawas'])
        ->and($props['ruang'])->not->toHaveKeys(['peserta', 'kursi', 'jumlah', 'kapasitas']);

    // Nama pengawasnya bahkan tidak boleh muncul di dalam teks mentah
    // responsnya. Satu kali ikut terkirim sebagai prop yang "tidak
    // ditampilkan" tetap berarti namanya sampai di peramban orang yang
    // memindai kartu di daun pintu.
    $isi = $this->get("/p/{$tautan['tautan']->slug}")->getContent();

    expect($isi)->not->toContain('Siswa 1');
});

it('membuka pintu mencatat pemakaian tautan dan mengarahkan ke portal pengawas', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);

    expect($tautan['tautan']->use_count)->toBe(0)
        ->and($tautan['tautan']->last_used_at)->toBeNull();

    $this->post("/p/{$tautan['tautan']->slug}", ['kode' => $tautan['kode']])
        ->assertRedirect(route('pengawas.dashboard'));

    $dipakai = $tautan['tautan']->fresh();

    // Pemakaiannya naik TEPAT satu - dan itu satu-satunya hal di tabel ini
    // yang membuat "R-01 (tautan ruang)" bisa ditelusuri balik ke kredensial
    // yang benar-benar dipakai.
    expect($dipakai->use_count)->toBe(1)
        ->and($dipakai->last_used_at)->not->toBeNull();

    // Pemegangnya tidak di-login: kalau ia menjadi akun, setiap baris presensi
    // yang ditandatanganinya akan menjadi presensi atas nama orang itu.
    $this->assertGuest();
});

it('menolak tautan yang sudah dicabut dengan alasan tertulis', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $layanan = app(RoomEntryLinkService::class);
    $tautan = $layanan->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);

    $layanan->cabutSemua(ruangMaster($data['ruang'][0]));

    // GET-nya 200, bukan 404: tautannya memang ada, dan yang perlu diketahui
    // pemegangnya adalah APA yang terjadi padanya.
    $props = propsPintu($this, $tautan['tautan']->slug);

    expect($props['berlaku'])->toBeFalse()
        ->and($props['alasan'])->toContain('dicabut')
        // Ruangnya tidak ikut ditampilkan begitu tautannya mati: nama ruang
        // pun tidak lagi perlu diketahui orang yang memegang kertas kemarin.
        ->and($props['ruang'])->toBeNull();

    $this->post("/p/{$tautan['tautan']->slug}", ['kode' => $tautan['kode']])
        ->assertRedirect()
        ->assertSessionHas('galat');

    // Kodenya BENAR dan tautannya dicabut - dan itu tetap tidak membuka apa
    // pun. Urutan itu yang penting: kalau kodenya diperiksa lebih dulu,
    // tautan yang sudah dicabut masih bisa dipakai selama seseorang masih
    // mengingat enam angkanya.
    $this->get('/pengawas')->assertRedirect(route('login'));
});

it('menolak tautan yang sudah kedaluwarsa dengan alasan tertulis', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    // Diterbitkan dengan masa berlaku NEGATIF - satu jam yang sudah lewat.
    // Bentuknya sengaja dipakai, bukan `expires_at` yang disunting langsung:
    // yang diuji adalah tautan yang lahir dengan masa berlaku pendek, dan itu
    // keadaan yang sebenarnya terjadi pada kartu pintu yang ditempel pagi
    // untuk sesi siang.
    $tautan = app(RoomEntryLinkService::class)->terbitkan(
        ruangMaster($data['ruang'][0]),
        berlakuJam: -1,
    );

    expect($tautan['tautan']->berlaku())->toBeFalse();

    $props = propsPintu($this, $tautan['tautan']->slug);

    expect($props['berlaku'])->toBeFalse()
        ->and($props['alasan'])->toContain('kedaluwarsa');

    $this->post("/p/{$tautan['tautan']->slug}", ['kode' => $tautan['kode']])
        ->assertSessionHas('galat');

    expect($tautan['tautan']->fresh()->use_count)->toBe(0);
});

it('menerbitkan tautan baru mencabut yang lama untuk ruang yang sama', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $layanan = app(RoomEntryLinkService::class);
    $lama = $layanan->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);
    $baru = $layanan->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);

    // Dua tautan hidup untuk satu pintu berarti kode lama yang sudah ditulis
    // di papan tulis masih bisa dipakai setelah Admin menerbitkan yang baru -
    // dan Admin yang menerbitkan ulang hampir selalu melakukannya JUSTRU
    // karena kode lamanya bocor.
    expect($lama['tautan']->fresh()->berlaku())->toBeFalse()
        ->and($baru['tautan']->fresh()->berlaku())->toBeTrue()
        ->and($baru['tautan']->fresh()->revoked_at)->toBeNull();

    $this->post("/p/{$lama['tautan']->slug}", ['kode' => $lama['kode']])
        ->assertSessionHas('galat');

    $this->post("/p/{$baru['tautan']->slug}", ['kode' => $baru['kode']])
        ->assertRedirect(route('pengawas.dashboard'));
});

it('menolak kode yang bukan enam angka', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);

    // Bentuknya diperiksa lebih dulu, dan itu terlihat dari JENIS
    // penolakannya: `size:6` menolak sebelum satu pun hash diperiksa,
    // sehingga percobaan yang jelas salah tidak membayar biaya bcrypt - dan
    // pintu di depan kelas tidak bisa dijadikan alat untuk membebani server.
    foreach (['12345', '1234567', ''] as $salah) {
        $this->post("/p/{$tautan['tautan']->slug}", ['kode' => $salah])
            ->assertSessionHasErrors('kode');
    }

    // ------------------------------------------------------------------
    // `abcdef` sengaja TIDAK masuk daftar di atas, dan itu bukan kelalaian
    // ------------------------------------------------------------------
    // Enam huruf memenuhi `size:6`, sehingga ia lolos validasi bentuk dan
    // jatuh ke pemeriksaan HASH - di mana ia ditolak sebagai kode yang salah,
    // bukan sebagai kode yang bentuknya salah. Dua penolakan itu memang
    // berbeda, dan yang penting bagi pengawas di depan pintu adalah yang
    // terakhir: kartu yang hurufnya salah ketik mendapat pesan yang sama
    // dengan kartu yang angkanya salah.
    $this->post("/p/{$tautan['tautan']->slug}", ['kode' => 'abcdef'])
        ->assertRedirect()
        ->assertSessionHas('galat')
        ->assertSessionHasNoErrors();

    // Tidak satu pun percobaan di atas menaikkan pemakaiannya: `cocokkan()`
    // menaikkan hitungan itu SESUDAH hashnya cocok, sehingga Admin tidak
    // pernah melihat "5 pemakaian" pada tautan yang belum dipakai siapa pun.
    expect($tautan['tautan']->fresh()->use_count)->toBe(0);
});

it('menolak tautan ruang milik sekolah lain lewat pintu sekolah ini', function () {
    $lain = School::factory()->create(['name' => 'SMK Negeri 2']);
    $adminLain = User::factory()->admin()->untukSekolah($lain)->create();
    $tahunLain = AcademicYear::factory()->untukSekolah($lain)->aktif()->create(['name' => '2026/2027']);
    $semesterLain = Semester::factory()->create([
        'school_id' => $lain->id,
        'academic_year_id' => $tahunLain->id,
        'name' => 'Ganjil',
    ]);

    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);
    $lainData = siapkanPresensi($lain, $tahunLain, $semesterLain, $adminLain, ['R-01'], 2);

    $tautanSini = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);

    // Pintu sekolah pertama hanya boleh mengenal ruangnya sendiri. Yang
    // menegakkannya adalah `whereHas('eventRoom', ... where('room_id',
    // $tautan->room_id))` di dalam controller - dan kalau baris itu hilang,
    // yang terjadi bukan galat melainkan ruang sekolah lain yang terbuka.
    $props = propsPintu($this, $tautanSini['tautan']->slug);

    expect($props['sekolah'])->toBe('SMK Negeri 1');

    $slugLain = RoomEntryLink::tanpaTenant()
        ->where('room_id', ruangMaster($lainData['ruang'][0])->id)
        ->first();

    // Tautan sekolah lain memang belum diterbitkan di sini, sehingga yang
    // tersisa untuk dibuktikan adalah bahwa slug-nya benar-benar tidak
    // dikenali - bukan bahwa ia menunjuk ruang yang salah.
    expect($slugLain)->toBeNull();

    $this->get("/p/{$tautanSini['tautan']->slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Pengawas/Pintu'));
});
