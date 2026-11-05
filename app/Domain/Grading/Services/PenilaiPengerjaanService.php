<?php

namespace App\Domain\Grading\Services;

use App\Domain\Attendance\Contracts\PenilaiPengerjaan;
use App\Domain\Attendance\Models\ExamAttempt;

/**
 * Jawaban Attendance atas kontraknya sendiri: nilainya dihitung modul Grading.
 *
 * ---------------------------------------------------------------------
 * Dua langkah, dan urutannya tidak boleh dibalik
 * ---------------------------------------------------------------------
 * `PenilaiOtomatis` lebih dulu menulis `is_correct`/`score_awarded` setiap
 * butir objektif, BARU `PenghitungHasil` menjumlahkannya. Membalik urutannya
 * berarti menjumlahkan `score_awarded` yang masih kosong dari attempt ini -
 * hasil yang berisi nol untuk seluruh soal pilihan ganda, dan yang tampak
 * seperti siswa yang tidak menjawab apa pun.
 *
 * Keduanya dipanggil dari sini, bukan dari dua tempat, supaya urutan itu
 * hanya ditulis sekali.
 *
 * ---------------------------------------------------------------------
 * Galatnya ditelan, dan itu disengaja
 * ---------------------------------------------------------------------
 * Pemanggilnya adalah `MesinUjian` yang sedang menutup attempt. Melempar dari
 * sini berarti siswa yang menekan Kumpulkan menerima halaman galat dan
 * attempt-nya TIDAK TERTUTUP - jawaban yang sudah ia kerjakan tetap berstatus
 * berjalan sampai deadline, dan pengawas melihatnya sebagai masih mengerjakan.
 *
 * Yang lebih buruk: `tutupBilaKedaluwarsa()` dipanggil di setiap pembacaan
 * attempt. Exception di sana membuat halaman siswa gagal dimuat seluruhnya.
 *
 * Nilai yang belum terhitung bukan kehilangan data - `PenghitungHasil::hitungUlangUjian()`
 * bisa menghitungnya kapan saja, dan Admin punya tombolnya di plate 07.
 */
class PenilaiPengerjaanService implements PenilaiPengerjaan
{
    public function __construct(
        private readonly PenilaiOtomatis $otomatis,
        private readonly PenghitungHasil $penghitung,
    ) {}

    public function nilai(ExamAttempt $attempt): void
    {
        try {
            $this->otomatis->nilaiAttempt($attempt);
            $this->penghitung->hitungUlang($attempt);
        } catch (\Throwable $e) {
            // Dilaporkan, bukan ditelan diam-diam: kegagalan di sini berarti
            // nilai satu siswa tidak ada, dan itu harus terlihat di log
            // aplikasi - bukan hanya di layar yang tidak pernah dibuka.
            report($e);
        }
    }
}
