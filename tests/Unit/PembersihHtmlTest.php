<?php

use App\Domain\Question\Support\PembersihHtml;
use App\Domain\Question\Support\SkemaJenisSoal;

/**
 * Pembersih HTML - satu-satunya jalur masuk teks berformat ke bank soal.
 *
 * =====================================================================
 * Mengapa berkas ini ada
 * =====================================================================
 * Soal ditulis Guru di editor berformat, dan hasilnya adalah HTML mentah dari
 * `contenteditable` - yang berarti HTML apa pun yang ada di clipboard. Kalau
 * ia disimpan apa adanya, ia dikirim ke peramban SISWA: 300 di antaranya, di
 * jaringan sekolah yang sama, pada jam yang sama.
 *
 * Karena itu yang diuji di sini bukan "apakah tag cantik bertahan" melainkan
 * "apakah ada jalan apa pun bagi kode untuk sampai ke peramban siswa". Setiap
 * test di bawah ini mewakili satu jalan masuk, dan kegagalannya tidak
 * menimbulkan galat di mana pun - hanya soal yang tampak normal sambil
 * menjalankan sesuatu di layar siswa.
 *
 * ---------------------------------------------------------------------
 * Kasus entitas adalah yang paling mudah lolos, dan paling perlu diuji
 * ---------------------------------------------------------------------
 * Pembersih yang menyaring daftar tag saja akan meloloskan
 * `&lt;script&gt;alert(1)&lt;/script&gt;`: parser mengurainya menjadi teks,
 * dan pembersih yang menulis kembali teks itu MENTAH mengubahnya kembali
 * menjadi tag yang dijalankan peramban. Satu baris htmlspecialchars adalah
 * bedanya, dan test `mempertahankan teks bertanda kurung sebagai teks`
 * adalah satu-satunya yang menangkapnya.
 */
beforeEach(function () {
    $this->bersih = new PembersihHtml;
});

/** Pintasan: bersihkan satu blok HTML. */
function bersih(string $html): string
{
    return (new PembersihHtml)->bersihkan($html);
}

/* ------------------------------------------------- Yang harus dibuang */

it('membuang script beserta isinya', function () {
    // Isi script adalah KODE, bukan teks. Meneruskannya sebagai teks
    // menghasilkan soal bertabur "function(){...}" - yang bagi Guru tampak
    // seperti soal rusak, bukan seperti serangan yang berhasil ditolak.
    expect(bersih('<script>alert(1)</script>halo'))->toBe('halo')
        ->and(bersih('halo<script>alert(1)</script>'))->toBe('halo')
        ->and(bersih('<div>a<script>alert(1)</script>b</div>'))->toBe('<div>ab</div>');
});

it('membuang iframe, style, dan tag bersarang berbahaya lain', function () {
    expect(bersih('<iframe src="http://jahat.example"></iframe>aman'))->toBe('aman')
        ->and(bersih('<style>p{color:red}</style>teks'))->toBe('teks')
        ->and(bersih('<object data="x"></object>a'))->toBe('a')
        ->and(bersih('<svg onload="alert(1)">x</svg>b'))->toBe('b');
});

it('membuang seluruh atribut penanganan peristiwa', function () {
    // `on*` adalah jalan masuk yang tidak butuh tag berbahaya sama sekali:
    // `<b onclick>` adalah tag yang sah dengan perilaku yang tidak.
    expect(bersih('<b onclick="alert(1)">tebal</b>'))->toBe('<b>tebal</b>')
        ->and(bersih('<p onmouseover="x()" onfocus="y()">a</p>'))->toBe('<p>a</p>')
        ->and(bersih('<div onerror="z()">a</div>'))->toBe('<div>a</div>');
});

it('membuang atribut yang tidak dikenal, termasuk class', function () {
    // class dan id dibuang bukan karena berbahaya, tetapi karena tempelan
    // dari Word membawa puluhan kelas yang tidak ada di lembar gaya aplikasi
    // ini - dan hasilnya adalah soal yang tampil dengan ukuran huruf asing.
    expect(bersih('<p class="MsoNormal" id="x">a</p>'))->toBe('<p>a</p>');
});

it('membuang tautan javascript dan menyisakan teksnya', function () {
    // Teksnya DIPERTAHANKAN: Guru yang menempelkan kalimat bertaut tidak
    // boleh kehilangan kalimatnya hanya karena tautannya tidak sah.
    expect(bersih('<a href="javascript:alert(1)">klik</a>'))->toBe('klik')
        ->and(bersih('<a href="JaVaScRiPt:alert(1)">klik</a>'))->toBe('klik')
        ->and(bersih('<a href="data:text/html,<script>x</script>">klik</a>'))->toBe('klik');
});

it('membuang gambar, karena media diunggah dan bukan ditautkan', function () {
    // Keputusan yang sudah tertulis di wireframe plate 03: soal tidak boleh
    // bergantung pada layanan luar. Gambar masuk lewat question_media.
    expect(bersih('<img src="http://luar.example/a.png" onerror="alert(1)">teks'))->toBe('teks');
});

it('membuang tag script yang lahir dari entitas', function () {
    // Kasus yang paling mudah lolos: parser mengurai `&lt;script&gt;`
    // menjadi teks bertuliskan `<script>`, dan pembersih yang menulis
    // kembali teks itu MENTAH mengubahnya kembali menjadi tag yang
    // dijalankan peramban.
    expect(bersih('&lt;script&gt;alert(1)&lt;/script&gt;'))
        ->toBe('&lt;script&gt;alert(1)&lt;/script&gt;');
});

/* ------------------------------------------------- Yang harus bertahan */

it('mempertahankan tag format yang dipakai soal', function () {
    expect(bersih('<b>tebal</b>'))->toBe('<b>tebal</b>')
        ->and(bersih('<i>miring</i>'))->toBe('<i>miring</i>')
        ->and(bersih('<u>garis</u>'))->toBe('<u>garis</u>')
        ->and(bersih('<strong>tegas</strong>'))->toBe('<strong>tegas</strong>')
        ->and(bersih('<em>tekanan</em>'))->toBe('<em>tekanan</em>');
});

it('mempertahankan sup dan sub, yang tanpa keduanya soal matematika rusak', function () {
    expect(bersih('x<sup>2</sup> + y<sub>1</sub>'))
        ->toBe('x<sup>2</sup> + y<sub>1</sub>');
});

it('mempertahankan tabel beserta colspan dan rowspan', function () {
    $html = '<table><thead><tr><th colspan="2">Nama</th></tr></thead>'
        .'<tbody><tr><td rowspan="2">a</td><td>b</td></tr></tbody></table>';

    expect(bersih($html))->toBe($html);
});

it('mempertahankan daftar dan baris baru', function () {
    expect(bersih('a<br>b'))->toBe('a<br>b')
        ->and(bersih('<ol><li>satu</li></ol>'))->toBe('<ol><li>satu</li></ol>')
        ->and(bersih('<ul><li>satu</li></ul>'))->toBe('<ul><li>satu</li></ul>');
});

it('mempertahankan tautan http dan https, juga tautan relatif', function () {
    expect(bersih('<a href="https://contoh.id/a">a</a>'))
        ->toBe('<a href="https://contoh.id/a">a</a>')
        ->and(bersih('<a href="http://contoh.id">a</a>'))
        ->toBe('<a href="http://contoh.id">a</a>')
        // Tautan relatif menunjuk ke dalam aplikasi ini sendiri.
        ->and(bersih('<a href="/guru/bank-soal">a</a>'))
        ->toBe('<a href="/guru/bank-soal">a</a>');
});

it('mempertahankan perataan tetapi membuang warna dan ukuran huruf', function () {
    // text-align mempengaruhi BACA: soal yang memuat tabel atau puisi
    // kehilangan maknanya bila perataannya hilang. Warna dan ukuran huruf
    // tidak punya alasan yang sama, dan keduanya yang paling sering menempel
    // dari web lain - menghasilkan soal yang tidak terbaca di latar tema ini.
    expect(bersih('<p style="text-align: center">a</p>'))
        ->toBe('<p style="text-align: center">a</p>')
        ->and(bersih('<p style="color: red; text-align: right">a</p>'))
        ->toBe('<p style="text-align: right">a</p>')
        ->and(bersih('<span style="font-size: 42px">a</span>'))
        ->toBe('<span>a</span>');
});

it('membuang nilai style yang menyisipkan url atau ekspresi', function () {
    expect(bersih('<p style="text-align: url(jahat)">a</p>'))->toBe('<p>a</p>')
        ->and(bersih('<p style="text-align: expression(alert(1))">a</p>'))->toBe('<p>a</p>');
});

/* -------------------------------------- Tag asing yang isinya berguna */

it('mempertahankan isi tag asing dan membuang tag-nya', function () {
    // Ini yang membuat tempelan dari Word tetap terbaca perangkat: teksnya
    // datang, formatnya tidak. Kebalikannya - membuang seluruh isi - akan
    // menghasilkan kotak kosong bagi Guru yang menempel soal dari dokumen.
    expect(bersih('<font color="red">a</font>'))->toBe('a')
        ->and(bersih('<marquee>a</marquee>'))->toBe('a')
        ->and(bersih('<center><p>a</p></center>'))->toBe('<p>a</p>');
});

/* ----------------------------------------------------- Bentuk masukan */

it('mengembalikan teks kosong untuk masukan kosong atau hanya spasi', function () {
    expect(bersih(''))->toBe('')
        ->and(bersih('   '))->toBe('')
        ->and(bersih("\n\t "))->toBe('');
});

it('memotong teks yang melebihi batas, alih-alih menolaknya', function () {
    // Memotong adalah kegagalan yang TERLIHAT di layar Guru; menolak simpan
    // dengan pesan "terlalu panjang" lebih sulit dijelaskan daripada teks
    // yang berhenti. Yang penting adalah batasnya ditegakkan di sini, bukan
    // di peramban - permintaan HTTP bisa dikirim tanpa melewati editor.
    $hasil = bersih(str_repeat('a', SkemaJenisSoal::BATAS_TEKS + 500));

    expect(mb_strlen($hasil))->toBe(SkemaJenisSoal::BATAS_TEKS)
        ->and($hasil)->toBe(str_repeat('a', SkemaJenisSoal::BATAS_TEKS));
});

it('tidak rusak oleh HTML yang tidak seimbang', function () {
    // HTML dari contenteditable memang sering tidak seimbang. Yang benar
    // adalah membiarkan parser menutupnya, bukan menolak simpan Guru.
    expect(bersih('<b>tebal'))->toBe('<b>tebal</b>')
        ->and(bersih('<p>a<p>b'))->toBe('<p>a</p><p>b</p>');
});

it('tidak merusak huruf beraksen dan simbol matematika', function () {
    // Tanpa trik penyandian di urai(), soal berbahasa Indonesia dan simbol
    // matematika rusak menjadi mojibake.
    expect(bersih('<p>sudut α = 30° — benar</p>'))
        ->toBe('<p>sudut α = 30° — benar</p>');
});

/* ------------------------------------------------ Pembersihan rekursif */

it('membersihkan seluruh nilai JSON secara rekursif dan mempertahankan kuncinya', function () {
    // Membersihkan hanya kolom pertama berarti meninggalkan jalur masuk yang
    // sama terbuka di kolom lain - kesalahan yang paling mudah terjadi
    // karena yang diperiksa mata adalah kolom pertama di daftar.
    $hasil = (new PembersihHtml)->bersihkanDalam([
        'teks' => '<b>soal</b>',
        'opsi' => [
            ['isi' => '<script>x</script>a'],
            ['isi' => '<i>b</i>'],
        ],
    ]);

    expect($hasil)->toBe([
        'teks' => '<b>soal</b>',
        'opsi' => [
            ['isi' => 'a'],
            ['isi' => '<i>b</i>'],
        ],
    ]);

    // Kuncinya TIDAK berubah: ia nama field yang dikunci skema, dan
    // membersihkannya berarti mengubah bentuk JSON itu sendiri.
    expect(array_keys($hasil['opsi'][0]))->toBe(['isi']);
});

it('meneruskan nilai yang bukan teks apa adanya', function () {
    $hasil = (new PembersihHtml)->bersihkanDalam([
        'nilai' => 3.14,
        'benar' => true,
        'kosong' => null,
    ]);

    expect($hasil)->toBe(['nilai' => 3.14, 'benar' => true, 'kosong' => null]);
});
