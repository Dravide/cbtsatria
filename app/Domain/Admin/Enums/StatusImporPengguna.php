<?php

namespace App\Domain\Admin\Enums;

/**
 * Keadaan satu berkas impor pengguna.
 *
 * =====================================================================
 * Mengapa enum baru, bukan `Question\Enums\StatusImpor`
 * =====================================================================
 * Nilainya identik, dan yang diduplikasi hanya enam baris berisi empat label.
 * Tetap saja enumnya baru, dan alasannya bukan kerapian:
 *
 * `DomainBoundariesTest` TIDAK memuat `Question` di daftar impor `Admin`, dan
 * impor langsung ke `Question\Enums\StatusImpor` memang pelanggaran batas.
 * Yang membuatnya menggoda adalah regex test itu hanya memindai pola
 * `App\Domain\([A-Za-z]+)\Models\` dan `…\Http\` - sehingga impor `Enums\`
 * lolos TANPA satu pun test berbunyi. Menyandarkan diri pada celah regex
 * sebuah test berarti menjadikan test itu alasan, bukan pengawas.
 *
 * `Question\Enums\StatusImpor` bukan kandidat untuk dinaikkan ke `App\Support`
 * juga: labelnya berbicara tentang soal ("Menunggu pratinjau" sebuah berkas
 * soal), dan dua importer yang berbeda kelak akan membutuhkan label yang
 * berbeda untuk keadaan yang sama.
 *
 * =====================================================================
 * `gagal` adalah keadaan BERKAS, bukan keadaan baris
 * =====================================================================
 * Berkas berisi 40 baris dengan 3 baris rusak TETAP `dikonfirmasi`: 37
 * akunnya tersimpan, dan laporan galatnya memuat 3 alamatnya. `gagal` hanya
 * untuk berkas yang tidak bisa dibaca sama sekali - bukan zip, tanpa lembar,
 * tanpa kolom wajib, melebihi batas baris.
 *
 * Yang membuat pemisahan ini lebih penting di sini daripada di impor soal:
 * pada mode "perbarui akun lama", berkas yang 92% berhasil dilaporkan sebagai
 * kegagalan akan diunggah ulang - dan unggahan kedua itu menulis 37 akun yang
 * sama untuk kedua kalinya.
 */
enum StatusImporPengguna: string
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
     * Daftar nilai/label untuk pemilih di laman riwayat impor.
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
