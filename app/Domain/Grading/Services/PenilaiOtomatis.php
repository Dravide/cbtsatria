<?php

namespace App\Domain\Grading\Services;

use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\StudentAnswer;
use App\Domain\Grading\Support\PenilaiButir;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menulis hasil penilaian otomatis ke `student_answers` - PRD §13.1-§13.3.
 *
 * =====================================================================
 * Modul Grading MENULIS ke tabel Attendance, dan itu arah yang benar
 * =====================================================================
 * `student_answers.is_correct` dan `score_awarded` sudah ada sejak Fase 7
 * dengan sengaja kosong: "null berarti BELUM DINILAI". Yang mengisinya
 * adalah fase ini, dan yang mengisinya hidup di modul Grading - bukan di
 * Attendance.
 *
 * Arahnya satu arah: Grading membaca dan menulis model Attendance, Attendance
 * tidak pernah mengenal Grading. Kalau sebaliknya, `JawabanService` di
 * Attendance harus tahu aturan penilaian, dan mesin ujian yang sedang
 * berjalan akan menilai jawaban saat disimpan - sebelum siswa selesai.
 * Nilai yang bergerak di layar saat siswa masih mengerjakan adalah nilai
 * yang bisa ditebak dari perilakunya sendiri.
 *
 * =====================================================================
 * Kapan dipanggil, dan mengapa bukan saat menyimpan jawaban
 * =====================================================================
 * Setelah attempt DIKIRIM atau KEDALUWARSA. Sebelum itu, jawaban masih bisa
 * berubah, dan menilai jawaban yang masih bisa berubah berarti menilai dua
 * kali dengan dua hasil yang berbeda untuk satu butir.
 *
 * Idempotent: memanggilnya ulang menghitung ulang dari snapshot, bukan
 * menambah point demi point. Itu yang membuat "hitung ulang" di plate 07
 * aman, dan yang membuat koreksi kunci soal yang diperbaiki Admin bisa
 * ditegakkan tanpa rekonsiliasi manual.
 *
 * =====================================================================
 * Uraian tidak disentuh, sama sekali
 * =====================================================================
 * `is_correct` dan `score_awarded` jawaban uraian dibiarkan apa adanya.
 * Menyetelnya ke false/0 akan membuat uraian yang belum dikoreksi tercatat
 * SALAH - dan `pending_essays` di `exam_results` tidak akan pernah bisa
 * membedakan keduanya lagi.
 */
class PenilaiOtomatis
{
    public function __construct(private readonly PenilaiButir $penilai) {}

    /**
     * Nilai seluruh butir objektif satu attempt.
     *
     * @return int Jumlah jawaban yang ditulis.
     */
    public function nilaiAttempt(ExamAttempt $attempt): int
    {
        return DB::transaction(function () use ($attempt) {
            $butir = AttemptQuestion::query()
                ->where('exam_attempt_id', $attempt->id)
                ->with('soal', 'jawaban')
                ->get();

            $ditulis = 0;

            foreach ($butir as $satu) {
                if ($satu->soal === null) {
                    // Snapshot yang hilang bukan alasan melempar: attempt lama
                    // dari ujian yang datanya sudah dipangkas tetap harus bisa
                    // dihitung sebagian, bukan gagal seluruhnya.
                    continue;
                }

                $hasil = $this->penilai->nilai($satu->soal, $satu->jawaban?->answer_json);

                if ($hasil['benar'] === null) {
                    // Uraian - bukan urusan mesin.
                    continue;
                }

                $this->tulis($satu, $hasil);

                $ditulis++;
            }

            return $ditulis;
        });
    }

    /**
     * Tulis hasil satu butir ke baris jawabannya.
     *
     * Baris yang BELUM ADA tidak dibuat di sini. Siswa yang tidak pernah
     * menyentuh butir 12 memang tidak punya baris jawaban, dan membuatkannya
     * baris berisi `{"opsi":[]}` berarti mengarang jawaban yang tidak pernah
     * dikirim - terbaca di riwayat sebagai "dijawab lalu dikosongkan".
     * Ketiadaan baris sudah cukup berarti nol poin, dan `PenghitungHasil`
     * membaca keduanya dengan cara yang sama.
     *
     * @param  array{benar: bool|null, skor: float}  $hasil
     */
    private function tulis(AttemptQuestion $butir, array $hasil): void
    {
        $jawaban = $butir->jawaban;

        if ($jawaban === null) {
            return;
        }

        /** @var StudentAnswer $jawaban */
        $jawaban->forceFill([
            'is_correct' => $hasil['benar'],
            'score_awarded' => $hasil['skor'],
        ])->save();
    }

    /**
     * Ringkasan per butir untuk satu attempt - dipakai pratinjau nilai.
     *
     * Tidak menulis apa pun. Dihitung dari snapshot dan jawaban yang ada,
     * sehingga Guru bisa melihat "kalau dikunci sekarang, nilainya berapa"
     * tanpa menunggu attempt-nya benar-benar dikirim.
     *
     * @return array{otomatis: float, maksimal: float, terjawab: int, total: int}
     */
    public function ringkas(ExamAttempt $attempt): array
    {
        $butir = AttemptQuestion::query()
            ->where('exam_attempt_id', $attempt->id)
            ->with('soal', 'jawaban')
            ->get();

        return $this->ringkasDari($butir);
    }

    /**
     * Ringkasan dari kumpulan butir yang sudah dimuat.
     *
     * Dipisah supaya `PenghitungHasil` bisa memuat butirnya sekali untuk
     * keduanya. Dua pemanggilan yang masing-masing memuat ulang 40 butir
     * adalah 80 baris untuk satu attempt - dan plate 07 memuat ratusan
     * attempt sekaligus.
     *
     * @param  Collection<int, AttemptQuestion>  $butir
     * @return array{otomatis: float, maksimal: float, terjawab: int, total: int}
     */
    public function ringkasDari(Collection $butir): array
    {
        $otomatis = 0.0;
        $maksimal = 0.0;
        $terjawab = 0;

        foreach ($butir as $satu) {
            if ($satu->soal === null) {
                continue;
            }

            $maksimal += $satu->soal->bobotBerlaku();

            if ($satu->jawaban?->terjawab()) {
                $terjawab++;
            }

            $otomatis += (float) ($satu->jawaban?->score_awarded ?? 0);
        }

        return [
            'otomatis' => round($otomatis, 2),
            'maksimal' => round($maksimal, 2),
            'terjawab' => $terjawab,
            'total' => $butir->count(),
        ];
    }
}
