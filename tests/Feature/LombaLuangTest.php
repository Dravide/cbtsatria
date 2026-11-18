<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Models\LeisureGameScore;
use App\Domain\Attendance\Models\LeisureMatch;
use App\Domain\Attendance\Services\LombaLuang;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Carbon\Carbon;

/**
 * Lomba hitung cepat dua pemain - undangan lewat kode.
 *
 * =====================================================================
 * Yang paling menentukan di berkas ini: SIAPA YANG BISA MASUK
 * =====================================================================
 * Lomba gagal dengan cara yang tidak berbunyi. Kode yang bisa dimasuki
 * orang ketiga, kode yang bisa dimasuki pembuatnya sendiri, atau lomba yang
 * tetap berjalan setelah salah satu pemainnya masuk ujian - ketiganya tidak
 * menimbulkan galat apa pun. Ia hanya menjadi lomba yang skornya tidak
 * bermakna, dan tidak ada satu pun tangkapan layar yang memperlihatkannya.
 *
 * Karena itu separuh berkas ini membuktikan TIDAK ADANYA akses.
 *
 * =====================================================================
 * Waktu dipercepat, bukan ditunggu
 * =====================================================================
 * `mulai_pada` adalah kolom, jadi lomba yang "sudah selesai" dibuat dengan
 * memundurkan kolom itu - bukan dengan menunggu enam puluh detik. Yang
 * diuji adalah perhitungannya, dan perhitungan yang diuji lewat tidur
 * adalah tes yang lambat dan rapuh.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.lomba',
    ]);

    AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => AcademicYear::query()->value('id'),
        'name' => 'Ganjil',
    ]);

    $this->host = User::factory()->siswa()->untukSekolah($this->sekolah)->create([
        'username' => 'siswa.host',
        'name' => 'Ahmad',
    ]);

    $this->tamu = User::factory()->siswa()->untukSekolah($this->sekolah)->create([
        'username' => 'siswa.tamu',
        'name' => 'Budi',
    ]);
});

/** Membuat lomba lewat service, mengembalikan barisnya. */
function lombaBaru(object $test, ?User $host = null): LeisureMatch
{
    return app(LombaLuang::class)->buat($host ?? $test->host, now());
}

/** Memundurkan jam mulai supaya lombanya dianggap sudah selesai. */
function lombaSelesai(LeisureMatch $lomba): LeisureMatch
{
    $lomba->forceFill([
        'mulai_pada' => now()->subSeconds(LeisureMatch::DURASI_DETIK + 5),
    ])->save();

    return $lomba->refresh();
}

/* --------------------------------------------------------- soal lomba */

it('membangkitkan enam puluh soal yang keempat pilihannya memuat jawaban', function () {
    $soal = app(LombaLuang::class)->bangkitkanSoal();

    expect($soal)->toHaveCount(60);

    foreach ($soal as $s) {
        // Empat pilihan, tidak ada yang kembar, dan jawabannya selalu salah
        // satunya. Ketiga syarat ini yang membuat soal bisa dijawab sama
        // sekali; soal yang kehilangan jawabannya di pilihan adalah soal
        // yang tidak bisa dijawab siapa pun.
        expect($s['pilihan'])->toHaveCount(4)
            ->and(array_unique($s['pilihan']))->toHaveCount(4)
            ->and($s['pilihan'])->toContain($s['jawaban'])
            ->and($s['jawaban'])->toBeGreaterThanOrEqual(0);
    }
});

it('memberi kedua pemain daftar soal yang sama persis', function () {
    $lomba = lombaBaru($this);

    $dipasang = app(LombaLuang::class)->masuk($lomba->code, $this->tamu, now());

    // Soal yang dibaca dari baris yang sama - bukan dibangkitkan ulang.
    // Dua generator yang harus identik adalah dua generator yang suatu hari
    // berbeda, dan lomba yang soalnya berbeda bukan lomba.
    expect($dipasang->soal)->toBe($lomba->refresh()->soal);
});

/* ------------------------------------------------------ kode undangan */

it('mengalihkan ke RUANG lomba setelah membuat undangan, bukan ke alamat tanpa kode', function () {
    $respon = masukUji($this, $this->host)
        ->post('/siswa/luang/lomba')
        ->assertRedirect();

    $lomba = LeisureMatch::query()->latest('id')->firstOrFail();

    // Regresi: `route('siswa.luang.lomba', ['kode' => ...])` menempelkan
    // kodenya sebagai QUERY STRING (`?kode=ABC123`), karena rute itu tidak
    // punya parameter `{kode}`. Controller tidak pernah menerimanya, lomba
    // tetap kosong, dan yang berubah hanya alamat di bilah peramban.
    $respon->assertRedirect("/siswa/luang/lomba/{$lomba->code}");

    expect($respon->headers->get('Location'))
        ->not->toContain('?')
        ->toContain($lomba->code);
});

it('mengalihkan ke ruang lomba setelah menerima undangan', function () {
    $lomba = lombaBaru($this);

    masukUji($this, $this->tamu)
        ->post('/siswa/luang/lomba/masuk', ['kode' => $lomba->code])
        ->assertRedirect("/siswa/luang/lomba/{$lomba->code}");
});

it('membuat kode enam karakter yang tidak mirip angka atau huruf lain', function () {
    $lomba = lombaBaru($this);

    expect($lomba->code)->toMatch('/^[A-Z0-9]{6}$/');

    // Huruf dan angka yang tertukar saat dibacakan tidak boleh muncul:
    // kode ini dibagikan anak ke temannya, dan setiap pasangan yang mirip
    // adalah satu kesalahan yang pasti terjadi.
    expect($lomba->code)->not->toMatch('/[IO01LZ5]/');
});

it('menolak kode yang tidak menunjuk lomba mana pun', function () {
    masukUji($this, $this->tamu)
        ->post('/siswa/luang/lomba/masuk', ['kode' => 'ZZZZZZ'])
        ->assertRedirect()
        ->assertSessionHas('galat', 'Kode undangan itu tidak berlaku. Periksa lagi, atau minta kode baru.');
});

it('menolak pembuatnya sendiri masuk sebagai lawan', function () {
    $lomba = lombaBaru($this);

    // Satu anak dengan dua tab akan mendapat dua skor dari satu lomba, dan
    // papan peringkatnya naik dua kali lipat tanpa satu pun permainan
    // tambahan.
    expect(app(LombaLuang::class)->masuk($lomba->code, $this->host, now()))->toBeNull();
});

it('menolak lomba yang sudah berisi dua pemain', function () {
    $lomba = lombaBaru($this);
    $ketiga = User::factory()->siswa()->untukSekolah($this->sekolah)->create();

    expect(app(LombaLuang::class)->masuk($lomba->code, $this->tamu, now()))->not->toBeNull();
    expect(app(LombaLuang::class)->masuk($lomba->code, $ketiga, now()))->toBeNull();
});

it('menolak undangan yang sudah kedaluwarsa', function () {
    $lomba = lombaBaru($this);

    // Sepuluh menit lewat: undangan yang tidak dipakai tidak boleh tetap
    // bisa dimasuki, karena kodenya mungkin sudah tersebar ke mana-mana.
    expect(app(LombaLuang::class)->masuk(
        $lomba->code,
        $this->tamu,
        now()->addMinutes(LeisureMatch::BERLAKU_MENIT + 1),
    ))->toBeNull();
});

it('menolak kode dari sekolah lain', function () {
    $sekolahLain = School::factory()->create(['name' => 'SMK Negeri 2']);
    $orangLain = User::factory()->siswa()->untukSekolah($sekolahLain)->create();

    $lomba = lombaBaru($this);

    // Kode yang ditebak tepat pada sekolah lain tetap tidak boleh
    // menyeberang: skor yang tercatat akan lahir di papan peringkat sekolah
    // yang salah.
    expect(app(LombaLuang::class)->masuk($lomba->code, $orangLain, now()))->toBeNull();
});

/* ------------------------------------------------------------- keadaan */

it('menunggu sebelum lawannya masuk, dan hitung mundur sesudahnya', function () {
    $lomba = lombaBaru($this);

    expect($lomba->keadaan(now())['fase'])->toBe('menunggu');

    $lomba = app(LombaLuang::class)->masuk($lomba->code, $this->tamu, now());

    expect($lomba->keadaan(now())['fase'])->toBe('hitung_mundur')
        ->and($lomba->keadaan(now())['sisa'])->toBeGreaterThan(0);
});

it('berjalan sesudah jam mulainya lewat, dan selesai enam puluh detik kemudian', function () {
    // Jam dibekukan: `sisa` dihitung dari selisih timestamp, dan `now()`
    // yang bergerak beberapa mikrodetik di antara penyiapan dan pemeriksaan
    // membuat hitungannya meleset satu detik tanpa ada yang salah.
    Carbon::setTestNow('2026-11-18 09:00:00');

    $lomba = app(LombaLuang::class)->masuk(lombaBaru($this)->code, $this->tamu, now());

    $berjalan = $lomba->keadaan(now()->addSeconds(LeisureMatch::JEDA_MULAI_DETIK + 10));
    expect($berjalan['fase'])->toBe('berjalan')
        ->and($berjalan['sisa'])->toBe(LeisureMatch::DURASI_DETIK - 10);

    $selesai = $lomba->keadaan(now()->addSeconds(LeisureMatch::JEDA_MULAI_DETIK + LeisureMatch::DURASI_DETIK + 1));
    expect($selesai['fase'])->toBe('selesai');

    Carbon::setTestNow();
});

/* --------------------------------------------------------------- skor */

/** Lembar jawaban yang seluruhnya benar untuk lomba ini. */
function lembarBenar(LeisureMatch $lomba, int $jumlah = 12): array
{
    return array_map(fn ($s) => $s['jawaban'], array_slice($lomba->soal, 0, $jumlah));
}

it('menilai lembar jawaban terhadap kunci yang tersimpan di server', function () {
    $lomba = lombaSelesai(app(LombaLuang::class)->masuk(lombaBaru($this)->code, $this->tamu, now()));

    masukUji($this, $this->host)
        ->postJson("/siswa/luang/lomba/{$lomba->code}/skor", [
            'jawaban' => lembarBenar($lomba, 12),
            'duration_ms' => 60_000,
        ])
        ->assertOk()
        ->assertJson(['diterima' => true, 'score' => 120]);
});

it('hanya menghitung jawaban yang benar', function () {
    $lomba = lombaSelesai(app(LombaLuang::class)->masuk(lombaBaru($this)->code, $this->tamu, now()));

    // Sepuluh angka, tiga di antaranya sengaja salah.
    $lembar = lembarBenar($lomba, 10);
    $lembar[2] = -1;
    $lembar[5] = -1;
    $lembar[8] = -1;

    masukUji($this, $this->host)
        ->postJson("/siswa/luang/lomba/{$lomba->code}/skor", [
            'jawaban' => $lembar,
            'duration_ms' => 60_000,
        ])
        ->assertOk()
        ->assertJson(['score' => 70]);
});

it('menerima lembar yang lebih pendek dari jumlah soal', function () {
    $lomba = lombaSelesai(app(LombaLuang::class)->masuk(lombaBaru($this)->code, $this->tamu, now()));

    // Anak yang menjawab dua puluh soal dalam enam puluh detik memang
    // mengirim dua puluh angka - lembar pendek bukan lembar tidak sah.
    masukUji($this, $this->host)
        ->postJson("/siswa/luang/lomba/{$lomba->code}/skor", [
            'jawaban' => lembarBenar($lomba, 20),
            'duration_ms' => 60_000,
        ])
        ->assertOk()
        ->assertJson(['score' => 200]);
});

it('memotong lembar yang lebih panjang dari daftar soal, bukan menolaknya', function () {
    $lomba = lombaSelesai(app(LombaLuang::class)->masuk(lombaBaru($this)->code, $this->tamu, now()));

    // Regresi: anak yang mengetuk cepat di detik terakhir mengirim lembar
    // yang lebih panjang dari enam puluh soal. Dulu itu ditolak `max:120`
    // dengan 422 - dan SELURUH skornya hilang karena beberapa angka
    // berlebih. Sekarang angkanya dipotong di soal terakhir.
    $lembar = array_merge(lembarBenar($lomba, 60), [1, 2, 3, 4, 5]);

    masukUji($this, $this->host)
        ->postJson("/siswa/luang/lomba/{$lomba->code}/skor", [
            'jawaban' => $lembar,
            'duration_ms' => 60_000,
        ])
        ->assertOk()
        ->assertJson(['diterima' => true, 'score' => 600]);
});

it('menerima lembar KOSONG sebagai skor nol, bukan 422', function () {
    $lomba = lombaSelesai(app(LombaLuang::class)->masuk(lombaBaru($this)->code, $this->tamu, now()));

    // Regresi: `required` menolak array kosong, dan anak yang sama sekali
    // tidak menjawab dalam enam puluh detik memang mengirim `[]`. Menolaknya
    // dengan 422 membuat lombanya berakhir tanpa skor sama sekali - padahal
    // skor nol adalah jawaban yang benar untuk lembar kosong.
    masukUji($this, $this->host)
        ->postJson("/siswa/luang/lomba/{$lomba->code}/skor", [
            'jawaban' => [],
            'duration_ms' => 60_000,
        ])
        ->assertOk()
        ->assertJson(['diterima' => true, 'score' => 0]);
});

it('menolak kiriman skor sebelum lombanya selesai', function () {
    $lomba = app(LombaLuang::class)->masuk(lombaBaru($this)->code, $this->tamu, now());

    // 409, bukan 200. Peramban yang mengirim terlalu cepat tidak boleh
    // mendapat skor yang tercatat sebagai lengkap.
    masukUji($this, $this->host)
        ->postJson("/siswa/luang/lomba/{$lomba->code}/skor", [
            'jawaban' => [1, 2, 3],
            'duration_ms' => 60_000,
        ])
        ->assertStatus(409);
});

it('menolak skor kedua pada lomba yang sama', function () {
    $lomba = lombaSelesai(app(LombaLuang::class)->masuk(lombaBaru($this)->code, $this->tamu, now()));

    masukUji($this, $this->host)
        ->postJson("/siswa/luang/lomba/{$lomba->code}/skor", [
            'jawaban' => lembarBenar($lomba, 12),
            'duration_ms' => 60_000,
        ])
        ->assertOk();

    // Kiriman kedua tidak menimpa: skor pertama sudah tercatat, dan baris
    // yang bisa ditimpa berarti anak itu bisa menaikkan angkanya dengan
    // memuat ulang lamannya.
    masukUji($this, $this->host)
        ->postJson("/siswa/luang/lomba/{$lomba->code}/skor", [
            'jawaban' => lembarBenar($lomba, 60),
            'duration_ms' => 60_000,
        ])
        ->assertOk()
        ->assertJson(['pesan' => 'Skor Anda pada lomba ini sudah tercatat.']);

    expect((int) LeisureGameScore::query()
        ->where('match_id', $lomba->id)
        ->where('student_id', $this->host->id)
        ->value('score'))->toBe(120);
});

it('menolak lembar yang mustahil, tetapi tetap menulis barisnya', function () {
    $lomba = lombaSelesai(app(LombaLuang::class)->masuk(lombaBaru($this)->code, $this->tamu, now()));

    // Enam puluh benar dalam SATU detik: 600 poin, 600 per detik - di atas
    // `HITUNG_POIN_PER_DETIK_MAKS`. `PenjagaSkorLuar` menolaknya, dan
    // barisnya tetap tersimpan bersama `match_id`-nya supaya Admin bisa
    // melihat kiriman yang ditolak.
    masukUji($this, $this->host)
        ->postJson("/siswa/luang/lomba/{$lomba->code}/skor", [
            'jawaban' => lembarBenar($lomba, 60),
            'duration_ms' => 1_000,
        ])
        ->assertOk()
        ->assertJson(['diterima' => false]);

    expect(LeisureGameScore::query()
        ->where('match_id', $lomba->id)
        ->whereNotNull('rejected_reason')
        ->exists())->toBeTrue();
});

/* ----------------------------------------------------------- kepemilikan */

it('menyembunyikan lomba milik orang lain, bukan menolak dengan 403', function () {
    $lomba = lombaBaru($this);

    // 404, bukan 403: lomba anak lain tampak TIDAK ADA. 403 akan
    // memberi tahu penebak bahwa kode itu benar-benar dipakai.
    masukUji($this, User::factory()->siswa()->untukSekolah($this->sekolah)->create())
        ->get("/siswa/luang/lomba/{$lomba->code}")
        ->assertNotFound();
});

it('mengirim soal tanpa kunci jawaban', function () {
    $lomba = lombaSelesai(app(LombaLuang::class)->masuk(lombaBaru($this)->code, $this->tamu, now()));

    $props = masukUji($this, $this->host)
        ->get("/siswa/luang/lomba/{$lomba->code}")
        ->assertOk()
        ->viewData('page')['props'];

    // Soal yang lolos membawa `jawaban`-nya akan membuat anak yang membuka
    // DevTools menang tanpa berhitung.
    expect($props['lomba']['soal'][0])->toHaveKeys(['teks', 'pilihan'])
        ->and($props['lomba']['soal'][0])->not->toHaveKey('jawaban');
});

/* --------------------------------------------------------------- batal */

it('membatalkan lomba saat salah satu pemainnya masuk ujian', function () {
    $lomba = app(LombaLuang::class)->masuk(lombaBaru($this)->code, $this->tamu, now());

    // Attempt yang belum ditutup menutup pintu luang - dan karenanya
    // menghentikan lomba yang sedang berjalan. Lawannya harus tahu
    // sebabnya, bukan sekadar melihat layar yang berhenti.
    $data = siapkanUjianSiswa($this->sekolah, AcademicYear::query()->first(), Semester::query()->first(), $this->admin);
    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    // Siswa pada penyiapan itu bukan host maupun tamu lomba ini, jadi
    // panggilan keadaan oleh pemain lomba tetap harus berjalan normal.
    masukUji($this, $this->host)
        ->getJson("/siswa/luang/lomba/{$lomba->code}/keadaan")
        ->assertOk()
        ->assertJson(['fase' => 'hitung_mundur']);
});
