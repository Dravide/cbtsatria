<?php

namespace App\Domain\Grading\Services;

use App\Domain\Attendance\Contracts\PenghapusHasil;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Grading\Models\ExamResult;

/**
 * Jawaban Grading atas `PenghapusHasil` - dipakai reset ujian.
 *
 * ---------------------------------------------------------------------
 * Yang dihapus HANYA baris hasilnya
 * ---------------------------------------------------------------------
 * `attempt_questions`, `student_answers`, dan `essay_gradings` sengaja
 * dibiarkan. Ketiganya riwayat apa yang pernah dilihat dan dijawab anak itu,
 * dan attempt yang dibatalkan tetap attempt yang pernah terjadi - Admin yang
 * mereset ujian karena kesalahan teknis justru sedang membutuhkan riwayat
 * itu. Yang tidak boleh tersisa hanyalah NILAI, karena nilai atas pengerjaan
 * yang dibatalkan akan terbaca sebagai nilai.
 */
class PenghapusHasilService implements PenghapusHasil
{
    public function hapus(ExamAttempt $attempt): void
    {
        ExamResult::query()
            ->where('exam_attempt_id', $attempt->id)
            ->delete();
    }
}
