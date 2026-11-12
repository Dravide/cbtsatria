<?php

namespace App\Domain\Attendance\Contracts;

use App\Domain\Attendance\Models\ExamAttempt;

/**
 * Penghapusan nilai satu pengerjaan yang dibatalkan - kontrak yang dipanggil
 * modul Attendance, dijawab modul Grading.
 *
 * ---------------------------------------------------------------------
 * Mengapa kontrak, bukan impor langsung
 * ---------------------------------------------------------------------
 * `exam_results` milik modul Grading, dan peta modul melarang Attendance
 * menunjuknya (`DomainBoundariesTest`). Tetapi ada satu tindakan Admin yang
 * MEMANG menghapus nilai: reset ujian. Attempt yang direset ditandai
 * `batal`, dan baris hasil yang sudah terlanjur dihitung untuknya adalah baris
 * untuk pengerjaan yang menurut sistem tidak pernah terjadi.
 *
 * Membiarkannya bukan pilihan yang aman meski seluruh pembaca hasil menyaring
 * status attempt: `SumberNilaiService::perAttempt()` menyaring lewat
 * `published_at`, bukan lewat status, sehingga satu baris basi cukup untuk
 * menampilkan nilai pengerjaan yang sudah dibatalkan di laman "Nilai saya".
 *
 * Arahnya sama dengan `PenilaiPengerjaan`: yang mendeklarasikan kontrak adalah
 * pemakainya (Attendance), yang menyediakan implementasinya adalah pemilik
 * datanya (Grading), dan yang menyambungkan keduanya adalah provider.
 */
interface PenghapusHasil
{
    /**
     * Hapus baris `exam_results` milik satu attempt, bila ada.
     *
     * Idempotent: attempt yang belum pernah dinilai tidak punya baris, dan
     * pemanggilan kedua tidak menggagalkan apa pun.
     */
    public function hapus(ExamAttempt $attempt): void;
}
