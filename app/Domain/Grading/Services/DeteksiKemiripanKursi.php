<?php

namespace App\Domain\Grading\Services;

use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Services\NomorKursi;

/**
 * Deteksi kemiripan jawaban salah identik antar kursi bersebelahan pasca-ujian.
 */
class DeteksiKemiripanKursi
{
    public const MIN_SAMA = 3;

    public const KOLOM_BAWAAN = 4;

    public function __construct(private readonly NomorKursi $kursi) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function untukUjian(int $examId): array
    {
        $peserta = ExamParticipant::query()
            ->where('exam_id', $examId)
            ->whereNotNull('exam_session_room_id')
            ->with([
                'student:id,name',
                'room.eventRoom.room:id,code,name,columns',
            ])
            ->get();

        if ($peserta->count() < 2) {
            return [];
        }

        $attempt = ExamAttempt::query()
            ->whereIn('exam_participant_id', $peserta->pluck('id'))
            ->whereIn('status', [ExamAttempt::STATUS_DIKIRIM, ExamAttempt::STATUS_KEDALUWARSA, ExamAttempt::STATUS_DIKUNCI])
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('exam_participant_id')
            ->map(fn ($b) => $b->last());

        $attemptIds = $attempt->filter()->pluck('id')->all();

        if ($attemptIds === []) {
            return [];
        }

        // Kumpulkan peta jawaban salah per attempt: [exam_attempt_id => [exam_question_id => json_encode(answer_json)]]
        $salahPerAttempt = AttemptQuestion::query()
            ->whereIn('exam_attempt_id', $attemptIds)
            ->whereHas('jawaban', fn ($q) => $q->where('is_correct', false))
            ->with('jawaban:id,attempt_question_id,answer_json')
            ->get()
            ->groupBy('exam_attempt_id')
            ->map(function ($butir) {
                $peta = [];

                foreach ($butir as $b) {
                    if ($b->jawaban?->answer_json !== null) {
                        $peta[$b->exam_question_id] = json_encode($b->jawaban->answer_json);
                    }
                }

                return $peta;
            });

        $hasil = [];

        foreach ($peserta->groupBy('exam_session_room_id') as $kelompokRuang) {
            $pertama = $kelompokRuang->first();
            $eventRoomId = (int) ($pertama?->room?->event_room_id ?? 0);
            $kolom = max(1, (int) ($pertama?->room?->eventRoom?->room?->columns ?? self::KOLOM_BAWAAN));
            $kodeRuang = $pertama?->room?->eventRoom?->room?->code ?? 'Ruang';
            $petaKursi = $this->kursi->petaRuangEvent($eventRoomId);

            $daftar = $kelompokRuang->values();

            for ($i = 0; $i < $daftar->count(); $i++) {
                for ($j = $i + 1; $j < $daftar->count(); $j++) {
                    $pa = $daftar[$i];
                    $pb = $daftar[$j];
                    $ka = $petaKursi[$pa->student_id] ?? null;
                    $kb = $petaKursi[$pb->student_id] ?? null;

                    if ($ka === null || $kb === null) {
                        continue;
                    }

                    if (abs(intdiv($ka - 1, $kolom) - intdiv($kb - 1, $kolom)) > 1 || abs((($ka - 1) % $kolom) - (($kb - 1) % $kolom)) > 1) {
                        continue;
                    }

                    $aa = $attempt->get($pa->id);
                    $ab = $attempt->get($pb->id);

                    if ($aa === null || $ab === null) {
                        continue;
                    }

                    $sa = $salahPerAttempt->get($aa->id, []);
                    $sb = $salahPerAttempt->get($ab->id, []);
                    $sama = 0;

                    foreach ($sa as $qid => $ans) {
                        if (isset($sb[$qid]) && $sb[$qid] === $ans) {
                            $sama++;
                        }
                    }

                    if ($sama >= self::MIN_SAMA) {
                        $hasil[] = [
                            'ruang' => $kodeRuang,
                            'kursi_a' => $ka,
                            'nama_a' => $pa->student?->name ?? 'Peserta',
                            'kursi_b' => $kb,
                            'nama_b' => $pb->student?->name ?? 'Peserta',
                            'salah_sama' => $sama,
                        ];
                    }
                }
            }
        }

        usort($hasil, fn ($x, $y) => $y['salah_sama'] <=> $x['salah_sama']);

        return $hasil;
    }
}
