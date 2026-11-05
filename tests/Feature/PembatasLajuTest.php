<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Facility\Services\RoomEntryLinkService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Support\PembatasLaju;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Pembatas laju dua kredensial enam angka - PRD §19.2 dan §19.5.
 *
 * =====================================================================
 * Berkas tersendiri, dan isinya adalah kasus yang TIDAK diuji berkas lain
 * =====================================================================
 * `PintuRuangTest` menguji bahwa kode yang BENAR membuka dan kode yang salah
 * tidak. Yang tidak diuji di sana - karena tidak ada satu pun bagian dari
 * perilaku normalnya - adalah APA YANG TERJADI SESUDAH orang menebak berkali-
 * kali. Berkas ini menguji tepat keadaan itu, dan keadaan itu hanya muncul
 * pada percobaan yang gagal berulang.
 *
 * =====================================================================
 * Semuanya lewat HTTP, dan itu bukan pilihan gaya
 * =====================================================================
 * `ThrottleRequests` meng-hash kunci limiter sebelum menyimpannya:
 * `md5($namaLimiter.$limit->key)`. Akibatnya `RateLimiter::clear()` atas
 * kunci MENTAH tidak membersihkan apa pun - dan yang lebih buruk, gagalnya
 * tidak berbunyi. Karena itu tidak ada satu pun test di sini yang memeriksa
 * kunci mentah; semuanya memeriksa PERILAKU: permintaan ke-6 dijawab apa,
 * dan apakah kredensial yang benar masih ditolak.
 *
 * Yang menghitung hash-nya di satu tempat adalah `PembatasLaju::bersihkan()`,
 * dan test "membersihkan hitungan" di bawah yang membuktikan bahwa cara itu
 * memang bekerja - bukan md5 yang disusun ulang di dalam test.
 *
 * =====================================================================
 * Batasnya diuji sebagai BATAS, bukan sebagai "kira-kira terkunci"
 * =====================================================================
 * Yang membedakan pembatas laju yang benar dari yang salah bukan "apakah ia
 * menolak", melainkan:
 *
 *   - apakah ia menolak SESUDAH jatahnya habis dan bukan sebelumnya;
 *   - apakah kredensial yang BENAR pada percobaan terakhir tetap ditolak
 *     (kalau tidak, ia cuma memperlambat, bukan mengunci);
 *   - apakah satu tautan yang ditebak tidak menutup tautan lain;
 *   - dan apakah keberhasilan membersihkan hitungannya sendiri.
 *
 * Keempatnya punya test masing-masing di bawah.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.pembatas',
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
 * Permintaan dari alamat IP tertentu.
 *
 * `withServerVariables` dipakai, bukan `withHeaders('X-Forwarded-For')`:
 * tanpa proxy tepercaya (dan aplikasi ini tidak memasang `trustProxies`),
 * `$request->ip()` membaca `REMOTE_ADDR` - jadi header yang dipalsukan justru
 * TIDAK akan terbaca, dan test yang memakainya akan lulus sambil menguji
 * kunci yang sama berulang kali.
 */
function dariIp(string $ip): TestCase
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip]);
}

/** Terbitkan tautan pintu untuk satu ruang, dengan kode yang bisa dibaca test. */
function tautanPintu(string $slugRuang = 'R-01'): array
{
    $data = siapkanPresensi(
        test()->sekolah,
        test()->tahun,
        test()->semester,
        test()->admin,
        [$slugRuang],
        2,
    );

    return app(RoomEntryLinkService::class)->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);
}

it('mendaftarkan kedua limiter bernama', function () {
    // Penjaga terhadap kesalahan yang paling mudah terjadi dan paling sulit
    // terlihat: nama limiter di `RateLimiter::for()` berbeda satu huruf dari
    // nama di `middleware('throttle:...')`. Ketika itu terjadi, middleware
    // jatuh ke jalur angka-bawaan `handle()` (bukan jalur limiter bernama)
    // dan batasnya menjadi 60/menit yang TIDAK diinginkan siapa pun - tanpa
    // satu pun galat.
    expect(RateLimiter::limiter(PembatasLaju::PINTU))->not->toBeNull()
        ->and(RateLimiter::limiter(PembatasLaju::GERBANG))->not->toBeNull();
});

/* =====================================================================
 | Pintu ruang - POST /p/{slug}
 |===================================================================== */

it('mengizinkan lima percobaan kode salah, lalu menolak yang keenam dengan pesan tertulis', function () {
    $tautan = tautanPintu();
    $slug = $tautan['tautan']->slug;

    // Lima percobaan pertama sampai ke CONTROLLER: pesannya pesan kode yang
    // tidak cocok, bukan pesan pembatas laju. Ini yang membuktikan batasnya
    // lima, bukan nol.
    for ($i = 1; $i <= PembatasLaju::PINTU_PER_PASANGAN; $i++) {
        $respon = $this->post("/p/{$slug}", ['kode' => '000000']);

        $respon->assertRedirect();
        $respon->assertSessionHas('galat', 'Kode pengawas tidak cocok. Periksa kembali enam angkanya.');

        expect($respon->getSession()->get('galat'))
            ->not->toContain('Terlalu banyak');
    }

    // Percobaan keenam TIDAK sampai ke controller sama sekali.
    $keenam = $this->post("/p/{$slug}", ['kode' => '000000']);

    $keenam->assertRedirect(route('pintu.ruang', ['slug' => $slug]));
    $keenam->assertSessionHas('galat', PembatasLaju::PESAN_PINTU);

    // Tidak satu pun dari enam percobaan itu menaikkan pemakaian tautannya.
    // Kalau pembatasnya dipasang SESUDAH `cocokkan()`, angka ini tidak akan
    // nol - dan Admin akan melihat "6 pemakaian" pada tautan yang belum
    // pernah dipakai siapa pun.
    expect($tautan['tautan']->fresh()->use_count)->toBe(0);
});

it('menolak kode yang BENAR pada percobaan keenam - terkunci, bukan diperlambat', function () {
    $tautan = tautanPintu();
    $slug = $tautan['tautan']->slug;

    // Ini test yang paling menentukan di berkas ini.
    //
    // Sebuah pembatas laju yang hanya MEMPERLAMBAT akan meloloskan percobaan
    // ini: lima tebakan salah diikuti satu tebakan yang kebetulan benar, dan
    // pintunya terbuka. Yang dijaga PRD §19.2 adalah bahwa penebak beruntun
    // TIDAK mendapat kesempatan itu - lima kesalahan dalam satu menit
    // menutup pintunya bagi semua orang dari alamat itu, termasuk yang
    // memegang kode yang benar.
    for ($i = 1; $i <= PembatasLaju::PINTU_PER_PASANGAN; $i++) {
        $this->post("/p/{$slug}", ['kode' => '000000']);
    }

    $benarTetapDitolak = $this->post("/p/{$slug}", ['kode' => $tautan['kode']]);

    $benarTetapDitolak->assertRedirect(route('pintu.ruang', ['slug' => $slug]));
    $benarTetapDitolak->assertSessionHas('galat', PembatasLaju::PESAN_PINTU);

    // Dan buktinya bukan sekadar pesannya: tautannya tidak bertambah dipakai,
    // dan pemegangnya tetap tamu.
    expect($tautan['tautan']->fresh()->use_count)->toBe(0);

    $this->assertGuest();
});

it('membersihkan hitungan percobaan sesudah kode yang benar berhasil', function () {
    $tautan = tautanPintu();
    $slug = $tautan['tautan']->slug;

    // Empat salah - satu di bawah batas, jadi pintunya masih bisa dibuka.
    for ($i = 0; $i < PembatasLaju::PINTU_PER_PASANGAN - 1; $i++) {
        $this->post("/p/{$slug}", ['kode' => '000000']);
    }

    $this->post("/p/{$slug}", ['kode' => $tautan['kode']])
        ->assertRedirect(route('pengawas.dashboard'));

    expect($tautan['tautan']->fresh()->use_count)->toBe(1);

    // Sesudah berhasil, jatahnya penuh kembali: DUA percobaan salah berikutnya
    // masih sampai ke controller. Tanpa `PembatasLaju::bersihkan()`, empat
    // salah + satu benar + dua salah sudah melewati batas lima, dan yang
    // kedua di bawah ini akan dijawab pembatas laju.
    //
    // Dua, bukan lima: yang dibuktikan adalah hitungannya KEMBALI KE NOL, dan
    // satu percobaan tidak bisa membedakan "nol" dari "tersisa satu".
    for ($i = 0; $i < 2; $i++) {
        $respon = $this->post("/p/{$slug}", ['kode' => '000000']);

        expect($respon->getSession()->get('galat'))
            ->toBe('Kode pengawas tidak cocok. Periksa kembali enam angkanya.');
    }

    // Pengawas berikutnya yang mengetik kode dengan benar tetap masuk.
    $this->post("/p/{$slug}", ['kode' => $tautan['kode']])
        ->assertRedirect(route('pengawas.dashboard'));
});

it('tidak menutup tautan ruang lain karena lima tebakan di satu tautan', function () {
    $data = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['R-01', 'R-02'],
        4,
    );

    $layanan = app(RoomEntryLinkService::class);
    $a = $layanan->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);
    $b = $layanan->terbitkan(ruangMaster($data['ruang'][1]), berlakuJam: 4);

    // Kunci per-pasangan memuat slug DAN ip; kunci per-tautan hanya slug.
    // Tanpa komponen slug pada yang pertama, lima tebakan di koridor satu akan
    // menutup seluruh pintu di sekolah itu - termasuk ruang yang pengawasnya
    // belum datang.
    for ($i = 0; $i < PembatasLaju::PINTU_PER_PASANGAN; $i++) {
        $this->post("/p/{$a['tautan']->slug}", ['kode' => '000000']);
    }

    // Tautan A terkunci bagi IP ini.
    $this->post("/p/{$a['tautan']->slug}", ['kode' => $a['kode']])
        ->assertSessionHas('galat', PembatasLaju::PESAN_PINTU);

    // Tautan B, IP yang sama: percobaan pertama masih sampai ke controller.
    // Kalau kuncinya tidak memuat slug, baris ini sudah dijawab pembatas laju
    // - karena IP ini baru saja menghabiskan jatahnya di tautan A.
    $respon = $this->post("/p/{$b['tautan']->slug}", ['kode' => '000000']);

    expect($respon->getSession()->get('galat'))
        ->toBe('Kode pengawas tidak cocok. Periksa kembali enam angkanya.');

    // Dan kode B yang benar membuka pintunya, masih dari IP yang sama.
    $this->post("/p/{$b['tautan']->slug}", ['kode' => $b['kode']])
        ->assertRedirect(route('pengawas.dashboard'));
});

it('menutup SATU tautan bagi seluruh alamat sesudah batas per-tautannya habis', function () {
    $tautan = tautanPintu();
    $slug = $tautan['tautan']->slug;

    // Inilah batas yang tidak bisa dilewati dengan berpindah alamat - dan
    // itulah alasan keberadaannya: penebak yang memakai banyak IP tetap
    // dibatasi oleh tautannya sendiri.
    //
    // Setiap alamat menyumbang LIMA percobaan (batas per-pasangan), jadi
    // enam alamat berbeda menghabiskan 30 percobaan - tepat batas per-tautan.
    $alamat = PembatasLaju::PINTU_PER_TAUTAN / PembatasLaju::PINTU_PER_PASANGAN;

    for ($a = 0; $a < $alamat; $a++) {
        $ip = '203.0.113.'.($a + 1);

        for ($i = 0; $i < PembatasLaju::PINTU_PER_PASANGAN; $i++) {
            dariIp($ip)->post("/p/{$slug}", ['kode' => '000000']);
        }
    }

    // Alamat yang SAMA SEKALI BARU - jatah per-pasangannya utuh - tetap
    // ditolak, dan yang menolaknya adalah batas per-tautan.
    $segar = dariIp('203.0.113.200');

    $segar->post("/p/{$slug}", ['kode' => '000000']);

    // Kode yang benar pun tidak membuka sesuatu yang sudah ditutup tautannya
    // sendiri. Perhatikan bahwa pemeriksaan jendela sesi ada SESUDAH
    // pembatas laju, sehingga pintu yang sah tidak pernah sampai ke sini.
    $segar->post("/p/{$slug}", ['kode' => $tautan['kode']])
        ->assertSessionHas('galat', PembatasLaju::PESAN_PINTU_TAUTAN);

    expect($tautan['tautan']->fresh()->use_count)->toBe(0);
});

/* =====================================================================
 | Gerbang ujian - POST /siswa/ujian/{peserta}/gerbang
 |===================================================================== */

it('menolak token yang BENAR pada percobaan keenam, dan tidak membuat attempt', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];
    $siswa = $data['siswa'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);

    // Lima kesalahan yang sampai ke `MesinUjian` - dan galatnya galat TOKEN,
    // bukan galat pembatas laju. Bentuk kegagalannya berbeda dan itu memang
    // disengaja: yang salah baca papan tulis harus bisa langsung mengetik
    // ulang tanpa menunggu.
    for ($i = 0; $i < PembatasLaju::GERBANG_PER_SISWA; $i++) {
        $respon = mulaiAttempt($this, $siswa, $peserta, 'ZZZZZZ');

        expect(pesanGalat($respon))->toHaveKey('token');
    }

    // Yang keenam: tokennya BENAR, dan ia tetap ditolak.
    $keenam = mulaiAttempt($this, $siswa, $peserta, $data['token']);

    $keenam->assertRedirect(route('siswa.gerbang', ['peserta' => $peserta->id]));
    $keenam->assertSessionHas('galat', PembatasLaju::PESAN_GERBANG);

    // Ujiannya tidak dimulai. Ini yang membedakan pembatas laju dari
    // pemeriksaan yang hanya menghitung - dan akibatnya bukan kekosongan
    // administratif: kalau attemptnya lahir di sini, jam ujian anak itu
    // sudah berjalan sejak percobaan yang ditolak.
    expect(ExamAttempt::query()->where('exam_participant_id', $peserta->id)->count())->toBe(0);
});

it('mengunci per siswa, bukan per penempatan', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];

    // Dua siswa SATU penempatan - dan penempatan itu yang mereka bagi, karena
    // keduanya duduk di ruang yang sama. Yang Menentukan di sini adalah
    // bahwa kuncinya `user_id`, bukan `exam_participant_id`: anak yang salah
    // baca papan tulis tidak boleh menutup gerbang kursi sebelahnya, yang
    // memakai token YANG SAMA.
    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $data['peserta'][0]);
    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $data['peserta'][1]);

    for ($i = 0; $i < PembatasLaju::GERBANG_PER_SISWA; $i++) {
        mulaiAttempt($this, $data['siswa'][0], $peserta, 'ZZZZZZ');
    }

    // Siswa pertama terkunci.
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token'])
        ->assertSessionHas('galat', PembatasLaju::PESAN_GERBANG);

    // Siswa kedua, token yang sama, penempatan yang sama: masuk.
    mulaiAttempt($this, $data['siswa'][1], $data['peserta'][1], $data['token'])
        ->assertRedirect("/siswa/ujian/{$data['peserta'][1]->id}/kerjakan");

    expect(attemptBerjalan($data['peserta'][1])->status)->toBe(ExamAttempt::STATUS_BERJALAN);
    expect(ExamAttempt::query()->where('exam_participant_id', $peserta->id)->count())->toBe(0);
});

it('membersihkan hitungan token sesudah ujiannya benar-benar dimulai', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $peserta = $data['peserta'][0];
    $siswa = $data['siswa'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);

    // Empat salah, lalu berhasil.
    for ($i = 0; $i < PembatasLaju::GERBANG_PER_SISWA - 1; $i++) {
        mulaiAttempt($this, $siswa, $peserta, 'ZZZZZZ');
    }

    mulaiAttempt($this, $siswa, $peserta, $data['token'])
        ->assertRedirect("/siswa/ujian/{$peserta->id}/kerjakan");

    expect(attemptBerjalan($peserta)->status)->toBe(ExamAttempt::STATUS_BERJALAN);

    // Sesudah berhasil, jatahnya penuh kembali. Dua kesalahan berikutnya
    // masih dijawab sebagai galat token - bukan sebagai pesan pembatas laju.
    // Tanpa `PembatasLaju::bersihkan()`, empat salah + satu berhasil + satu
    // salah sudah mencapai batas lima.
    for ($i = 0; $i < 2; $i++) {
        $respon = mulaiAttempt($this, $siswa, $peserta, 'ZZZZZZ');

        expect(pesanGalat($respon))->toHaveKey('token')
            ->and(pesanGalat($respon)['token'][0])->not->toContain('Terlalu banyak');
    }

    // Dan "Lanjutkan ujian" - satu-satunya jalan pulang bagi anak yang
    // ponselnya mati di tengah ujian - masih terbuka.
    mulaiAttempt($this, $siswa, $peserta, $data['token'])
        ->assertRedirect("/siswa/ujian/{$peserta->id}/kerjakan");
});
