<?php

namespace App\Domain\Exam\Enums;

/**
 * Status token, DIHITUNG dan bukan disimpan.
 *
 * ---------------------------------------------------------------------
 * Mengapa turunan, bukan kolom
 * ---------------------------------------------------------------------
 * Wireframe plate 18 menampilkan lima chip status: `aktif`, `dibuka`,
 * `draft`, dan - pada keadaan yang tidak terlihat di contoh plate -
 * `kedaluwarsa` dan `dicabut`. Lima keadaan itu terlihat seperti lima nilai
 * yang perlu disimpan, dan justru itu jebakannya.
 *
 * Tiga di antaranya bukan fakta tentang tokennya, melainkan tentang WAKTU
 * dan RIWAYAT saat ia dibaca:
 *
 *   kedaluwarsa  <- now() > valid_until
 *   dibuka       <- ada baris di exam_token_views
 *   draft        <- belum ada baris exam_tokens sama sekali
 *
 * Menyimpannya sebagai kolom berarti kolom yang harus diperbarui oleh
 * sesuatu - dan sesuatu itu adalah penjadwal yang belum tentu berjalan, di
 * server yang belum tentu hidup pada jam yang tepat. Token yang seharusnya
 * kedaluwarsa pukul 07:45 akan tetap terbaca "aktif" sampai ada yang
 * memperbaruinya, dan yang membaca status itu adalah pengawas di depan
 * kelas yang menunggu siswanya bisa masuk.
 *
 * Karena itu statusnya diturunkan saat dibaca. Satu-satunya yang benar-benar
 * disimpan adalah `revoked_at`, karena pencabutan adalah tindakan orang,
 * bukan lewatnya waktu.
 */
enum StatusToken: string
{
    case Draft = 'draft';
    case Aktif = 'aktif';
    case Dibuka = 'dibuka';
    case Kedaluwarsa = 'kedaluwarsa';
    case Dicabut = 'dicabut';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::Aktif => 'aktif',
            self::Dibuka => 'dibuka',
            self::Kedaluwarsa => 'kedaluwarsa',
            self::Dicabut => 'dicabut',
        };
    }

    /**
     * Nada chip di UI.
     *
     * `draft` memakai nada netral, bukan peringatan: token yang belum
     * diterbitkan bukan kesalahan, itu keadaan normal sesi yang baru disusun.
     * Memberinya warna peringatan berarti setiap sesi baru terlihat
     * bermasalah.
     */
    public function nada(): string
    {
        return match ($this) {
            self::Aktif => 'ok',
            self::Dibuka => 'info',
            self::Kedaluwarsa, self::Dicabut => 'warn',
            self::Draft => 'mute',
        };
    }

    /**
     * Apakah token dalam keadaan ini masih bisa dipakai siswa.
     *
     * Hanya `aktif` dan `dibuka`. `dibuka` tetap bisa dipakai - pengawas
     * sudah membacanya, dan justru itu tujuannya.
     */
    public function berlaku(): bool
    {
        return $this === self::Aktif || $this === self::Dibuka;
    }
}
