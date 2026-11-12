<?php

namespace App\Domain\Attendance\Enums;

/**
 * Keadaan satu baris pengakuan siswa - wireframe-presensi-qr.html plate B4-B6.
 *
 * ---------------------------------------------------------------------
 * `Menunggu` adalah keadaan yang benar, dan itu inti seluruh fitur
 * ---------------------------------------------------------------------
 * Sejak baris pengakuan ditulis sampai pengawas menekan tombolnya, statusnya
 * `menunggu`. Tidak ada satu pun jalur yang membuat siswa mencatat dirinya
 * hadir - presensi adalah dasar gerbang ujian dan dasar berita acara, dan
 * kalau siswa bisa menulisnya, satu ponsel di luar ruang bisa mengubah
 * kehadiran seluruh kelas.
 *
 * Kata di layar siswa karena itu "pengakuan", bukan "Anda sudah hadir".
 * Layar yang menulis yang kedua akan membuat anak berjalan pergi dengan
 * keyakinan yang tidak didukung satu baris data pun - dan keyakinan itu baru
 * runtuh di laporan kehadiran, berhari-hari kemudian.
 *
 * ---------------------------------------------------------------------
 * `Ditolak` disediakan tetapi tidak pernah ditulis hari ini
 * ---------------------------------------------------------------------
 * Penolakan dijawab sebagai kalimat di laman, bukan sebagai baris: kode yang
 * kedaluwarsa bukan peristiwa kehadiran, dan menyimpannya akan membuat daftar
 * pengakuan memuat percobaan yang tidak pernah terjadi. Nilainya ada supaya
 * membatalkan pengakuan kelak menjadi suntingan satu nilai - bukan migrasi.
 */
enum StatusPengakuan: string
{
    case Menunggu = 'menunggu';
    case Dikonfirmasi = 'dikonfirmasi';
    case Ditolak = 'ditolak';

    public function label(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu pengawas',
            self::Dikonfirmasi => 'Dikonfirmasi',
            self::Ditolak => 'Ditolak',
        };
    }
}
