<?php

namespace App\Support\Impor;

/**
 * Pembaca CSV tanpa dependensi.
 *
 * =====================================================================
 * Tiga hal yang membedakan CSV yang benar-benar dipakai dari CSV yang ideal
 * =====================================================================
 * Spesifikasi CSV hanya punya satu aturan tentang pemisah: "koma". Berkas
 * yang benar-benar datang dari sekolah tidak begitu, dan ketiganya diurus di
 * sini:
 *
 *   1. TITIK KOMA. Excel berbahasa Indonesia memakai `;` sebagai pemisah
 *      daftar, dan EKSPORNYA mengikuti setelan itu. Berkas yang dibuka di
 *      Excel lalu disimpan sebagai CSV akan bertitik koma, dan pembaca yang
 *      hanya menerima koma melihat seluruh barisnya sebagai SATU kolom -
 *      lalu melaporkan "kolom kunci tidak ditemukan" untuk berkas yang
 *      sebenarnya sempurna.
 *
 *   2. BOM. Excel menuliskan tiga byte `EF BB BF` di awal berkas UTF-8. Tanpa
 *      dibuang, nama kolom pertama terbaca sebagai "\u{FEFF}kode" - dan
 *      pemeriksaan nama kolom gagal pada kolom PERTAMA saja, yang gejalanya
 *      adalah "kode" hilang sementara "judul" dan "kunci" ditemukan.
 *
 *   3. SEL BERPENGAPIT. Isi sel memuat koma, titik sisa, dan baris baru -
 *      dan semuanya sah selama diapit tanda kutip. Membaca baris-per-baris
 *      lalu memecahnya dengan `explode` merusak sel yang di dalamnya ada
 *      koma, dan kerusakannya senyap: kolomnya bertambah, dan yang terbaca
 *      sebagai "kunci" adalah potongan kalimat.
 *
 * Pemecahnya karena itu bukan `explode`, melainkan `str_getcsv` dengan
 * pemisah yang sudah ditentukan.
 */
class PembacaCsv implements PembacaBerkas
{
    public function dukung(string $ekstensi): bool
    {
        return in_array(strtolower($ekstensi), ['csv', 'txt'], true);
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

        $isi = @file_get_contents($jalur);

        if ($isi === false) {
            throw new BerkasImportTidakTerbaca('Berkasnya tidak bisa dibuka. Unggah ulang.');
        }

        $isi = $this->buangBom($isi);
        $pemisah = $this->tebakPemisah($isi);

        $baris = [];
        $no = 0;

        $pegangan = fopen('php://temp', 'r+');
        fwrite($pegangan, $isi);
        rewind($pegangan);

        while (($kolom = fgetcsv($pegangan, 0, $pemisah, '"', '\\')) !== false) {
            $no++;

            // Baris kosong TETAP disimpan supaya nomor barisnya sama dengan
            // yang dilihat Guru di Excel - lihat catatan di PembacaBerkas.
            if ($kolom === [null] || $kolom === []) {
                $baris[$no] = [];

                continue;
            }

            if ($no > self::BARIS_MAKS) {
                fclose($pegangan);

                throw BerkasImportTidakTerbaca::terlaluBanyakBaris($no);
            }

            $baris[$no] = array_map(fn ($sel) => trim((string) $sel), $kolom);
        }

        fclose($pegangan);

        if ($baris === []) {
            throw BerkasImportTidakTerbaca::kosong();
        }

        return $baris;
    }

    /**
     * Buang BOM UTF-8 di awal berkas.
     *
     * Dikerjakan pada ISINYA, bukan pada baris pertamanya setelah dibaca:
     * `fgetcsv` akan membawa BOM itu ke dalam nama kolom pertama, dan
     * pemeriksaan nama kolom lalu gagal pada satu kolom saja.
     */
    private function buangBom(string $isi): string
    {
        if (str_starts_with($isi, "\xEF\xBB\xBF")) {
            return substr($isi, 3);
        }

        return $isi;
    }

    /**
     * Tebak pemisahnya dari baris pertama.
     *
     * Dihitung di LUAR tanda kutip, dan itu yang membuatnya benar: sel yang
     * di dalamnya memuat koma - dan hampir semuanya memuat - akan membuat
     * penghitungan naif memilih koma sebagai pemisah pada berkas bertitik
     * koma, lalu memecah satu sel menjadi tiga.
     */
    private function tebakPemisah(string $isi): string
    {
        $barisPertama = strtok($isi, "\r\n");

        if ($barisPertama === false || trim($barisPertama) === '') {
            throw BerkasImportTidakTerbaca::csvTanpaPemisah('');
        }

        $koma = 0;
        $titikKoma = 0;
        $tab = 0;
        $dalamKutip = false;
        $panjang = strlen($barisPertama);

        for ($i = 0; $i < $panjang; $i++) {
            $c = $barisPertama[$i];

            if ($c === '"') {
                $dalamKutip = ! $dalamKutip;

                continue;
            }

            if ($dalamKutip) {
                continue;
            }

            match ($c) {
                ',' => $koma++,
                ';' => $titikKoma++,
                "\t" => $tab++,
                default => null,
            };
        }

        // Tab didahulukan karena ia satu-satunya yang tidak pernah muncul
        // sebagai tanda baca di dalam teks berbahasa Indonesia.
        if ($tab > 0) {
            return "\t";
        }

        if ($titikKoma > 0 && $titikKoma >= $koma) {
            return ';';
        }

        if ($koma > 0) {
            return ',';
        }

        throw BerkasImportTidakTerbaca::csvTanpaPemisah(mb_substr($barisPertama, 0, 60));
    }
}
