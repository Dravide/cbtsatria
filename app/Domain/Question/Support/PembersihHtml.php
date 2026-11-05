<?php

namespace App\Domain\Question\Support;

/**
 * Pembersih HTML untuk isi soal - satu-satunya jalur masuk teks berformat.
 *
 * ---------------------------------------------------------------------
 * Mengapa pembersihan ini wajib, dan bukan kehati-hatian berlebihan
 * ---------------------------------------------------------------------
 * Soal ditulis Guru di editor berformat, dan hasilnya adalah HTML. Pertanyaan
 * yang menentukan bentuk kelas ini: apa yang terjadi bila Guru menempelkan
 * sesuatu dari halaman web lain ke dalam kotak soal?
 *
 * Jawabannya adalah "apa saja". Clipboard membawa `<script>`, `<iframe>`,
 * atribut `onerror` pada `<img>`, dan `href="javascript:..."`. Kalau HTML itu
 * disimpan apa adanya, ia dikirim ke peramban SISWA - 300 di antaranya, di
 * jaringan sekolah yang sama, pada jam yang sama. XSS di bank soal bukan
 * risiko teoretis; ia satu-satunya cara satu Guru dapat mengendalikan
 * peramban seluruh peserta ujian.
 *
 * Pembersihan dilakukan DI SERVER, bukan di editor. Editor di sisi klien
 * adalah kenyamanan; batas kepercayaan tetap di sini, karena permintaan HTTP
 * bisa dikirim tanpa melewati editor sama sekali.
 *
 * ---------------------------------------------------------------------
 * Whitelist, bukan blacklist - dan itu perbedaan yang menentukan
 * ---------------------------------------------------------------------
 * Pendekatan blacklist ("buang script dan iframe") gagal setiap kali ada
 * tag baru yang ditemukan - `<svg onload>`, `<math>`, `<object>`, dan
 * seterusnya. Daftarnya tidak pernah selesai.
 *
 * Yang dipakai di sini adalah daftar PUTIH: tag di luar daftar dibuang
 * seluruhnya, atribut di luar daftar dibuang seluruhnya. Tag baru yang belum
 * dikenal siapa pun otomatis tertolak, dan itu sifat yang diinginkan.
 *
 * ---------------------------------------------------------------------
 * Yang dipertahankan, dan mengapa justru itu
 * ---------------------------------------------------------------------
 * Soal matematika butuh `sup` dan `sub` (pangkat dan indeks), Bahasa
 * Indonesia butuh `table` (teks bacaan bertabel), dan semuanya butuh `b i u`
 * serta daftar. Yang TIDAK dipertahankan adalah gaya inline kecuali
 * `text-align` - karena warna dan ukuran huruf yang ditempel dari web lain
 * menghasilkan soal yang tidak terbaca di latar tema aplikasi, dan itu
 * keluhan pertama yang akan datang.
 */
class PembersihHtml
{
    /**
     * Tag yang dipertahankan. Perbandingan dalam huruf kecil.
     *
     * Tidak ada `img` di sini: berkas media diunggah, bukan ditautkan - dan
     * itu keputusan yang sudah tertulis di wireframe plate 03 ("Berkas media
     * diunggah, bukan ditautkan, supaya soal tidak bergantung pada layanan
     * luar"). Media masuk lewat question_media, bukan lewat atribut src.
     *
     * @var array<int, string>
     */
    public const TAG_DIIZINKAN = [
        'p', 'br', 'b', 'strong', 'i', 'em', 'u', 's',
        'sup', 'sub', 'span', 'div',
        'ol', 'ul', 'li',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'blockquote', 'code', 'pre', 'a',
    ];

    /**
     * Atribut yang dipertahankan, per tag.
     *
     * @var array<string, array<int, string>>
     */
    private const ATRIBUT_DIIZINKAN = [
        'a' => ['href'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
        '*' => ['style'],
    ];

    /** Properti CSS yang dipertahankan di dalam atribut style. */
    private const GAYA_DIIZINKAN = ['text-align'];

    /**
     * Tag yang dibuang BESERTA ISI DI DALAMNYA, bukan hanya tag-nya.
     *
     * Perbedaan perlakuan ini bukan kerapian, melainkan satu-satunya hal yang
     * memisahkan "teks Word yang kehilangan format" dari "kode yang berubah
     * menjadi tulisan biasa". Isi `<script>` adalah kode; isi `<style>` adalah
     * CSS; isi `<iframe>` adalah dokumen lain. Meneruskan isinya sebagai teks
     * menghasilkan soal yang bertabur `function(){...}` di tengah kalimat -
     * dan for non-developer itu tampak seperti soal yang rusak, bukan seperti
     * serangan yang berhasil ditolak.
     *
     * Tag di luar daftar INI tetapi di luar daftar putih tetap mempertahankan
     * isinya, dan itu yang membuat tempelan dari Word tetap terbaca.
     *
     * @var array<int, string>
     */
    private const TAG_DIBUANG_BESERTA_ISINYA = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet',
        'noscript', 'template', 'svg', 'math', 'canvas',
        'frame', 'frameset', 'head', 'title', 'meta', 'link', 'base',
    ];

    /**
     * Bersihkan satu blok HTML.
     *
     * Mengembalikan HTML yang aman disimpan DAN aman dikirim ke peramban
     * siswa. Blok yang lebih panjang dari BATAS_TEKS dipotong, bukan
     * ditolak: memotong adalah kegagalan yang terlihat di layar Guru, dan
     * pesan galat panjang lebih sulit dijelaskan daripada teks yang terhenti.
     */
    public function bersihkan(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        if (mb_strlen($html) > SkemaJenisSoal::BATAS_TEKS) {
            $html = mb_substr($html, 0, SkemaJenisSoal::BATAS_TEKS);
        }

        return $this->susun($this->urai($html));
    }

    /**
     * Bersihkan nilai JSON secara rekursif.
     *
     * Dipakai untuk seluruh kolom `_json` soal: teks soal, isi opsi, isi
     * butir jodoh, pembahasan. Semuanya bisa memuat HTML, dan membersihkan
     * hanya `prompt_json` berarti meninggalkan tiga jalur masuk yang sama
     * terbuka - kesalahan yang paling mudah terjadi karena yang diperiksa
     * mata adalah kolom pertama di daftar.
     */
    public function bersihkanDalam(mixed $nilai): mixed
    {
        if (is_string($nilai)) {
            return $this->bersihkan($nilai);
        }

        if (is_array($nilai)) {
            $hasil = [];

            foreach ($nilai as $kunci => $isi) {
                // Kunci TIDAK dibersihkan: ia nama field (`teks`, `rumus`,
                // `opsi`), dan membersihkannya berarti mengubah bentuk JSON
                // yang justru dikunci skema.
                $hasil[$kunci] = $this->bersihkanDalam($isi);
            }

            return $hasil;
        }

        return $nilai;
    }

    /* ------------------------------------------------------- Mekanisme */

    /**
     * Urai HTML menjadi node, dengan libxml menangani kasus sulit.
     *
     * Tanpa `libxml_use_internal_errors`, HTML yang tidak seimbang -
     * `<b>teks` tanpa penutup - memunculkan peringatan ke log pada setiap
     * penyimpanan soal. Peringatan itu benar dan tidak berguna: HTML dari
     * contenteditable memang sering tidak seimbang, dan yang benar adalah
     * membiarkan parser menutupnya, bukan menolak simpan Guru.
     */
    private function urai(string $html): \DOMDocument
    {
        $sebelum = libxml_use_internal_errors(true);

        $dok = new \DOMDocument('1.0', 'UTF-8');

        // Trik pembungkus dan penyandian: tanpa `<?xml encoding>`, karakter
        // non-ASCII dari soal berbahasa Indonesia dan simbol matematika
        // rusak menjadi mojibake saat DOMDocument menebak Latin-1.
        $dok->loadHTML(
            '<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($sebelum);

        return $dok;
    }

    /**
     * Susun ulang node menjadi HTML, membuang apa pun yang tidak diizinkan.
     */
    private function susun(\DOMDocument $dok): string
    {
        $isi = $dok->getElementsByTagName('div')->item(0);

        if ($isi === null) {
            return '';
        }

        return $this->tulisAnak($isi);
    }

    /**
     * Serialkan seluruh anak sebuah node, melewati yang tidak sah.
     */
    private function tulisAnak(\DOMNode $induk): string
    {
        $html = '';

        foreach ($induk->childNodes as $anak) {
            $html .= $this->tulisNode($anak);
        }

        return $html;
    }

    private function tulisNode(\DOMNode $node): string
    {
        // Teks DISANDIKAN ULANG, dan itu wajib - bukan kerapian.
        //
        // Parser sudah MENGURAI entitas saat membaca: `&lt;script&gt;` masuk
        // sebagai teks bertuliskan `<script>`. Meneruskan nodeValue mentah
        // berarti menuliskan kembali tag itu ke HTML keluaran, dan peramban
        // menjalankannya. Pembatas yang dilewati lewat entitas adalah bentuk
        // XSS yang paling mudah lolos dari pembersih yang hanya menyaring tag.
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tag = strtolower($node->nodeName);

        // Tag yang isinya pun tidak boleh lolos. Diperiksa SEBELUM daftar
        // putih justru supaya urutannya tidak bisa salah: `style` dan
        // `script` tidak akan pernah masuk daftar putih, tetapi keduanya juga
        // tidak boleh diperlakukan seperti tag asing biasa.
        if (in_array($tag, self::TAG_DIBUANG_BESERTA_ISINYA, true)) {
            return '';
        }

        // Tag di luar daftar: ISI-nya dipertahankan, TAG-nya dibuang.
        //
        // Ini pilihan yang disengaja, dan bedanya terasa di layar: Guru yang
        // menempelkan soal dari dokumen Word mendapat teksnya tanpa
        // formatnya, bukan kotak kosong.
        if (! in_array($tag, self::TAG_DIIZINKAN, true)) {
            return $this->tulisAnak($node);
        }

        // `a` hanya dipertahankan bila tertaut ke http/https. Tautan
        // `javascript:` dibuang seluruhnya, dan itu satu-satunya bentuk
        // XSS yang bertahan lewat tag yang sah.
        if ($tag === 'a' && ! $this->tautanAman($node)) {
            return $this->tulisAnak($node);
        }

        $atribut = $this->tulisAtribut($node, $tag);
        $dalam = $this->tulisAnak($node);

        // Tag void: tidak punya isi dan tidak punya penutup.
        if (in_array($tag, ['br'], true)) {
            return '<br>';
        }

        return "<{$tag}{$atribut}>{$dalam}</{$tag}>";
    }

    private function tautanAman(\DOMElement $node): bool
    {
        $href = trim((string) $node->getAttribute('href'));

        if ($href === '') {
            return false;
        }

        $skema = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        // Tautan relatif (tanpa skema) diizinkan - ia menunjuk ke dalam
        // aplikasi ini sendiri.
        if ($skema === '') {
            return true;
        }

        return in_array($skema, ['http', 'https'], true);
    }

    private function tulisAtribut(\DOMElement $node, string $tag): string
    {
        $boleh = array_merge(
            self::ATRIBUT_DIIZINKAN['*'] ?? [],
            self::ATRIBUT_DIIZINKAN[$tag] ?? [],
        );

        $html = '';

        foreach ($node->attributes ?? [] as $atribut) {
            $nama = strtolower($atribut->nodeName);

            if (! in_array($nama, $boleh, true)) {
                continue;
            }

            if ($nama === 'style') {
                $gaya = $this->gayaAman($atribut->nodeValue ?? '');

                if ($gaya !== '') {
                    $html .= ' style="'.htmlspecialchars($gaya, ENT_QUOTES, 'UTF-8').'"';
                }

                continue;
            }

            $html .= ' '.$nama.'="'.htmlspecialchars($atribut->nodeValue ?? '', ENT_QUOTES, 'UTF-8').'"';
        }

        return $html;
    }

    /**
     * Saring atribut style menjadi hanya properti yang diizinkan.
     *
     * `text-align` dipertahankan karena ia mempengaruhi BACA, bukan sekadar
     * tampilan: soal yang memuat tabel atau puisi kehilangan maknanya bila
     * perataannya hilang. Warna dan ukuran huruf tidak punya alasan yang
     * sama, dan keduanya adalah yang paling sering menempel dari web lain.
     */
    private function gayaAman(string $style): string
    {
        $aman = [];

        foreach (explode(';', $style) as $bagian) {
            if (! str_contains($bagian, ':')) {
                continue;
            }

            [$properti, $nilai] = explode(':', $bagian, 2);
            $properti = strtolower(trim($properti));

            if (! in_array($properti, self::GAYA_DIIZINKAN, true)) {
                continue;
            }

            $nilai = trim($nilai);

            // Nilai yang menyisipkan URL atau ekspresi dibuang, bahkan pada
            // properti yang diizinkan. `text-align: expression(...)` adalah
            // sisa IE yang masih diterima sebagian mesin pembersih.
            if (preg_match('/url\s*\(|expression\s*\(|\\\\/i', $nilai) === 1) {
                continue;
            }

            $aman[] = $properti.': '.$nilai;
        }

        return implode('; ', $aman);
    }
}
