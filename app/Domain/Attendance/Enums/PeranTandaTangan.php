<?php

namespace App\Domain\Attendance\Enums;

/**
 * Peran penanda tangan berita acara - PRD §12.3, blueprint :422.
 *
 * ---------------------------------------------------------------------
 * Mengapa peran, bukan orang
 * ---------------------------------------------------------------------
 * "Tanda tangan melekat pada peran dan sesi, bukan pada orang semata, supaya
 * pergantian pengawas tidak memindahkan tanda tangan lama."
 *
 * Perbedaan itu punya akibat yang bisa dilihat: pengawas yang bertugas di
 * S-2 menandatangani berita acara S-2, dan walaupun ia juga bertugas di S-3,
 * tanda tangan S-2 tetap milik S-2. Yang membuat itu benar adalah kunci
 * uniknya `(school_id, session_report_id, peran)` - barisnya menunjuk SESI,
 * bukan menunjuk orang.
 *
 * Karena itu tepat dua peran, dan keduanya bukan pilihan gaya: `pengawas`
 * yang menandatangani adalah syarat pengiriman final (blueprint plate 18),
 * sementara `panitia` disediakan karena berita acara sekolah memang
 * ditandatangani dua pihak - dan menyediakan perannya sekarang lebih murah
 * daripada menambah kolomnya kelak.
 *
 * Sejak 2026-11-12 lamannya hanya menawarkan `pengawas`: panitia
 * menandatangani di luar aplikasi, dan kotak tanda tangan yang tidak pernah
 * dituntut hanya menambah satu kotak kosong di ponsel pengawas. `panitia`
 * tetap ada sebagai case karena baris lama bisa memakainya; controller
 * menolak tanda tangan bertanda `panitia` dengan kalimat.
 */
enum PeranTandaTangan: string
{
    case Pengawas = 'pengawas';
    case Panitia = 'panitia';

    public function label(): string
    {
        return match ($this) {
            self::Pengawas => 'Pengawas ruang',
            self::Panitia => 'Panitia',
        };
    }

    /**
     * Apakah peran ini wajib ada sebelum laporan boleh dikirim final.
     *
     * Hanya `pengawas`. Panitia menandatangani di luar aplikasi pada
     * kenyataannya, dan menuntutnya di sini berarti berita acara tidak
     * pernah bisa dikirim pada ujian yang panitianya tidak sempat membuka
     * laman.
     */
    public function wajib(): bool
    {
        return $this === self::Pengawas;
    }
}
