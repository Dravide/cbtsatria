<?php

namespace App\Domain\Attendance\Support;

/**
 * Satu penolakan pindai - judul menyebut SEBAB, pesan menyebut LANGKAH.
 *
 * Judul dan pesan dipisah karena plate B6 mengaturnya begitu: kepala kotak
 * berbunyi "Kode sudah kedaluwarsa", dan isinya menyebut apa yang harus
 * dilakukan berikutnya. Penolakan yang hanya berbunyi "kode tidak sah"
 * membuat siswa menebak, lalu menanyakan hal yang salah kepada pengawas di
 * pintu.
 */
final readonly class PenolakanPindai
{
    public function __construct(
        public string $judul,
        public string $pesan,
    ) {}
}
