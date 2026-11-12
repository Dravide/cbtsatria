<?php

namespace App\Domain\Attendance\Enums;

/**
 * Sisi foto berita acara - PRD §12.2.
 *
 * ---------------------------------------------------------------------
 * Dua sisi di skema, SATU yang ditawarkan lamannya
 * ---------------------------------------------------------------------
 * Sejak 2026-11-12 laman berita acara hanya meminta satu foto: keadaan ruang
 * SAAT UJIAN BERLANGSUNG. Ia sisi yang sama dengan syarat pengiriman final,
 * sehingga laman yang hanya menawarkan satu foto tetap bisa menghasilkan
 * laporan lengkap.
 *
 * `Sesudah` TETAP ada di enum - bukan karena lamannya menawarkannya, tetapi
 * karena baris lama di `session_report_files` bisa saja memakainya, dan
 * menghapus case-nya akan membuat baris itu gagal di-cast saat dibaca.
 * Controller menolak unggahan bertanda `sebelum` dengan kalimat, bukan dengan
 * galat 500.
 *
 * ---------------------------------------------------------------------
 * Nilai `sesudah` dipertahankan, labelnya tidak
 * ---------------------------------------------------------------------
 * Nilai yang tersimpan di basis data tetap `sesudah` - nilai itulah yang
 * dipakai `kekuranganFinal()` dan papan monitoring. Yang berubah hanya kata
 * yang dibaca pengawas: foto diminta KETIKA ujian berlangsung, bukan sesudah
 * peserta keluar. Label lama membuat pengawas menunggu ujian selesai lebih
 * dulu, lalu mengunggah foto ruang kosong - bukti yang justru tidak
 * menjawab apa pun tentang ketertiban ujiannya.
 */
enum SisiFoto: string
{
    case Sebelum = 'sebelum';
    case Sesudah = 'sesudah';

    public function label(): string
    {
        return match ($this) {
            self::Sebelum => 'Sebelum ujian',
            self::Sesudah => 'Saat ujian berlangsung',
        };
    }

    /** Keterangan yang dirender di bawah kotak fotonya. */
    public function petunjuk(): string
    {
        return match ($this) {
            self::Sebelum => 'Ambil sebelum peserta masuk: ruang kosong, meja bersih.',
            self::Sesudah => 'Ambil saat peserta sedang mengerjakan: ruang terisi, kursi terpakai.',
        };
    }
}
