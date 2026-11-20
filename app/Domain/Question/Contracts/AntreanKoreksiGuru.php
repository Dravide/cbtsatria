<?php

namespace App\Domain\Question\Contracts;

/**
 * Kontrak penghitung antrean koreksi uraian untuk beranda Guru.
 */
interface AntreanKoreksiGuru
{
    public function jumlahMenunggu(int $teacherId): int;
}
