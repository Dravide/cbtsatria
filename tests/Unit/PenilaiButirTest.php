<?php

use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Grading\Support\PenilaiButir;
use App\Domain\Question\Enums\JenisSoal;
use Tests\TestCase;

/*
 * Berkas ini BUTUH aplikasi ter-boot karena `ExamQuestion` melakukan cast
 * `jenis` ke enum dan `kunci_json` ke array. Cast hanya bekerja sesudah
 * aplikasi dibangun - tanpa `uses()` di bawah, seluruh test gagal dengan
 * "A facade root has not been set" yang tidak menyebut penyebabnya.
 *
 * RefreshDatabase SENGAJA tidak dipakai: `PenilaiButir` tidak menyentuh basis
 * data sama sekali. Ia menerima snapshot dan jawaban, dan mengembalikan
 * benar/salah beserta poin. Itu yang membuat kesepuluh jenis soal bisa diuji
 * dalam milidetik - dan kesepuluh jenis itu memang harus diuji, karena kunci
 * yang salah bentuk adalah kesalahan paling mahal di seluruh sistem: ia
 * tersimpan rapi, ujian berjalan lancar, dan baru ketahuan ketika 300 siswa
 * dinilai dengan kunci yang tidak pernah cocok.
 */
uses(TestCase::class);

/**
 * Snapshot butir tanpa basis data.
 *
 * `forceFill` dipakai, bukan `create()`, justru supaya berkas ini tidak
 * menuntut migrasi. Yang diuji adalah perilaku `nilai()`, dan perilaku itu
 * hanya membaca kolom yang diisi di sini.
 *
 * @param  array<string, mixed>|null  $kunci
 * @param  array<string, mixed>  $skor
 */
function butirNilai(
    JenisSoal $jenis,
    ?array $kunci,
    float $bobot = 1.0,
    array $skor = [],
): ExamQuestion {
    $soal = new ExamQuestion;

    $soal->forceFill([
        'jenis' => $jenis,
        'bobot' => $bobot,
        'kunci_json' => $kunci,
        'skor_json' => $skor,
    ]);

    return $soal;
}

/** Nilai satu butir dengan penilai baru - kelasnya memang murni. */
function nilaiButir(ExamQuestion $soal, ?array $jawaban): array
{
    return (new PenilaiButir)->nilai($soal, $jawaban);
}

/* =====================================================================
 | Pilihan ganda - tepat satu kunci
 |===================================================================== */

it('menilai pilihan ganda menurut himpunan pilihan, bukan urutan', function () {
    $soal = butirNilai(JenisSoal::PilihanGanda, ['opsi' => ['a']], 2);

    expect(nilaiButir($soal, ['opsi' => ['a']]))->toBe(['benar' => true, 'skor' => 2.0])
        ->and(nilaiButir($soal, ['opsi' => ['b']]))->toBe(['benar' => false, 'skor' => 0.0])
        // Mengklik "A" lalu "B" tidak boleh berbeda hasilnya dari mengklik
        // "B" saja pada soal yang kuncinya "B" - karena itu himpunan.
        ->and(nilaiButir($soal, ['opsi' => ['b', 'a']]))->toBe(['benar' => false, 'skor' => 0.0]);
});

it('menilai jawaban kosong sebagai salah, bukan sebagai belum dinilai', function () {
    $soal = butirNilai(JenisSoal::PilihanGanda, ['opsi' => ['a']], 2);

    // `benar = false`, BUKAN null. Null berarti "belum dikoreksi", dan soal
    // objektif yang belum dikoreksi adalah soal yang tidak akan pernah
    // dikoreksi - mesinnya sudah selesai bekerja saat itu juga.
    foreach ([null, [], ['opsi' => []], ['opsi' => ['']]] as $kosong) {
        expect(nilaiButir($soal, $kosong))->toBe(['benar' => false, 'skor' => 0.0]);
    }
});

it('menolak pilihan ganda tanpa kunci dengan skor nol', function () {
    $soal = butirNilai(JenisSoal::PilihanGanda, ['opsi' => []], 2);

    expect(nilaiButir($soal, ['opsi' => ['a']]))->toBe(['benar' => false, 'skor' => 0.0]);
});

/* =====================================================================
 | Jawaban ganda - dengan dan tanpa skor parsial
 |===================================================================== */

it('menuntut seluruh kunci pada jawaban ganda tanpa skor parsial', function () {
    $soal = butirNilai(JenisSoal::JawabanGanda, ['opsi' => ['a', 'c']], 4);

    expect(nilaiButir($soal, ['opsi' => ['a', 'c']]))->toBe(['benar' => true, 'skor' => 4.0])
        ->and(nilaiButir($soal, ['opsi' => ['c', 'a']]))->toBe(['benar' => true, 'skor' => 4.0])
        // Satu dari dua kunci, tanpa parsial: tidak dapat poin.
        ->and(nilaiButir($soal, ['opsi' => ['a']]))->toBe(['benar' => false, 'skor' => 0.0]);
});

it('membuat centang-semua tidak lebih baik daripada tidak menjawab', function () {
    // Rumus parsialnya (benar - salah) / jumlah kunci. Memilih seluruh opsi
    // menghasilkan 0 - dan itulah yang membuat rumus ini layak dipakai.
    $soal = butirNilai(JenisSoal::JawabanGanda, ['opsi' => ['a', 'c']], 4, ['skor_parsial' => true]);

    expect(nilaiButir($soal, ['opsi' => ['a', 'c']]))->toBe(['benar' => true, 'skor' => 4.0])
        ->and(nilaiButir($soal, ['opsi' => ['a']]))->toBe(['benar' => false, 'skor' => 2.0])
        ->and(nilaiButir($soal, ['opsi' => ['a', 'b']]))->toBe(['benar' => false, 'skor' => 0.0])
        ->and(nilaiButir($soal, ['opsi' => ['a', 'b', 'c']]))->toBe(['benar' => false, 'skor' => 2.0]);
});

it('memotong skor parsial negatif ke nol', function () {
    $soal = butirNilai(JenisSoal::JawabanGanda, ['opsi' => ['a']], 4, ['skor_parsial' => true]);

    // Tiga pilihan salah, nol benar: (0 - 3) / 1 = -3. Poin negatif tidak
    // pernah tersimpan - nilai ujian yang bisa turun di bawah nol adalah
    // nilai yang tidak bisa dijelaskan ke orang tua.
    expect(nilaiButir($soal, ['opsi' => ['b', 'c', 'd']]))->toBe(['benar' => false, 'skor' => 0.0]);
});

it('mengabaikan skor parsial untuk pilihan ganda dan benar/salah', function () {
    // Satu pilihan benar dari dua kunci tidak ada artinya ketika kuncinya
    // hanya satu - karena itu parsial SENGAJA hanya untuk tiga jenis yang
    // punya lebih dari satu bagian.
    $pg = butirNilai(JenisSoal::PilihanGanda, ['opsi' => ['a']], 4, ['skor_parsial' => true]);
    $bs = butirNilai(JenisSoal::BenarSalah, ['opsi' => ['b']], 4, ['skor_parsial' => true]);

    expect(nilaiButir($pg, ['opsi' => ['b', 'a']]))->toBe(['benar' => false, 'skor' => 0.0])
        ->and(nilaiButir($bs, ['opsi' => ['s', 'b']]))->toBe(['benar' => false, 'skor' => 0.0]);
});

/* =====================================================================
 | Benar/salah
 |===================================================================== */

it('menilai benar/salah pada dua kunci yang sah', function () {
    $soal = butirNilai(JenisSoal::BenarSalah, ['opsi' => ['b']], 1);

    expect(nilaiButir($soal, ['opsi' => ['b']]))->toBe(['benar' => true, 'skor' => 1.0])
        ->and(nilaiButir($soal, ['opsi' => ['s']]))->toBe(['benar' => false, 'skor' => 0.0]);
});

/* =====================================================================
 | Isian singkat - normalisasi, dan batasnya
 |===================================================================== */

it('menerima varian ejaan isian tanpa melonggarkan isinya', function () {
    $soal = butirNilai(JenisSoal::IsianSingkat, ['teks' => ['7', 'tujuh']], 3);

    // Siswa mengetik "Jantung " dengan spasi di ujung, "JANTUNG" dengan Caps
    // Lock menyala, dan "jantung." karena terbiasa mengakhiri kalimat.
    // Menolak ketiganya berarti mesin menilai cara mengetik, bukan jawaban.
    foreach (['7', ' 7 ', 'TUJUH', 'tujuh.', 'Tujuh,'] as $diterima) {
        expect(nilaiButir($soal, ['teks' => $diterima]))->toBe(['benar' => true, 'skor' => 3.0]);
    }

    // Yang TIDAK dinormalkan: isinya. "jantung" dan "jantng" tetap berbeda.
    foreach (['delapan', '7,0', 'tujh', ''] as $ditolak) {
        expect(nilaiButir($soal, ['teks' => $ditolak]))->toBe(['benar' => false, 'skor' => 0.0]);
    }
});

it('menolak isian yang kuncinya bukan daftar varian', function () {
    $soal = butirNilai(JenisSoal::IsianSingkat, ['teks' => 'tujuh'], 3);

    // Bentuk kunci yang salah tidak diganti diam-diam. SkemaJenisSoal yang
    // mencegahnya tersimpan; di sini yang dibuktikan adalah penilaiannya
    // tidak menebak ketika bentuknya salah.
    expect(nilaiButir($soal, ['teks' => 'tujuh']))->toBe(['benar' => false, 'skor' => 0.0]);
});

/* =====================================================================
 | Isian rumpang - dinilai PER LUBANG, tidak pernah nol karena sebagian
 |===================================================================== */

it('menilai isian rumpang per lubang, dengan skor sebanding', function () {
    $soal = butirNilai(
        JenisSoal::IsianRumpang,
        ['rumpang' => [['gravitasi', 'gaya gravitasi'], ['newton', 'N'], ['neraca pegas'], ['gesek']]],
        4,
    );

    // Empat lubang benar: penuh. "gaya gravitasi" diterima sebagai varian.
    expect(nilaiButir($soal, ['rumpang' => ['gravitasi', 'newton', 'neraca pegas', 'gesek']]))
        ->toBe(['benar' => true, 'skor' => 4.0])
        ->and(nilaiButir($soal, ['rumpang' => ['gaya gravitasi', 'N', 'neraca pegas', 'gesek']]))
        ->toBe(['benar' => true, 'skor' => 4.0]);

    // Tiga dari empat: 3 poin, dan `benar` tetap false - nilai penuh hanya
    // bila SELURUH lubang tepat.
    expect(nilaiButir($soal, ['rumpang' => ['gravitasi', 'newton', 'neraca pegas', 'dorong']]))
        ->toBe(['benar' => false, 'skor' => 3.0]);
});

it('menghitung lubang kosong sebagai salah, bukan melewatinya', function () {
    // Kalau lubang kosong dilewati, menjawab satu dari empat dengan benar
    // bernilai 1.0 skala penuh - persis kesalahan yang membuat nilai rumpang
    // tidak bisa dipercaya.
    $soal = butirNilai(
        JenisSoal::IsianRumpang,
        ['rumpang' => [['gravitasi'], ['newton'], ['neraca'], ['gesek']]],
        4,
    );

    expect(nilaiButir($soal, ['rumpang' => ['gravitasi', '', '', '']]))
        ->toBe(['benar' => false, 'skor' => 1.0])
        ->and(nilaiButir($soal, ['rumpang' => ['', '', '', '']]))
        ->toBe(['benar' => false, 'skor' => 0.0]);
});

it('menormalkan ejaan tiap lubang dengan aturan yang sama seperti isian singkat', function () {
    $soal = butirNilai(
        JenisSoal::IsianRumpang,
        ['rumpang' => [['Newton'], ['Gravitasi']]],
        2,
    );

    foreach ([
        ['newton', 'gravitasi'],
        [' NEWTON ', 'GRAVITASI.'],
        ['Newton,', 'Gravitasi'],
    ] as $diterima) {
        expect(nilaiButir($soal, ['rumpang' => $diterima]))->toBe(['benar' => true, 'skor' => 2.0]);
    }

    // Isinya tetap tidak dilonggarkan.
    expect(nilaiButir($soal, ['rumpang' => ['newtn', 'gravitas']]))
        ->toBe(['benar' => false, 'skor' => 0.0]);
});

it('menilai rumpang yang kuncinya bukan daftar sebagai nol, tanpa menebak', function () {
    $soal = butirNilai(JenisSoal::IsianRumpang, ['rumpang' => 'gravitasi'], 2);

    expect(nilaiButir($soal, ['rumpang' => ['gravitasi']]))->toBe(['benar' => false, 'skor' => 0.0])
        ->and(nilaiButir($soal, ['teks' => 'gravitasi']))->toBe(['benar' => false, 'skor' => 0.0]);
});

/* =====================================================================
 | Menjodohkan
 |===================================================================== */

it('menilai menjodohkan per pasangan, dengan dan tanpa parsial', function () {
    $kunci = ['pasangan' => ['k1' => 'c1', 'k2' => 'c2']];

    $utuh = butirNilai(JenisSoal::Menjodohkan, $kunci, 2);
    $parsial = butirNilai(JenisSoal::Menjodohkan, $kunci, 2, ['skor_parsial' => true]);

    expect(nilaiButir($utuh, ['pasangan' => ['k1' => 'c1', 'k2' => 'c2']]))->toBe(['benar' => true, 'skor' => 2.0])
        // Satu pasangan benar dari dua, tanpa parsial: nol.
        ->and(nilaiButir($utuh, ['pasangan' => ['k1' => 'c1', 'k2' => 'c1']]))->toBe(['benar' => false, 'skor' => 0.0])
        ->and(nilaiButir($parsial, ['pasangan' => ['k1' => 'c1', 'k2' => 'c1']]))->toBe(['benar' => false, 'skor' => 1.0])
        ->and(nilaiButir($parsial, ['pasangan' => ['k1' => 'x', 'k2' => 'y']]))->toBe(['benar' => false, 'skor' => 0.0]);
});

it('menilai pasangan menggantung sebagai salah, bukan sebagai galat', function () {
    $soal = butirNilai(JenisSoal::Menjodohkan, ['pasangan' => ['k1' => 'c1', 'k2' => 'c2']], 2);

    expect(nilaiButir($soal, ['pasangan' => ['k1' => 'c1']]))->toBe(['benar' => false, 'skor' => 0.0]);
});

/* =====================================================================
 | Mengurutkan - kredit dari POSISI, bukan pasangan berurutan
 |===================================================================== */

it('memberi kredit mengurutkan dari posisi yang sudah benar', function () {
    $soal = butirNilai(JenisSoal::Mengurutkan, ['urutan' => ['c', 'a', 'b']], 3, ['skor_parsial' => true]);

    expect(nilaiButir($soal, ['urutan' => ['c', 'a', 'b']]))->toBe(['benar' => true, 'skor' => 3.0])
        // Menukar dua langkah terakhir: satu dari tiga posisi benar.
        ->and(nilaiButir($soal, ['urutan' => ['c', 'b', 'a']]))->toBe(['benar' => false, 'skor' => 1.0])
        // Membalik seluruhnya menghasilkan NOL, bukan satu: posisi pertama
        // kunci adalah 'c', dan jawaban yang dibalik menaruh 'a' di sana.
        // Ini yang membedakan kredit-per-posisi dari kredit-per-pasangan
        // berurutan - urutan terbalik tidak menyisakan satu pun posisi benar.
        ->and(nilaiButir($soal, ['urutan' => ['a', 'b', 'c']]))->toBe(['benar' => false, 'skor' => 0.0]);
});

it('menuntut urutan utuh bila skor parsial tidak dikonfigurasi', function () {
    $soal = butirNilai(JenisSoal::Mengurutkan, ['urutan' => ['c', 'a', 'b']], 3);

    expect(nilaiButir($soal, ['urutan' => ['c', 'b', 'a']]))->toBe(['benar' => false, 'skor' => 0.0]);
});

/* =====================================================================
 | Angka - koma desimal dan satuan
 |===================================================================== */

it('menerima koma desimal dan membuang satuan pada soal angka', function () {
    $soal = butirNilai(JenisSoal::Angka, ['nilai' => 3.14, 'toleransi' => 0.01], 2);

    // "3,14" adalah bentuk yang paling mungkin diketik siswa Indonesia, dan
    // membacanya sebagai 3 dan 14 sekaligus adalah cara paling cepat membuat
    // soal angka selalu salah.
    foreach (['3,14', '3.14', '3,14 cm', ' 3.14 ', 3.14] as $diterima) {
        expect(nilaiButir($soal, ['nilai' => $diterima]))->toBe(['benar' => true, 'skor' => 2.0]);
    }

    // Satuan pada jawaban DIBUANG sebelum angkanya dibandingkan, sehingga
    // "3,14 m" dinilai benar. Menilai satuan sebagai bagian dari jawaban
    // berarti soal aritmetika menghukum kesalahan penulisan.
    expect(nilaiButir($soal, ['nilai' => '3,14 m']))->toBe(['benar' => true, 'skor' => 2.0])
        ->and(nilaiButir($soal, ['nilai' => '3,2']))->toBe(['benar' => false, 'skor' => 0.0])
        ->and(nilaiButir($soal, ['nilai' => 'empat']))->toBe(['benar' => false, 'skor' => 0.0]);
});

it('menutup galat pembulatan biner pada toleransi nol', function () {
    // Kasus yang paling sering justru toleransi 0, dan di sana |3.14 - 3.14|
    // bisa menghasilkan 4.44e-16 - jawaban yang benar ditolak karena akibat
    // cara komputer menyimpan pecahan.
    $soal = butirNilai(JenisSoal::Angka, ['nilai' => 3.14], 2);

    expect(nilaiButir($soal, ['nilai' => '3.14']))->toBe(['benar' => true, 'skor' => 2.0])
        ->and(nilaiButir($soal, ['nilai' => '3.15']))->toBe(['benar' => false, 'skor' => 0.0]);
});

it('mempertahankan tanda minus pada jawaban angka', function () {
    $soal = butirNilai(JenisSoal::Angka, ['nilai' => -5, 'toleransi' => 0.1], 2);

    expect(nilaiButir($soal, ['nilai' => '-5']))->toBe(['benar' => true, 'skor' => 2.0])
        ->and(nilaiButir($soal, ['nilai' => '5']))->toBe(['benar' => false, 'skor' => 0.0]);
});

it('menolak kunci angka yang bukan angka', function () {
    $soal = butirNilai(JenisSoal::Angka, ['nilai' => 'tiga belas'], 2);

    expect(nilaiButir($soal, ['nilai' => 13]))->toBe(['benar' => false, 'skor' => 0.0]);
});

/* =====================================================================
 | Uraian - mesin tidak menyentuhnya sama sekali
 |===================================================================== */

it('tidak menyentuh uraian, sehingga belum dinilai tetap bisa dibedakan dari salah', function () {
    $soal = butirNilai(JenisSoal::Uraian, null, 5);

    // `benar = null`, bukan false. Mengembalikan false/0 akan membuat setiap
    // uraian yang belum dikoreksi tercatat SALAH - dan laporan yang
    // membacanya tidak bisa membedakan "belum dinilai" dari "salah".
    expect(nilaiButir($soal, ['teks' => 'Jawaban panjang']))->toBe(['benar' => null, 'skor' => 0.0])
        ->and(nilaiButir($soal, null))->toBe(['benar' => null, 'skor' => 0.0]);
});

/* =====================================================================
 | Media - mekanismenya dari konfigurasi, bukan dari jenisnya
 |===================================================================== */

it('mengikuti mekanisme pada soal media', function () {
    $pilihan = butirNilai(JenisSoal::Media, ['opsi' => ['a']], 1, ['mekanisme' => 'pilihan']);
    $isian = butirNilai(JenisSoal::Media, ['teks' => ['tujuh']], 1, ['mekanisme' => 'isian']);
    $benarSalah = butirNilai(JenisSoal::Media, ['opsi' => ['b']], 1, ['mekanisme' => 'benar_salah']);

    expect(nilaiButir($pilihan, ['opsi' => ['a']]))->toBe(['benar' => true, 'skor' => 1.0])
        // Bentuk jawaban yang BUKAN mekanismenya dinilai salah - soal media
        // yang mekanismenya pilihan tidak bisa dijawab dengan teks.
        ->and(nilaiButir($pilihan, ['teks' => 'a']))->toBe(['benar' => false, 'skor' => 0.0])
        ->and(nilaiButir($isian, ['teks' => 'TUJUH']))->toBe(['benar' => true, 'skor' => 1.0])
        ->and(nilaiButir($benarSalah, ['opsi' => ['b']]))->toBe(['benar' => true, 'skor' => 1.0]);
});

it('memperlakukan mekanisme yang tidak dikenal sebagai pilihan', function () {
    // Peramban versi baru yang mengirim mekanisme yang belum ada di daftar
    // tidak boleh membuat butirnya tidak bisa dinilai sama sekali.
    $soal = butirNilai(JenisSoal::Media, ['opsi' => ['a']], 1, ['mekanisme' => 'entah']);

    expect(nilaiButir($soal, ['opsi' => ['a']]))->toBe(['benar' => true, 'skor' => 1.0]);
});

/* =====================================================================
 | Bobot dan pembulatan
 |===================================================================== */

it('membaca bobot dari snapshot, bukan dari angka di dalam kunci', function () {
    $soal = butirNilai(JenisSoal::PilihanGanda, ['opsi' => ['a'], 'bobot' => 99], 2);

    // `bobot` di dalam `kunci_json` diabaikan: bobot yang berlaku datang dari
    // `ExamQuestion::bobotBerlaku()`, yang sudah memperhitungkan
    // `points_override` paket saat dibekukan.
    expect(nilaiButir($soal, ['opsi' => ['a']]))->toBe(['benar' => true, 'skor' => 2.0]);
});

it('membulatkan skor ke dua desimal, sama dengan presisi kolomnya', function () {
    // 1/3 x 12 = 3.9999999999999996. Tanpa pembulatan, ia tersimpan sebagai
    // 4.00 tetapi terbaca "tidak penuh" oleh perbandingan apa pun yang
    // memakai nilai mentahnya.
    $soal = butirNilai(JenisSoal::Mengurutkan, ['urutan' => ['a', 'b', 'c']], 12, ['skor_parsial' => true]);

    $hasil = nilaiButir($soal, ['urutan' => ['a', 'c', 'b']]);

    expect($hasil['skor'])->toBe(4.0)
        ->and($hasil['benar'])->toBeFalse();
});
