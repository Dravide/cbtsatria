<?php

namespace App\Domain\Grading\Services;

use App\Domain\Attendance\Contracts\SumberNilai;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Grading\Models\ExamResult;

/**
 * Jawaban Grading atas `SumberNilai`: angka yang dibaca laman nilai siswa.
 *
 * ---------------------------------------------------------------------
 * Hanya baris yang SUDAH TERBIT yang keluar dari sini
 * ---------------------------------------------------------------------
 * Penyaringan publikasi dilakukan di dalam service ini, bukan di controller
 * siswa, dan itu bukan penataan - controller siswa hidup di modul Attendance
 * dan tidak boleh tahu apa pun tentang `published_at`. Kalau penyaringannya
 * ditaruh di sana lewat pemeriksaan yang terlihat sederhana, ia harus membaca
 * `exam_results` langsung, dan arah modulnya berbalik.
 *
 * Akibatnya satu kalimat: nilai yang belum dipublikasikan TIDAK ADA di peta
 * yang dikembalikan. Controller siswa tidak perlu (dan tidak bisa) lupa
 * menyembunyikannya.
 *
 * ---------------------------------------------------------------------
 * `grading_status` dibaca, `pending_essays` tidak dihitung ulang
 * ---------------------------------------------------------------------
 * `PenghitungHasil` sudah memelihara keduanya. Menyimpulkan "final" dari
 * `pending_essays === 0` di sini berarti aturan yang sama hidup di dua
 * tempat, dan saat keduanya berbeda, laman siswa dan laporan Admin akan
 * menyebut angka yang berbeda untuk anak yang sama.
 */
class SumberNilaiService implements SumberNilai
{
    public function perAttempt(array $attemptIds): array
    {
        if ($attemptIds === []) {
            return [];
        }

        return ExamResult::query()
            ->whereIn('exam_attempt_id', $attemptIds)
            ->whereNotNull('published_at')
            ->get()
            ->mapWithKeys(fn (ExamResult $hasil) => [
                (int) $hasil->exam_attempt_id => [
                    'otomatis' => (float) $hasil->automatic_score,
                    'uraian' => (float) $hasil->essay_score,
                    'total' => (float) $hasil->total_score,
                    'maksimal' => (float) $hasil->maximum_score,
                    'persen' => (float) $hasil->final_percentage,
                    'final' => $hasil->final(),
                    'sisa_uraian' => (int) $hasil->pending_essays,
                    'terbit' => true,
                ],
            ])
            ->all();
    }

    public function bolehDibaca(ExamAttempt $attempt): bool
    {
        // Event yang belum menerbitkan hasilnya menutup SELURUH angka, bukan
        // sebagian. `show_objectives` mengatur RINCIAN yang ditampilkan,
        // bukan boleh-tidaknya nilai dibaca - dan menyatukan keduanya berarti
        // satu centang di plate 21 membocorkan nilai seluruh angkatan.
        if (! $attempt->penempatan?->exam?->event?->hasilTerbit()) {
            return false;
        }

        return ExamResult::query()
            ->where('exam_attempt_id', $attempt->id)
            ->whereNotNull('published_at')
            ->exists();
    }
}
