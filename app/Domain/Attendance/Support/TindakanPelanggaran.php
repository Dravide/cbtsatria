<?php

namespace App\Domain\Attendance\Support;

/**
 * Apa yang dilakukan sistem atas satu pelanggaran - blueprint `:505-600`.
 *
 * ---------------------------------------------------------------------
 * Mengapa objek, bukan boolean "kunci atau tidak"
 * ---------------------------------------------------------------------
 * Blueprint menuliskan tangga eskalasinya sebagai EMPAT tingkat dengan tiga
 * akibat yang berbeda: dicatat, diperingatkan, pengawas diberi tahu, lalu
 * ujian dikunci. Boolean akan memaksa pemanggilnya memilih salah satu, dan
 * yang hilang justru tingkat tengahnya - siswa yang sudah diperingatkan
 * tetapi belum dikunci, dan pengawas yang sudah ditandai tetapi belum
 * menindak.
 *
 * ---------------------------------------------------------------------
 * `eskalasi` dan `dicatat` bukan hal yang sama
 * ---------------------------------------------------------------------
 * Tingkat 0 (Halus) TIDAK dicatat sama sekali: klik kanan, salin, dan
 * pintasan alat pengembang diblokir diam-diam di peramban dan tidak pernah
 * menjadi baris `exam_violations`. Alasannya bukan belas kasihan: catatan
 * yang berisi tiga puluh kali "klik kanan" akan menenggelamkan satu baris
 * "berpindah aplikasi selama 40 detik" yang benar-benar perlu dibaca
 * pengawas.

 * Karena itu `dicatat` ada sebagai medan tersendiri, bukan disimpulkan dari
 * `eskalasi > 0`. Pemanggil yang menyimpulkan sendiri akan menulis baris
 * untuk tingkat 0 begitu syaratnya berubah sedikit.
 *
 * ---------------------------------------------------------------------
 * `sisaMenujuKunci` dikirim supaya peringatan bisa menyebut angkanya
 * ---------------------------------------------------------------------
 * Blueprint Tingkat 2 menuntut peringatan yang MENYEBUT jumlahnya: "3 dari 5
 * sebelum terkunci". Angka itu tidak bisa disusun klien dari `eskalasi`
 * sendirian - ia harus tahu ambang sekolahnya, dan mengirim ambang ke
 * peramban berarti mengirim aturan main yang bisa dibaca sebelum dipatuhi.
 * Yang dikirim cukup sisanya.
 */
final class TindakanPelanggaran
{
    private function __construct(
        public readonly bool $dicatat,
        public readonly bool $peringatkan,
        public readonly bool $tandaiPengawas,
        public readonly bool $kunci,
        /** 0 Halus / 1 Ringan / 2 Berulang / 3 Berat. */
        public readonly int $eskalasi,
        /** Pelanggaran tercatat lagi sebanyak ini sebelum ujian dikunci. */
        public readonly ?int $sisaMenujuKunci,
    ) {}

    /** Tingkat 0 - diblokir diam-diam, tidak pernah menjadi baris. */
    public static function halus(): self
    {
        return new self(false, false, false, false, 0, null);
    }

    public static function catat(bool $peringatkan, bool $tandai, bool $kunci, int $eskalasi, ?int $sisa): self
    {
        return new self(true, $peringatkan, $tandai, $kunci, $eskalasi, $sisa);
    }

    /**
     * Label tindakan untuk kolom "Tindakan" plate 29.
     *
     * Kosakatanya disalin dari wireframe, bukan dikarang: plate 29 menulis
     * "dicatat", "diperingatkan", dan "pengawas diberi tahu" apa adanya.
     */
    public function labelTindakan(): string
    {
        return match ($this->eskalasi) {
            3 => 'ujian dikunci',
            2 => 'pengawas diberi tahu',
            1 => 'diperingatkan',
            default => 'dicatat',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'eskalasi' => $this->eskalasi,
            'tindakan' => $this->labelTindakan(),
            'diperingatkan' => $this->peringatkan,
            'pengawas_ditandai' => $this->tandaiPengawas,
            'dikunci' => $this->kunci,
            'sisa_menuju_kunci' => $this->sisaMenujuKunci,
        ];
    }
}
