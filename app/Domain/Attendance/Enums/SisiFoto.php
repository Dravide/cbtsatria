<?php

namespace App\Domain\Attendance\Enums;

/**
 * Sisi foto berita acara - PRD §12.2.
 *
 * Dua sisi, dan keduanya wajib: satu sebelum ujian dimulai, satu sesudah
 * seluruh peserta keluar. Bukan satu foto "ruang ujian" - karena satu foto
 * tidak bisa membuktikan dua hal yang berbeda, dan yang perlu dibuktikan
 * justru keduanya: ruangnya kosong saat dibuka, dan kosong saat ditutup.
 *
 * Urutannya juga bukan urusan tampilan: `sesudah` adalah syarat pengiriman
 * final, dan pengawas yang mengirim lebih dulu tidak akan pernah bisa
 * melengkapinya.
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
