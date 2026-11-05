<?php

namespace App\Domain\Question\Enums;

/**
 * Keadaan satu berkas impor.
 *
 * ---------------------------------------------------------------------
 * Mengapa `dipratinjau` adalah keadaan tersendiri, bukan sekadar UI
 * ---------------------------------------------------------------------
 * Impor berjalan tiga langkah - unggah, pratinjau, konfirmasi - dan langkah
 * tengahnya menyimpan sesuatu yang nyata: hasil parse beserta nomor baris
 * setiap galatnya. Menyimpannya di kolom `pratinjau_json` berarti Guru yang
 * menekan Konfirmasi lima menit kemudian menyimpan hasil validasi yang SAMA
 * dengan yang ia lihat.
 *
 * Tanpa keadaan ini, "pratinjau" harus berarti "parse ulang berkasnya", dan
 * berkasnya bisa saja sudah dihapus, atau - lebih buruk - hasilnya berbeda
 * dari yang dilihat Guru karena sesuatu di antaranya berubah. Yang menyimpan
 * adalah kesamaan antara yang dilihat dan yang disimpan, bukan penguraiannya.
 *
 * ---------------------------------------------------------------------
 * `gagal` adalah keadaan berkas, bukan keadaan baris
 * ---------------------------------------------------------------------
 * Berkas dengan 12 baris rusak dari 50 TETAP `dikonfirmasi`, bukan `gagal`:
 * 38 barisnya tersimpan, dan laporan galatnya berisi 12 alamatnya. `gagal`
 * hanya untuk berkas yang tidak bisa dibaca sama sekali - bukan zip, tidak
 * ada sheet, atau melebihi batas baris. Mencampur keduanya berarti berkas
 * yang 76% berhasil dilaporkan sebagai kegagalan, dan Guru akan mengunggah
 * ulang dan menggandakan 38 soal yang sudah masuk.
 */
enum StatusImpor: string
{
    case Menunggu = 'menunggu';
    case Dipratinjau = 'dipratinjau';
    case Dikonfirmasi = 'dikonfirmasi';
    case Gagal = 'gagal';

    public function label(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu pratinjau',
            self::Dipratinjau => 'Menunggu konfirmasi',
            self::Dikonfirmasi => 'Selesai',
            self::Gagal => 'Gagal dibaca',
        };
    }

    /** Apakah berkas ini masih boleh dilanjutkan ke langkah berikutnya. */
    public function bolehDikonfirmasi(): bool
    {
        return $this === self::Dipratinjau;
    }

    /**
     * Daftar nilai/label untuk pemilih di laman daftar impor.
     *
     * @return array<int, array{nilai: string, label: string}>
     */
    public static function untukFilter(): array
    {
        return array_map(fn (self $s) => [
            'nilai' => $s->value,
            'label' => $s->label(),
        ], self::cases());
    }
}
