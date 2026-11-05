<?php

namespace App\Support\Impor;

use SimpleXMLElement;
use ZipArchive;

/**
 * Pembaca XLSX tanpa dependensi - ZipArchive + SimpleXML.
 *
 * =====================================================================
 * Yang dibaca, dan yang sengaja TIDAK dibaca
 * =====================================================================
 * Berkas .xlsx adalah arsip zip berisi XML. Yang dibutuhkan dari dalamnya
 * hanya dua:
 *
 *   xl/worksheets/sheet1.xml   - selnya, sebagai rujukan `r="A1"` + nilai
 *   xl/sharedStrings.xml       - tabel teks bersama, tempat sebagian besar
 *                                isi sel sebenarnya disimpan
 *
 * Yang TIDAK dibaca: `xl/styles.xml` (warna, huruf), `xl/charts/`, gambar,
 * komentar, dan seluruh mesin rumus. Formatnya tidak hilang karena memang
 * tidak pernah masuk: yang dipindahkan adalah TEKS di dalam sel, dan kolom
 * yang dibutuhkan penjajar kolom semuanya teks.
 *
 * =====================================================================
 * Sel bersama: satu tabel yang wajib, dan gejalanya bila terlewat
 * =====================================================================
 * Excel tidak menulis teks ke dalam sel. Ia menulis ANGKA ke dalam selnya, dan
 * angka itu adalah indeks ke `sharedStrings.xml`:
 *
 *   <c r="A2" t="s"><v>0</v></c>      <- teks ke-0 dari tabel bersama
 *   <c r="B2"><v>42</v></c>           <- angka, tanpa atribut t
 *
 * Pembaca yang melewatkan tabel itu akan menghasilkan isi yang terbaca "0",
 * "1", "2" - dan itu tampak seperti berkas yang rusak, bukan seperti pembaca
 * yang salah. Kerusakannya justru lebih menipu daripada galat.
 *
 * =====================================================================
 * Rujukan sel diurai, bukan dihitung posisinya
 * =====================================================================
 * Sel yang isinya sama dengan sel sebelumnya HILANG dari XML-nya: Excel
 * menulisnya sebagai `<c r="D5">` tanpa `<v>`, dan yang berikutnya bisa
 * melompat dari kolom B ke kolom F. Pembaca yang mengandalkan urutan akan
 * menggeser seluruh kolom setelah satu sel kosong - dan gejalanya adalah
 * "kolom kunci terbaca sebagai kolom bobot", yang menunjuk ke tempat yang
 * salah sama sekali.
 *
 * Karena itu `r="D5"` diurai menjadi (baris 5, kolom 4), dan sel yang kosong
 * menjadi lubang yang dibiarkan kosong.
 */
class PembacaXlsx implements PembacaBerkas
{
    public function dukung(string $ekstensi): bool
    {
        return in_array(strtolower($ekstensi), ['xlsx', 'xlsm'], true);
    }

    public function baca(string $jalur): array
    {
        $ukuran = @filesize($jalur);

        if ($ukuran === false) {
            throw new BerkasImportTidakTerbaca('Berkasnya tidak ditemukan di server. Unggah ulang.');
        }

        if ($ukuran === 0) {
            throw BerkasImportTidakTerbaca::kosong();
        }

        if ($ukuran > self::BYTES_MAKS) {
            throw BerkasImportTidakTerbaca::terlaluBesar($ukuran);
        }

        $zip = new ZipArchive;

        // Bukan zip = bukan xlsx. Berkas .xls lama (OLE2) masuk ke sini, dan
        // pesan yang membedakannya dari "zip rusak" ditulis di galatnya -
        // sebab guru yang menyimpan berkasnya sebagai .xls akan mengulangi
        // langkah yang sama bila pesannya tidak menyebutkan sebabnya.
        if ($zip->open($jalur) !== true) {
            throw BerkasImportTidakTerbaca::bukanZip();
        }

        try {
            $lembar = $this->lembarPertama($zip);

            if ($lembar === null) {
                throw BerkasImportTidakTerbaca::tanpaLembar();
            }

            $teks = $this->tabelTeksBersama($zip);
            $xml = $zip->getFromName($lembar);

            if ($xml === false) {
                throw BerkasImportTidakTerbaca::tanpaLembar();
            }

            return $this->urai($xml, $teks);
        } finally {
            $zip->close();
        }
    }

    /* ---------------------------------------------------------- Mekanisme */

    /**
     * Nama berkas lembar PERTAMA, menurut urutan di workbook.xml.
     *
     * Bukan `sheet1.xml` yang ditulis langsung: Excel menomori berkasnya
     * menurut urutan pembuatannya, dan lembar yang dipindah ke depan akan
     * menjadi `sheet2.xml`. Guru yang menyeret tabnya ke depan tidak boleh
     * mendapat lembar yang salah, dan satu-satunya sumber urutan yang benar
     * adalah `workbook.xml` + relasinya.
     */
    private function lembarPertama(ZipArchive $zip): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relasi = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false) {
            // Sebagian penulis (alat ekspor sederhana) tidak menulis
            // workbook.xml. Kalau begitu berkasnya, `sheet1.xml` adalah
            // tebakan yang benar.
            return $zip->locateName('xl/worksheets/sheet1.xml') !== false
                ? 'xl/worksheets/sheet1.xml'
                : null;
        }

        $rId = null;

        $wb = $this->xml($workbook);

        if ($wb !== null) {
            foreach ($wb->sheets->sheet ?? [] as $sheet) {
                $id = (string) ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');

                if ($id !== '') {
                    $rId = $id;
                    break;
                }
            }
        }

        if ($rId === null || $relasi === false) {
            return $zip->locateName('xl/worksheets/sheet1.xml') !== false
                ? 'xl/worksheets/sheet1.xml'
                : null;
        }

        $rel = $this->xml($relasi);

        if ($rel !== null) {
            foreach ($rel->Relationship ?? [] as $satu) {
                if ((string) $satu['Id'] !== $rId) {
                    continue;
                }

                $target = ltrim((string) $satu['Target'], '/');

                // Target bisa relatif terhadap xl/ (`worksheets/sheet1.xml`)
                // atau absolut dari akar arsip (`/xl/worksheets/sheet1.xml`).
                $jalur = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;

                return $zip->locateName($jalur) !== false ? $jalur : null;
            }
        }

        return null;
    }

    /**
     * Tabel teks bersama, diindeks menurut posisinya.
     *
     * Teks di dalamnya dipecah menjadi potongan (`<r><t>`), dan potongan yang
     * berbeda FORMAT hurufnya ditulis terpisah: "**Ja**karta" adalah dua
     * potongan. Menggabungkannya wajib - membacanya satu per satu berarti isi
     * sel yang kalimatnya terpotong di tengah.
     *
     * @return array<int, string>
     */
    private function tabelTeksBersama(ZipArchive $zip): array
    {
        $isi = $zip->getFromName('xl/sharedStrings.xml');

        if ($isi === false) {
            // Berkas yang seluruh selnya angka atau rumus tidak punya tabel
            // ini sama sekali, dan itu berkas yang sah.
            return [];
        }

        $xml = $this->xml($isi);

        if ($xml === null) {
            return [];
        }

        $hasil = [];
        $i = 0;

        foreach ($xml->si ?? [] as $si) {
            $hasil[$i] = $this->teksDari($si);
            $i++;
        }

        return $hasil;
    }

    /**
     * Teks satu entri tabel bersama, termasuk yang terpecah menjadi potongan.
     */
    private function teksDari(SimpleXMLElement $si): string
    {
        // Kasus sederhana: <si><t>teks</t></si>
        if (isset($si->t)) {
            return (string) $si->t;
        }

        // Kasus berformat: <si><r><rPr/><t>Ja</t></r><r><t>karta</t></r></si>
        $teks = '';

        foreach ($si->r ?? [] as $potongan) {
            $teks .= (string) $potongan->t;
        }

        return $teks;
    }

    /**
     * Urai lembar kerja menjadi baris-baris teks.
     *
     * @param  array<int, string>  $teks
     * @return array<int, array<int, string>>
     */
    private function urai(string $xml, array $teks): array
    {
        $dok = $this->xml($xml);

        if ($dok === null) {
            throw BerkasImportTidakTerbaca::tanpaLembar();
        }

        $baris = [];

        foreach ($dok->sheetData->row ?? [] as $row) {
            $noBaris = (int) $row['r'];

            if ($noBaris === 0) {
                // Sebagian penulis menghilangkan atribut r. Nomor dihitung
                // dari urutannya, dan itu tebakan yang benar untuk berkas
                // yang seluruh barisnya ber-atribut.
                $noBaris = count($baris) === 0 ? 1 : max(array_keys($baris)) + 1;
            }

            if ($noBaris > self::BARIS_MAKS) {
                throw BerkasImportTidakTerbaca::terlaluBanyakBaris($noBaris);
            }

            $kolom = [];

            foreach ($row->c ?? [] as $c) {
                $rujukan = (string) $c['r'];
                $indeks = $rujukan === '' ? count($kolom) : $this->kolomDari($rujukan);

                $kolom[$indeks] = $this->nilaiSel($c, $teks);
            }

            // Kolom dilubangi sampai indeks tertinggi, supaya `kunci` yang ada
            // di kolom D tetap berada di indeks 3 meski kolom B dan C kosong -
            // tanpa ini, penjajar kolom membaca kolom yang salah dan
            // galatnya menunjuk kolom yang tidak berhubungan.
            if ($kolom !== []) {
                $maks = max(array_keys($kolom));
                $lengkap = [];

                for ($i = 0; $i <= $maks; $i++) {
                    $lengkap[$i] = $kolom[$i] ?? '';
                }

                $baris[$noBaris] = $lengkap;
            } else {
                // Baris kosong TETAP disimpan sebagai baris kosong: nomor
                // barisnya harus tetap sama dengan yang dilihat Guru di Excel.
                $baris[$noBaris] = [];
            }
        }

        ksort($baris);

        return $baris;
    }

    /**
     * Nilai satu sel, sebagai teks.
     *
     * @param  array<int, string>  $teks
     */
    private function nilaiSel(SimpleXMLElement $c, array $teks): string
    {
        $tipe = (string) $c['t'];

        // t="s" -> nilai numeriknya adalah indeks ke tabel teks bersama.
        if ($tipe === 's') {
            $indeks = (int) $c->v;

            return $teks[$indeks] ?? '';
        }

        // t="inlineStr" -> teksnya ditulis langsung di dalam selnya. Sebagian
        // alat ekspor memakai bentuk ini alih-alih tabel bersama.
        if ($tipe === 'inlineStr') {
            return $this->teksDari($c->is);
        }

        // t="str" -> hasil rumus yang berupa teks.
        if ($tipe === 'str') {
            return trim((string) $c->v);
        }

        // t="b" -> boolean, ditulis 0 atau 1.
        if ($tipe === 'b') {
            return (string) $c->v === '1' ? 'BENAR' : 'SALAH';
        }

        // Tanpa t, atau t="n": angka. Ditulis apa adanya, dan itu WAJIB -
        // kolom bobot berisi "2", dan membiarkan Excel menuliskannya sebagai
        // "2.0" membuat penjajar kolom menolaknya sebagai bukan angka.
        return trim((string) $c->v);
    }

    /**
     * `D5` -> 3 (nol-based). `AA10` -> 26.
     */
    private function kolomDari(string $rujukan): int
    {
        $huruf = '';
        $panjang = strlen($rujukan);

        for ($i = 0; $i < $panjang; $i++) {
            $c = $rujukan[$i];

            if ($c >= 'A' && $c <= 'Z') {
                $huruf .= $c;

                continue;
            }

            break;
        }

        if ($huruf === '') {
            return 0;
        }

        $angka = 0;

        foreach (str_split($huruf) as $c) {
            $angka = $angka * 26 + (ord($c) - 64);
        }

        return $angka - 1;
    }

    /**
     * SimpleXML dengan pesan libxml dimatikan.
     *
     * Sama seperti PembersihHtml: XML dari Excel sering memuat rujukan
     * namespace yang tidak bisa diselesaikan tanpa jaringan, dan peringatan itu
     * benar tetapi tidak berguna - yang diinginkan adalah node yang bisa
     * dibaca, bukan log yang penuh.
     */
    private function xml(string $isi): ?SimpleXMLElement
    {
        if (trim($isi) === '') {
            return null;
        }

        $sebelum = libxml_use_internal_errors(true);
        $dok = simplexml_load_string($isi, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($sebelum);

        return $dok === false ? null : $dok;
    }
}
