<?php

namespace App\Domain\Grading\Services;

use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Grading\Support\UjianGuru;
use App\Domain\Question\Contracts\AntreanKoreksiGuru;

class AntreanKoreksiGuruService implements AntreanKoreksiGuru
{
    public function jumlahMenunggu(int $teacherId): int
    {
        $ujianIds = UjianGuru::untuk($teacherId)->pluck('id');

        if ($ujianIds->isEmpty()) {
            return 0;
        }

        return AttemptQuestion::query()
            ->whereHas('soal', fn ($q) => $q->whereIn('exam_id', $ujianIds)->where('jenis', 'uraian'))
            ->whereHas('attempt', fn ($q) => $q->whereIn('status', [
                ExamAttempt::STATUS_DIKIRIM,
                ExamAttempt::STATUS_KEDALUWARSA,
                ExamAttempt::STATUS_DIKUNCI,
            ]))
            ->whereHas('jawaban', fn ($q) => $q->whereNotNull('answer_json')->whereNull('score_awarded'))
            ->count();
    }
}
