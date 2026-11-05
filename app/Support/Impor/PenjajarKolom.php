<?php

namespace App\Support\Impor;

/**
 * Penjajar nama kolom berkas impor dengan indeksnya.
 *
 * Kelas ini adalah ALGORITMANYA, bukan daftar kolomnya. Daftar kolomnya
 * diwarisi: `PenjajarTemplate` (impor soal) dan `PenjajarPengguna` (impor
 * pengguna) masing-masing hanya mendeklarasikan tiga konstanta, dan seluruh
 * perilakunya tinggal di sini.
 *
 * =====================================================================
 * Mengapa diwarisi, bukan disalin
 * =====================================================================
 * Impor pengguna butuh aturan yang sama persis: nama kolom dicari, bukan
 * dihitung urutannya; BOM dibuang; sinonim diterjemahkan; kolom yang tidak ada
 * mengembalikan string kosong. Menyalin ~120 baris itu ke kelas kedua berarti
 * dua tempat yang bisa berbeda diam-diam pada hal yang paling menentukan -
 * kolom mana yang benar-benar dibaca importer. Satu definisi, dua daftar kolom.
 *
 * Konstanta anaknya dibaca lewat `static::`, dan itu yang membuat kelas ini
 * tetap bekerja untuk keduanya tanpa satu pun cabang `if`.
 *
 * =====================================================================
 * Mengapa indeks kolom dicari lewat NAMA, bukan lewat urutannya
 * =====================================================================
 * Cara termudah adalah menetapkan "kolom A adalah kode, B adalah judul, C
 * adalah jenis" dan membaca indeksnya langsung. Cara itu bertahan sampai Guru
 * pertama menambahkan satu kolom di tengah berkasnya - dan sejak saat itu
 * setiap kolom sesudahnya terbaca sebagai kolom yang salah.
 *
 * Kerusakannya lebih buruk daripada galat: kunci jawaban masuk ke kolom bobot,
 * bobotnya bukan angka, dan yang dilaporkan adalah "bobot baris 3 tidak sah"
 * untuk berkas yang bobotnya benar sejak awal.
 *
 * Karena itu namanya yang dicari, dan berkasnya boleh mengurutkan kolomnya
 * sesukanya. Yang dituntut hanyalah NAMANYA ada.
 *
 * =====================================================================
 * Pesan galatnya menyebut NOMOR BARIS dan NAMA KOLOM
 * =====================================================================
 * Kriteria selesai blueprint `:1225` menuntut "setidaknya satu galat yang
 * dilaporkan tepat barisnya", dan yang membuatnya berguna adalah keduanya:
 * nomor baris supaya Guru tahu HARUS MENCARI DI MANA, nama kolom supaya ia
 * tahu APA YANG HARUS DIPERBAIKI. Pesan "berkas tidak sah" memenuhi syarat
 * secara teknis dan tidak menolong siapa pun.
 *
 * =====================================================================
 * Nama kolom dinormalkan, dan itu yang membuatnya ramah
 * =====================================================================
 * "Kode", "KODE", " kode ", dan "kode soal" semuanya mengarah ke kolom yang
 * sama. Orang yang mengetik ulang judul kolomnya dengan huruf besar tidak
 * boleh mendapat laporan bahwa kolomnya hilang - dan itu terjadi pada
 * percobaan pertama setiap orang yang menyusun berkasnya sendiri.
 */
abstract class PenjajarKolom
{
    /**
     * Berapa baris awal yang dicoba sebagai judul kolom.
     *
     * Cukup untuk berkas yang punya baris judul laporan di atasnya, dan cukup
     * pendek untuk tidak menelusuri 2.000 baris berkas yang memang bukan
     * berkas data.
     */
    final public const BARIS_JUDUL_MAKS = 5;

    /**
     * Nama kanonik kolom, dalam urutan yang dipakai template unduhan.
     *
     * Urutannya menentukan bentuk template dan pesan galat "kolom wajib belum
     * ada", bukan cara pembacaannya - lihat catatan di kepala berkas ini.
     *
     * @var array<int, string>
     */
    public const KOLOM = [];

    /**
     * Kolom yang HARUS ada di berkasnya.
     *
     * Daftarnya sengaja pendek di setiap importer: yang menolak kekurangan
     * isi adalah pemeriksaan PER BARIS, bukan pemeriksaan tingkat berkas yang
     * akan menolak seluruh berkas karena satu baris.
     *
     * @var array<int, string>
     */
    public const WAJIB = [];

    /**
     * Sinonim nama kolom, supaya orang tidak perlu menghafal template.
     *
     * @var array<string, string>
     */
    protected const SINONIM = [];

    /** @var array<string, int> */
    private array $peta = [];

    /** Nomor baris judul kolom di berkasnya. */
    private int $barisJudul = 1;

    /** Kolom wajib yang tidak ditemukan. @var array<int, string> */
    private array $hilang = [];

    /**
     * @param  array<int, string>  $barisPertama
     */
    public function __construct(array $barisPertama, int $nomorBaris = 1)
    {
        $this->barisJudul = $nomorBaris;

        foreach ($barisPertama as $indeks => $nama) {
            $kunci = $this->normalkan((string) $nama);

            if ($kunci === '') {
                continue;
            }

            // Kolom pertama yang menang bila namanya muncul dua kali. Yang
            // kedua diabaikan diam-diam, bukan dilaporkan: berkas dengan judul
            // "kode" dua kali hampir selalu berarti satu kolom lama yang lupa
            // dihapus, dan menolak berkasnya karena itu berlebihan.
            $this->peta[$kunci] ??= (int) $indeks;
        }

        foreach (static::WAJIB as $wajib) {
            if (! array_key_exists($wajib, $this->peta)) {
                $this->hilang[] = $wajib;
            }
        }
    }

    /**
     * Apakah berkasnya punya seluruh kolom wajib.
     *
     * Diperiksa SEKALI untuk seluruh berkas, bukan per baris: kolom yang tidak
     * ada tidak akan muncul di baris mana pun, dan melaporkannya 50 kali
     * membuat satu masalah terlihat seperti lima puluh.
     */
    public function sah(): bool
    {
        return $this->hilang === [];
    }

    /** Pesan galat berkas tanpa kolom wajib. */
    public function pesanKolomHilang(): string
    {
        return 'Berkasnya belum memuat kolom wajib: '.implode(', ', $this->hilang).
            '. Kolom yang terbaca: '.implode(', ', array_keys($this->peta)).
            '. Unduh template impor, lalu isi mengikuti bentuknya.';
    }

    /** Nomor baris judul kolomnya - dipakai laporan untuk menyebut sebabnya. */
    public function barisJudul(): int
    {
        return $this->barisJudul;
    }

    /**
     * Isi satu sel, dipangkas.
     *
     * Kolom yang tidak ada di berkasnya mengembalikan string kosong, bukan
     * galat - dan itu yang membuat kolom opsional benar-benar opsional.
     *
     * @param  array<int, string>  $baris
     */
    public function ambil(array $baris, string $kolom): string
    {
        $indeks = $this->peta[$kolom] ?? null;

        if ($indeks === null) {
            return '';
        }

        return trim((string) ($baris[$indeks] ?? ''));
    }

    /**
     * Nama kolom yang benar-benar terbaca dari berkasnya.
     *
     * Ditampilkan di laman pratinjau, dan gunanya satu: orang yang berkasnya
     * ditolak karena kolom wajib hilang perlu melihat APA YANG TERBACA.
     * Pesan yang hanya menyebut kolom yang hilang meninggalkan pertanyaan
     * yang tidak bisa ia jawab sendiri - apakah namanya salah tulis, atau
     * kolomnya memang tidak ada.
     *
     * @return array<int, string>
     */
    public function namaKolomTerbaca(): array
    {
        return array_keys($this->peta);
    }

    /* ---------------------------------------------------------- Statis */

    /**
     * Judul kolom untuk template unduhan.
     *
     * @return array<int, string>
     */
    public static function judul(): array
    {
        return static::KOLOM;
    }

    /**
     * Baris judul kolom: baris tidak kosong yang PERTAMA yang MEMUAT kolom
     * wajib.
     *
     * Bukan selalu baris 1. Berkas yang disiapkan dari laporan lain sering
     * punya satu baris judul berkas di atasnya, dan memaksa orang menghapusnya
     * berarti menolak berkas yang isinya benar.
     *
     * -----------------------------------------------------------------
     * Diuji dengan penjajarnya, bukan dengan "baris tidak kosong"
     * -----------------------------------------------------------------
     * Versi pertama method ini mengambil baris tidak kosong yang pertama, dan
     * itu gagal tepat pada kasus yang docblock-nya janjikan: berkas berjudul
     * "Laporan bank soal" di baris 1 membuat baris 1 itu sendiri yang
     * dianggap judul kolom, sehingga berkasnya ditolak dengan "kolom wajib
     * judul, jenis, soal belum ada" - untuk berkas yang isinya sempurna.
     *
     * Yang benar adalah mencoba setiap baris awal sampai ada yang benar-benar
     * memuat kolom wajibnya. Batasnya lima baris: berkas yang judul kolomnya
     * di baris kesepuluh bukan berkas data, dan menelusuri 2.000 baris sambil
     * membangun penjajar di setiap barisnya adalah pencarian yang mahal untuk
     * berkas yang tetap akan ditolak.
     *
     * @param  array<int, array<int, string>>  $baris
     * @param  class-string<PenjajarKolom>  $kelasPenjajar  Penjajar yang dipakai
     *         untuk menguji setiap baris awal. Diberikan EKSPLISIT, bukan
     *         diambil lewat `static::class`: method ini statis, dan `static`
     *         di dalamnya menunjuk ke kelas yang dipanggil - yaitu kelas
     *         abstrak ini sendiri, sehingga `new static` melempar
     *         "Cannot instantiate abstract class" pada setiap berkas.
     * @return array{0: int, 1: array<int, string>}|null
     */
    public static function temukan(array $baris, string $kelasPenjajar): ?array
    {
        $dicoba = 0;

        foreach ($baris as $nomor => $sel) {
            if (self::kosong($sel)) {
                continue;
            }

            if ((new $kelasPenjajar($sel, (int) $nomor))->sah()) {
                return [(int) $nomor, $sel];
            }

            if (++$dicoba >= self::BARIS_JUDUL_MAKS) {
                break;
            }
        }

        // Tidak ada baris yang memuat seluruh kolom wajib. Baris tidak kosong
        // yang PERTAMA dikembalikan supaya pesan "kolom yang terbaca"
        // menyebutkan yang benar-benar dibaca orang di kepalanya, bukan baris
        // judul berkas di atasnya.
        foreach ($baris as $nomor => $sel) {
            if (! self::kosong($sel)) {
                return [(int) $nomor, $sel];
            }
        }

        return null;
    }

    /** @param array<int, string>|null $sel */
    public static function kosong(?array $sel): bool
    {
        if ($sel === null || $sel === []) {
            return true;
        }

        foreach ($sel as $isi) {
            if (trim((string) $isi) !== '') {
                return false;
            }
        }

        return true;
    }

    /* ---------------------------------------------------------- Pembantu */

    /**
     * Normalkan nama kolom: huruf kecil, tanpa spasi/garis bawah/tanda baca,
     * tanpa BOM, lalu terjemahkan sinonimnya.
     *
     * BOM dibuang di sini juga, meski PembacaCsv sudah membuangnya dari isi
     * berkasnya: berkas XLSX yang dibuat alat ekspor tertentu membawa BOM di
     * dalam judul kolom pertamanya, dan gejalanya sama persis - kolom PERTAMA
     * hilang sementara sisanya ditemukan.
     */
    private function normalkan(string $nama): string
    {
        $bersih = str_replace(["\xEF\xBB\xBF", "\u{FEFF}"], '', $nama);

        $bersih = preg_replace('/[^\p{L}\p{N}]+/u', '_', $bersih) ?? $bersih;

        $bersih = strtolower(trim($bersih, '_'));

        // Kolom opsi yang ditulis "opsi a" menjadi "opsi_a" oleh pembersihan di
        // atas; yang tetap perlu diterjemahkan adalah sinonim satu kata.
        return static::SINONIM[$bersih] ?? $bersih;
    }
}
