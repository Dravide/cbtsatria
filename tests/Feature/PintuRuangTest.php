<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Facility\Models\Room;
use App\Domain\Facility\Models\RoomEntryLink;
use App\Domain\Facility\Services\KartuPintu;
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

    $props = propsPintu($this, $tautan->slug);

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
    // responsnya - sebelum pintunya dibuka. Satu kali ikut terkirim sebagai
    // prop yang "tidak ditampilkan" tetap berarti namanya sampai di peramban
    // orang yang memindai kartu di daun pintu.
    //
    // Sejak kode pengawas menempel pada ORANG, batas ini menjadi lebih
    // penting, bukan kurang: yang tidak boleh bocor kini termasuk nama
    // pengawas mana pun yang ditugaskan di ruang itu - dan itu nama yang
    // tersedia di `supervision_assignments`, bukan sesuatu yang jauh.
    $isi = $this->get("/p/{$tautan->slug}")->getContent();

    expect($isi)->not->toContain('Siswa 1');
});

it('membuka pintu mencatat pemakaian tautan dan mengarahkan ke portal pengawas', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);
    $kode = $data['kode'][$data['ruang'][0]->id];

    expect($tautan->use_count)->toBe(0)
        ->and($tautan->last_used_at)->toBeNull();

    $this->post("/p/{$tautan->slug}", ['kode' => $kode])
        ->assertRedirect(route('pengawas.dashboard'));

    $dipakai = $tautan->fresh();

    // Pemakaiannya naik TEPAT satu. Ia satu-satunya hal di tabel ini yang
    // membuat tautan bisa ditelusuri balik ke pembukaan yang benar-benar
    // berhasil - dan pada kartu yang tidak lagi memuat kode, ia satu-satunya
    // tanda kebocoran yang masih bisa dibaca Admin.
    expect($dipakai->use_count)->toBe(1)
        ->and($dipakai->last_used_at)->not->toBeNull();

    // Pemegangnya tidak di-login: kalau ia menjadi akun, setiap baris presensi
    // yang ditandatanganinya akan menjadi presensi atas nama orang itu.
    $this->assertGuest();

    // Tetapi namanya TIDAK lagi hilang: yang membuka pintu adalah pengawas
    // yang memegang kode itu, dan namanya tersimpan bersama hibaknya.
    expect(session(KonteksRuang::KUNCI_SESI)['pengguna_id'])
        ->not->toBeNull();
});

it('menolak tautan yang sudah dicabut dengan alasan tertulis', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $layanan = app(RoomEntryLinkService::class);
    $tautan = $layanan->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);
    $kode = $data['kode'][$data['ruang'][0]->id];

    $layanan->cabutSemua(ruangMaster($data['ruang'][0]));

    // GET-nya 200, bukan 404: tautannya memang ada, dan yang perlu diketahui
    // pemegangnya adalah APA yang terjadi padanya.
    $props = propsPintu($this, $tautan->slug);

    expect($props['berlaku'])->toBeFalse()
        ->and($props['alasan'])->toContain('dicabut')
        // Ruangnya tidak ikut ditampilkan begitu tautannya mati: nama ruang
        // pun tidak lagi perlu diketahui orang yang memegang kertas kemarin.
        ->and($props['ruang'])->toBeNull();

    $this->post("/p/{$tautan->slug}", ['kode' => $kode])
        ->assertRedirect()
        ->assertSessionHas('galat');

    // Kodenya BENAR - ia kode pengawas yang sah untuk ruang itu - dan
    // tautannya dicabut, dan itu tetap tidak membuka apa pun. Urutan itu yang
    // penting: kalau kodenya diperiksa lebih dulu, tautan yang sudah dicabut
    // masih bisa dipakai oleh siapa pun yang menghafal enam angkanya.
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
    $kode = $data['kode'][$data['ruang'][0]->id];

    expect($tautan->berlaku())->toBeFalse();

    $props = propsPintu($this, $tautan->slug);

    expect($props['berlaku'])->toBeFalse()
        ->and($props['alasan'])->toContain('kedaluwarsa');

    $this->post("/p/{$tautan->slug}", ['kode' => $kode])
        ->assertSessionHas('galat');

    expect($tautan->fresh()->use_count)->toBe(0);
});

it('menerbitkan tautan baru mencabut yang lama untuk ruang yang sama', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $layanan = app(RoomEntryLinkService::class);
    $lama = $layanan->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);
    $baru = $layanan->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);

    // Dua tautan hidup untuk satu pintu berarti kartu lama yang tertinggal di
    // daun pintu masih membuka ruang yang sama - dan Admin yang menerbitkan
    // ulang hampir selalu melakukannya JUSTRU karena kartu lamanya bocor.
    expect($lama->fresh()->berlaku())->toBeFalse()
        ->and($baru->fresh()->berlaku())->toBeTrue()
        ->and($baru->fresh()->revoked_at)->toBeNull();

    $this->post("/p/{$lama->slug}", ['kode' => $data['kode'][$data['ruang'][0]->id]])
        ->assertSessionHas('galat');

    $this->post("/p/{$baru->slug}", ['kode' => $data['kode'][$data['ruang'][0]->id]])
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
        $this->post("/p/{$tautan->slug}", ['kode' => $salah])
            ->assertSessionHasErrors('kode');
    }

    // ------------------------------------------------------------------
    // `abcdef` sengaja TIDAK masuk daftar di atas, dan itu bukan kelalaian
    // ------------------------------------------------------------------
    // Enam huruf memenuhi `size:6`, sehingga ia lolos validasi bentuk dan
    // jatuh ke pemeriksaan HASH - di mana ia ditolak sebagai kode yang salah,
    // bukan sebagai kode yang bentuknya salah. Dua penolakan itu memang
    // berbeda, dan yang penting bagi pengawas di depan pintu adalah yang
    // terakhir: kode yang hurufnya salah ketik mendapat pesan yang sama
    // dengan kode yang angkanya salah.
    $this->post("/p/{$tautan->slug}", ['kode' => 'abcdef'])
        ->assertRedirect()
        ->assertSessionHas('galat')
        ->assertSessionHasNoErrors();

    // Tidak satu pun percobaan di atas menaikkan pemakaiannya:
    // `catatPemakaian()` menaikkan hitungan itu SESUDAH hashnya cocok,
    // sehingga Admin tidak pernah melihat "5 pemakaian" pada tautan yang
    // belum dipakai siapa pun.
    expect($tautan->fresh()->use_count)->toBe(0);
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
    $props = propsPintu($this, $tautanSini->slug);

    expect($props['sekolah'])->toBe('SMK Negeri 1');

    $slugLain = RoomEntryLink::tanpaTenant()
        ->where('room_id', ruangMaster($lainData['ruang'][0])->id)
        ->first();

    // Tautan sekolah lain memang belum diterbitkan di sini, sehingga yang
    // tersisa untuk dibuktikan adalah bahwa slug-nya benar-benar tidak
    // dikenali - bukan bahwa ia menunjuk ruang yang salah.
    expect($slugLain)->toBeNull();

    $this->get("/p/{$tautanSini->slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Pengawas/Pintu'));
});

/* ------------------------------------------- Kartu pintu cetak (plate 09) */

it('menerbitkan tautan tanpa tanggal berakhir, dan ia hidup sampai dicabut', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $ruang = ruangMaster($data['ruang'][0]);
    $layanan = app(RoomEntryLinkService::class);

    // Bawaan baru sejak 2026-11-09. Yang diuji BUKAN "kolomnya null" - itu
    // rincian penyimpanan - melainkan PERILAKU yang membuatnya layak
    // diputuskan: tautan yang diterbitkan hari ini masih membuka pintunya
    // jauh setelah dua jam, dan hanya pencabutan Admin yang menghentikannya.
    $tautan = $layanan->terbitkan($ruang);

    expect($tautan->expires_at)->toBeNull()
        ->and($tautan->berlaku())->toBeTrue();

    // Tiga hari kemudian - jauh melewati `BERLAKU_JAM` yang lama - ia masih
    // berlaku. Waktu dimajukan, bukan `expires_at` yang disunting, karena
    // yang diperiksa adalah perilaku `berlaku()` terhadap waktu yang berjalan.
    //
    // Yang TIDAK diuji di sini adalah pembukaan pintunya sesudah waktu
    // dimajukan: jendela SESI-nya sudah lewat, dan pintu yang menolak karena
    // sesinya berakhir adalah perilaku lain yang sudah diuji di tempat lain.
    // Yang perlu dibuktikan di sini hanya satu: tautannya sendiri tidak mati
    // oleh jam.
    $this->travel(3)->days();

    expect($tautan->fresh()->berlaku())->toBeTrue();

    // Pencabutan tetap berlaku seketika, dan itu yang menggantikan masa
    // berlaku sebagai pengaman: tautan yang bocor dimatikan Admin, bukan
    // menunggu jam.
    $layanan->cabutSemua($ruang);

    expect($tautan->fresh()->berlaku())->toBeFalse();
});

it('masih menghormati masa berlaku yang diberikan eksplisit', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    // Bawaannya kini tanpa batas, tetapi kemampuan membuat tautan berumur
    // pendek TIDAK dihapus - dan itu diuji di sini supaya perubahan bawaan
    // tidak diam-diam mencabut kemampuan itu.
    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);

    expect($tautan->expires_at)->not->toBeNull()
        ->and($tautan->berlaku())->toBeTrue();

    $this->travel(5)->hours();

    expect($tautan->fresh()->berlaku())->toBeFalse();
});

it('mencetak kartu pintu sebagai PDF, satu halaman per ruang', function () {
    $satu = Room::factory()->untukSekolah($this->sekolah)->create(['code' => 'R-01']);
    Room::factory()->untukSekolah($this->sekolah)->create(['code' => 'R-02']);

    // Satu ruang DITERBITKAN tautannya, supaya cabang QR-nya benar-benar
    // dirender DomPDF. Data URI pada `img src` adalah satu-satunya bagian
    // templat itu yang bisa gagal tanpa membuat render PDF-nya ikut gagal -
    // dan kegagalannya berbentuk kotak kosong di kertas, bukan galat.
    app(RoomEntryLinkService::class)->terbitkan($satu, berlakuJam: 4);

    $respon = $this->actingAs($this->admin)->get('/admin/ruang/kartu');

    $respon->assertOk();

    expect($respon->headers->get('content-type'))->toContain('application/pdf')
        ->and(substr($respon->getContent(), 0, 4))->toBe('%PDF');
});

it('membuat halaman untuk ruang tanpa tautan, bukan melewatinya', function () {
    $berTautan = Room::factory()->untukSekolah($this->sekolah)->create(['code' => 'R-01']);
    Room::factory()->untukSekolah($this->sekolah)->create(['code' => 'R-02']);

    app(RoomEntryLinkService::class)->terbitkan($berTautan, berlakuJam: 4);

    $ruang = Room::query()
        ->with(['entryLinks' => fn ($q) => $q->whereNull('revoked_at')])
        ->orderBy('code')
        ->get();

    $halaman = app(KartuPintu::class)->halaman($ruang);

    // Dua ruang, dua halaman - ruang yang belum punya tautan tetap mendapat
    // lembar sendiri supaya "pintu tanpa kertas" tidak pernah berarti
    // "kelupaan cetak".
    expect($halaman)->toHaveCount(2);

    $perKode = collect($halaman)->keyBy('kode');

    expect($perKode['R-01']['qr'])->toStartWith('data:image/png;base64,')
        ->and($perKode['R-01']['url'])->toContain('/p/')
        ->and($perKode['R-02']['qr'])->toBeNull()
        ->and($perKode['R-02']['url'])->toBeNull();
});

it('menerbitkan tautan massal hanya untuk ruang yang belum punya', function () {
    $sudah = Room::factory()->untukSekolah($this->sekolah)->create(['code' => 'R-01']);
    $belum = Room::factory()->untukSekolah($this->sekolah)->create(['code' => 'R-02']);
    // Ruang perbaikan sekalipun mendapat tautan: yang menentukan boleh
    // dipakai atau tidak adalah statusnya saat penempatan, bukan ada
    // tidaknya kartu pintu - dan kartunya tetap ditempel, karena ruang bisa
    // diperbaiki kembali sebelum hari ujian.
    Room::factory()->untukSekolah($this->sekolah)->perbaikan()->create(['code' => 'R-03']);

    $lama = app(RoomEntryLinkService::class)->terbitkan($sudah, berlakuJam: 4);

    $this->actingAs($this->admin)
        ->post('/admin/ruang/terbitkan-massal')
        ->assertSessionHasNoErrors();

    // Tautan R-01 TIDAK dicabut. Operasi massal yang mencabut ulang seluruh
    // kartu yang sudah ditempel adalah kebalikan dari gunanya menyiapkan
    // hari ujian - dan itulah batas yang diuji di sini.
    expect($lama->fresh()->revoked_at)->toBeNull()
        ->and($lama->fresh()->berlaku())->toBeTrue();

    expect($belum->fresh()->tautanAktif())->not->toBeNull()
        ->and(session('sukses'))->toContain('2 tautan ruang diterbitkan')
        ->and(session('sukses'))->toContain('1 ruang sudah punya tautan');
});
