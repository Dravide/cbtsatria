<?php

namespace App\Domain\Attendance\Enums;

/**
 * Sisi foto berita acara - PRD §12.2.
 *
 * ---------------------------------------------------------------------
 * Dua sisi di skema, SATU yang ditawarkan lamannya
 * ---------------------------------------------------------------------
 * Sejak 2026-11-12 laman berita acara hanya meminta satu foto: keadaan ruang
 * sesudah ujian. Ia sisi yang sama dengan syarat pengiriman final, sehingga
 * laman yang hanya menawarkan satu foto tetap bisa menghasilkan laporan
 * lengkap.
 *
 * `sebelum` TETAP ada di enum - bukan karena lamannya menawarkannya, tetapi
 * karena baris lama di `session_report_files` bisa saja memakainya, dan
 * menghapus case-nya akan membuat baris itu gagal di-cast saat dibaca.
 * Controller menolak unggahan bertanda `sebelum` dengan kalimat, bukan dengan
 * galat 500.
 */
enum SisiFoto: string
{
    case Sebelum = 'sebelum';
    case Sesudah = 'sesudah';

    public function label(): string
    {
        return match ($this) {
            self::Sebelum => 'Sebelum ujian',
            self::Sesudah => 'Sesudah ujian',
        };
    }

    /** Keterangan yang dirender di bawah kotak fotonya. */
    public function petunjuk(): string
    {
        return match ($this) {
            self::Sebelum => 'Ambil sebelum peserta masuk: ruang kosong, meja bersih.',
            self::Sesudah => 'Ambil setelah seluruh peserta keluar.',
        };
    }
}
