<?php

use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Support\SkemaJenisSoal;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/*
 * Berkas ini BUTUH aplikasi ter-boot, dan itu satu-satunya alasan baris
 * `uses()` di bawah ada.
 *
 * Bukan karena ia menyentuh basis data - ia tidak menyentuh sama sekali.
 * Alasannya adalah SkemaJenisSoal melempar ValidationException, dan
 * ValidationException::withMessages() memerlukan facade root yang hanya ada
 * setelah aplikasi dibangun. Tanpa `uses()`, seluruh test di berkas ini gagal
 * dengan "A facade root has not been set" - galat yang menunjuk ke dalam
 * pustaka Laravel dan sama sekali tidak menyebut penyebabnya.
 *
 * RefreshDatabase SENGAJA tidak dipakai: menyalakannya berarti setiap test di
 * sini membayar biaya migrasi untuk sesuatu yang jawabannya fungsi murni.
 */
uses(TestCase::class);

/**
 * Bentuk kunci kesembilan jenis soal.
 *
 * =====================================================================
 * Mengapa berkas ini ada, dan mengapa ia tidak menyentuh basis data
 * =====================================================================
 * Kesembilan bentuk kunci di SkemaJenisSoal adalah tempat kesalahan paling
 * MAHAL di seluruh Fase 4, dan sekaligus yang paling MURAH diuji.
 *
 * Mahal, karena kunci yang salah bentuk tidak menimbulkan galat apa pun: ia
 * tersimpan rapi di JSON, ujian berjalan lancar, dan baru ketahuan ketika
 * 300 siswa dinilai dengan kunci yang tidak pernah cocok dengan jawaban
 * mereka. Tidak ada test lain di sistem ini yang bisa menangkapnya - uji
 * coba hanya membuktikan bahwa soal yang DIBUAT saat itu bisa dibuka.
 *
 * Murah, karena seluruhnya bisa dijawab tanpa basis data: pertanyaannya
 * "apakah kunci berbentuk begini sah untuk jenis begini", dan jawabannya
 * adalah fungsi murni.
 *
 * ---------------------------------------------------------------------
 * Setiap jenis punya test positif DAN negatifnya
 * ---------------------------------------------------------------------
 * Test yang hanya membuktikan kunci yang benar diterima tidak membuktikan
 * apa pun tentang bentuk yang salah - dan justru bentuk yang salah itu yang
 * akan dikirim Guru. Karena itu setiap jenis diuji dua kali.
 */
beforeEach(function () {
    $this->skema = new SkemaJenisSoal;
});

/**
 * Jalankan validasi dan kembalikan pesan galatnya, atau null bila lolos.
 *
 * Dipakai alih-alih `expect()->toThrow()` karena yang perlu diperiksa
 * bukan "ia melempar" melainkan "ia melempar pada KOLOM yang benar" - dan
 * kolom itulah yang menentukan di sebelah field mana Guru melihat pesannya.
 *
 * @param  array<int, array<string, mixed>>  $opsi
 * @param  array<int, array<string, mixed>>  $butir
 * @param  array<int, array<string, mixed>>  $rubrik
 * @return array<string, array<int, string>>|null
 */
function galatKunci(
    JenisSoal $jenis,
    ?array $kunci,
    float $bobot = 1,
    array $opsi = [],
    array $butir = [],
    array $rubrik = [],
    array $skor = [],
): ?array {
    try {
        (new SkemaJenisSoal)->validasi($jenis, $kunci, $bobot, $opsi, $butir, $rubrik, $skor);

        return null;
    } catch (ValidationException $e) {
        return $e->errors();
    }
}

/* ------------------------------------------------------------ Label */

it('memberi setiap jenis soal label Indonesia yang unik', function () {
    $label = array_map(fn (JenisSoal $j) => $j->label(), JenisSoal::cases());
    $pendek = array_map(fn (JenisSoal $j) => $j->pendek(), JenisSoal::cases());

    expect(JenisSoal::cases())->toHaveCount(9)
        ->and(array_unique($label))->toHaveCount(9)
        // Label pendek dipakai sebagai chip di tabel; dua jenis dengan chip
        // yang sama membuat daftar bank soal tidak bisa dibaca sekilas.
        ->and(array_unique($pendek))->toHaveCount(9)
        ->and($label)->not->toContain('', null);
});

it('hanya uraian yang dinilai manual dan menuntut rubrik', function () {
    $manual = array_values(array_filter(
        JenisSoal::cases(),
        fn (JenisSoal $j) => ! $j->otomatis(),
    ));

    expect($manual)->toBe([JenisSoal::Uraian])
        ->and(JenisSoal::Uraian->perluRubrik())->toBeTrue();

    foreach (JenisSoal::cases() as $j) {
        if ($j !== JenisSoal::Uraian) {
            expect($j->perluRubrik())->toBeFalse();
        }
    }
});

/* ------------------------------------------------- Pilihan ganda */

it('menerima pilihan ganda dengan tepat satu kunci', function () {
    $galat = galatKunci(
        JenisSoal::PilihanGanda,
        ['opsi' => ['a']],
        opsi: [
            ['option_key' => 'a'],
            ['option_key' => 'b'],
        ],
    );

    expect($galat)->toBeNull();
});

it('menolak pilihan ganda yang kuncinya lebih dari satu', function () {
    // Dua kunci pada jenis yang hanya boleh punya satu berarti Guru salah
    // memilih jenis - dan menyimpannya sebagai pilihan ganda berarti siswa
    // yang menjawab A dinilai salah separuh tanpa penjelasan.
    $galat = galatKunci(JenisSoal::PilihanGanda, ['opsi' => ['a', 'b']]);

    expect($galat)->toHaveKey('kunci')
        ->and($galat['kunci'][0])->toContain('tepat satu');
});

it('menolak pilihan ganda yang kuncinya kosong', function () {
    expect(galatKunci(JenisSoal::PilihanGanda, null))->toHaveKey('kunci')
        ->and(galatKunci(JenisSoal::PilihanGanda, []))->toHaveKey('kunci');
});

it('menolak kunci yang menunjuk opsi yang tidak ada', function () {
    $galat = galatKunci(
        JenisSoal::PilihanGanda,
        ['opsi' => ['d']],
        opsi: [['option_key' => 'a'], ['option_key' => 'b']],
    );

    expect($galat)->toHaveKey('kunci')
        ->and($galat['kunci'][0])->toContain('"d"');
});

it('menormalkan besar-kecil huruf kunci pilihan ganda', function () {
    // "A" di kunci dan "a" di opsi adalah dua nilai berbeda bagi basis data
    // dan sama bagi manusia. Perbandingan yang gagal karena itu akan tampak
    // seperti kunci yang salah.
    $galat = galatKunci(
        JenisSoal::PilihanGanda,
        ['opsi' => ['A']],
        opsi: [['option_key' => 'a']],
    );

    expect($galat)->toBeNull();
});

/* ------------------------------------------------- Jawaban ganda */

it('menerima jawaban ganda dengan himpunan kunci', function () {
    $galat = galatKunci(
        JenisSoal::JawabanGanda,
        ['opsi' => ['a', 'c']],
        opsi: [
            ['option_key' => 'a'],
            ['option_key' => 'b'],
            ['option_key' => 'c'],
        ],
    );

    expect($galat)->toBeNull();
});

it('menolak jawaban ganda tanpa satu pun kunci', function () {
    expect(galatKunci(JenisSoal::JawabanGanda, ['opsi' => []]))->toHaveKey('kunci');
});

/* ---------------------------------------------------- Benar/salah */

it('menerima kunci b atau s pada soal benar salah', function () {
    foreach (['b', 's'] as $huruf) {
        expect(galatKunci(JenisSoal::BenarSalah, ['opsi' => [$huruf]]))->toBeNull();
    }
});

it('menolak kunci selain b atau s pada soal benar salah', function () {
    // Jenis ini hanya punya dua kemungkinan. Kunci "a" atau "c" berarti Guru
    // mengira sedang menulis pilihan ganda.
    $galat = galatKunci(JenisSoal::BenarSalah, ['opsi' => ['x']]);

    expect($galat)->toHaveKey('kunci')
        ->and($galat['kunci'][0])->toContain('"b" atau "s"');
});

/* ---------------------------------------------------- Isian singkat */

it('menerima isian singkat dengan banyak varian jawaban', function () {
    $galat = galatKunci(JenisSoal::IsianSingkat, ['teks' => ['7', 'tujuh']]);

    expect($galat)->toBeNull();
});

it('menolak isian singkat tanpa varian, dan varian yang kosong', function () {
    expect(galatKunci(JenisSoal::IsianSingkat, ['teks' => []]))->toHaveKey('kunci')
        ->and(galatKunci(JenisSoal::IsianSingkat, []))->toHaveKey('kunci')
        ->and(galatKunci(JenisSoal::IsianSingkat, ['teks' => ['7', '  ']]))->toHaveKey('kunci');
});

/* ----------------------------------------------------- Menjodohkan */

it('menerima menjodohkan dengan pasangan lengkap', function () {
    $galat = galatKunci(
        JenisSoal::Menjodohkan,
        ['pasangan' => ['k1' => 'c1', 'k2' => 'c2']],
        butir: [
            ['item_key' => 'k1', 'item_group' => 'kiri'],
            ['item_key' => 'k2', 'item_group' => 'kiri'],
            ['item_key' => 'c1', 'item_group' => 'kanan'],
            ['item_key' => 'c2', 'item_group' => 'kanan'],
        ],
    );

    expect($galat)->toBeNull();
});

it('menolak menjodohkan yang punya pasangan menggantung', function () {
    // Inilah kegagalan yang paling mahal di jenis ini: ia tersimpan rapi,
    // ujian berjalan, dan baru ketahuan saat siswa melihat satu item kiri
    // yang tidak punya pasangan sama sekali.
    $galat = galatKunci(
        JenisSoal::Menjodohkan,
        ['pasangan' => ['k1' => 'c1']],
        butir: [
            ['item_key' => 'k1', 'item_group' => 'kiri'],
            ['item_key' => 'k2', 'item_group' => 'kiri'],
            ['item_key' => 'c1', 'item_group' => 'kanan'],
        ],
    );

    expect($galat)->toHaveKey('kunci')
        ->and($galat['kunci'][0])->toContain('k2');
});

it('menolak menjodohkan yang menunjuk item kiri atau kanan yang tidak ada', function () {
    $butir = [
        ['item_key' => 'k1', 'item_group' => 'kiri'],
        ['item_key' => 'c1', 'item_group' => 'kanan'],
    ];

    expect(galatKunci(JenisSoal::Menjodohkan, ['pasangan' => ['hantu' => 'c1']], butir: $butir))
        ->toHaveKey('kunci');

    expect(galatKunci(JenisSoal::Menjodohkan, ['pasangan' => ['k1' => 'hantu']], butir: $butir))
        ->toHaveKey('kunci');
});

it('menolak menjodohkan yang butirnya hanya ada di satu sisi', function () {
    $galat = galatKunci(
        JenisSoal::Menjodohkan,
        ['pasangan' => ['k1' => 'c1']],
        butir: [
            ['item_key' => 'k1', 'item_group' => 'kiri'],
            ['item_key' => 'c1', 'item_group' => 'kiri'],
        ],
    );

    expect($galat)->toHaveKey('butir');
});

/* ----------------------------------------------------- Mengurutkan */

it('menerima mengurutkan dengan permutasi lengkap', function () {
    $galat = galatKunci(
        JenisSoal::Mengurutkan,
        ['urutan' => ['c', 'a', 'b']],
        butir: [
            ['item_key' => 'a'],
            ['item_key' => 'b'],
            ['item_key' => 'c'],
        ],
    );

    expect($galat)->toBeNull();
});

it('menolak urutan yang panjangnya tidak sama dengan jumlah item', function () {
    $galat = galatKunci(
        JenisSoal::Mengurutkan,
        ['urutan' => ['a', 'b']],
        butir: [['item_key' => 'a'], ['item_key' => 'b'], ['item_key' => 'c']],
    );

    expect($galat)->toHaveKey('kunci')
        ->and($galat['kunci'][0])->toContain('3');
});

it('menolak urutan yang memuat item sama dua kali', function () {
    // Panjangnya cocok, sehingga tanpa pemeriksaan ini ia LOLOS - dan siswa
    // yang menjawab dengan urutan benar tetap dinilai salah.
    $galat = galatKunci(
        JenisSoal::Mengurutkan,
        ['urutan' => ['a', 'a', 'b']],
        butir: [['item_key' => 'a'], ['item_key' => 'b'], ['item_key' => 'c']],
    );

    expect($galat)->toHaveKey('kunci')
        ->and($galat['kunci'][0])->toContain('dua kali');
});

/* ---------------------------------------------------------- Angka */

it('menerima soal angka dengan nilai dan toleransi', function () {
    expect(galatKunci(JenisSoal::Angka, ['nilai' => 3.14, 'toleransi' => 0.01, 'satuan' => 'cm']))
        ->toBeNull();

    // Toleransi boleh tidak disebut sama sekali: artinya jawaban harus persis.
    expect(galatKunci(JenisSoal::Angka, ['nilai' => 42]))->toBeNull();
});

it('menolak soal angka tanpa nilai bilangan dan dengan toleransi negatif', function () {
    expect(galatKunci(JenisSoal::Angka, ['nilai' => 'sekitar tiga']))->toHaveKey('kunci')
        ->and(galatKunci(JenisSoal::Angka, []))->toHaveKey('kunci')
        ->and(galatKunci(JenisSoal::Angka, ['nilai' => 1, 'toleransi' => -1]))->toHaveKey('kunci');
});

/* ---------------------------------------------------------- Uraian */

it('menuntut rubrik pada soal uraian dan menolaknya bila kosong', function () {
    expect(galatKunci(JenisSoal::Uraian, null, rubrik: []))->toHaveKey('rubrik');
});

it('menerima rubrik yang setiap kriterianya punya nama dan skor lebih dari nol', function () {
    $galat = galatKunci(
        JenisSoal::Uraian,
        null,
        rubrik: [
            ['criterion' => 'Menuliskan rumus', 'max_score' => 2],
            ['criterion' => 'Menghitung hasil', 'max_score' => 3],
        ],
    );

    expect($galat)->toBeNull();
});

it('menolak kriteria rubrik tanpa nama atau berskor nol', function () {
    expect(galatKunci(JenisSoal::Uraian, null, rubrik: [['criterion' => '', 'max_score' => 2]]))
        ->toHaveKey('rubrik');

    expect(galatKunci(JenisSoal::Uraian, null, rubrik: [['criterion' => 'Benar', 'max_score' => 0]]))
        ->toHaveKey('rubrik');
});

it('menolak kunci opsi pada soal uraian', function () {
    // Kunci di sini berarti Guru salah memilih jenis. Menyimpannya diam-diam
    // menghasilkan soal yang jawabannya tidak pernah bisa dikoreksi mesin
    // tetapi terlihat seperti bisa.
    $galat = galatKunci(
        JenisSoal::Uraian,
        ['opsi' => ['a']],
        rubrik: [['criterion' => 'Benar', 'max_score' => 1]],
    );

    expect($galat)->toHaveKey('kunci')
        ->and($galat['kunci'][0])->toContain('rubriknya');
});

/* ----------------------------------------------------------- Media */

it('menilai soal media mengikuti mekanisme yang dipilihnya', function () {
    // Mekanisme pilihan: kuncinya satu huruf.
    expect(galatKunci(
        JenisSoal::Media,
        ['opsi' => ['a']],
        opsi: [['option_key' => 'a']],
        skor: ['mekanisme' => 'pilihan'],
    ))->toBeNull();

    // Mekanisme isian menuntut varian teks, bukan huruf - dan kunci yang
    // berbentuk opsi DITOLAK, karena bentuknya milik mekanisme lain.
    expect(galatKunci(JenisSoal::Media, ['opsi' => ['a']], skor: ['mekanisme' => 'isian']))
        ->toHaveKey('kunci');
});

it('menolak mekanisme media yang tidak dikenal', function () {
    $galat = galatKunci(JenisSoal::Media, ['opsi' => ['a']], skor: ['mekanisme' => 'suara']);

    expect($galat)->toHaveKey('skor');
});

/* ----------------------------------------------------------- Bobot */

it('menolak bobot nol atau negatif untuk semua jenis', function () {
    // Soal yang tidak bernilai tidak punya tempat di ujian: ia bisa ada di
    // paket tanpa mempengaruhi apa pun, dan Guru yang menambahkannya
    // mengira sudah menambah satu soal.
    foreach (JenisSoal::cases() as $jenis) {
        $galat = galatKunci($jenis, ['teks' => ['x']], bobot: 0);

        // Pesannya memuat nama jenis: satu kegagalan di sini harus langsung
        // menunjuk jenis yang mana, karena yang diperiksa sembilan sekaligus.
        expect($galat)
            ->toHaveKey('bobot')
            ->and(
                $galat['bobot'][0] ?? '',
                "jenis {$jenis->value} lolos dengan bobot nol",
            )->toContain('lebih dari nol');
    }
});
