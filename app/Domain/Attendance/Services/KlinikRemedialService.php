<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Exam\Models\ExamQuestion;
use Illuminate\Support\Collection;

/**
 * Klinik remedial mikro pasca-publikasi untuk siswa.
 *
 * Mengambil maksimal 5 butir pilihan ganda yang dijawab salah oleh siswa pada
 * ujian yang hasilnya sudah diterbitkan, tanpa mengubah nilai resmi.
 */
class KlinikRemedialService
{
    public const MAKS_BUTIR = 5;

    /**
     * Daftar butir remedial milik siswa.
     *
     * @return array<int, array<string, mixed>>
     */
    public function untukSiswa(int $studentId): array
    {
        $butirSalah = $this->kandidatSalah($studentId)
            ->take(self::MAKS_BUTIR);

        return $butirSalah->map(function (AttemptQuestion $aq) {
            $soal = $aq->soal;

            if ($soal === null) {
                return null;
            }

            return [
                'attempt_question_id' => $aq->id,
                'mapel' => $aq->attempt?->penempatan?->exam?->subject?->name ?? 'Umum',
                'ujian' => $aq->attempt?->penempatan?->exam?->title ?? 'Ujian',
                'judul' => $soal->judul,
                'soal' => $soal->soal_json,
                'opsi' => $soal->opsi_json ?? [],
            ];
        })->filter()->values()->all();
    }

    /**
     * Periksa jawaban latihan remedial siswa secara langsung.
     *
     * @return array{benar: bool, kunci: string|null, pembahasan: string|null}
     */
    public function periksaJawaban(int $studentId, int $attemptQuestionId, string $opsiDipilih): array
    {
        $aq = AttemptQuestion::query()
            ->whereKey($attemptQuestionId)
            ->whereHas('attempt.penempatan', fn ($q) => $q->where('student_id', $studentId))
            ->whereHas('attempt.penempatan.exam.event', fn ($q) => $q->whereNotNull('results_published_at'))
            ->with('soal')
            ->firstOrFail();

        /** @var ExamQuestion|null $soal */
        $soal = $aq->soal;
        $kunci = is_array($soal?->kunci_json) ? ($soal->kunci_json['opsi'][0] ?? $soal->kunci_json['kunci'] ?? null) : null;
        $benar = $kunci !== null && strcasecmp((string) $kunci, trim($opsiDipilih)) === 0;
        $pembahasan = is_array($soal?->pembahasan_json) ? ($soal->pembahasan_json['teks'] ?? null) : null;

        return [
            'benar' => $benar,
            'kunci' => $kunci !== null ? (string) $kunci : null,
            'pembahasan' => is_string($pembahasan) && trim($pembahasan) !== '' ? trim($pembahasan) : null,
        ];
    }

    /**
     * @return Collection<int, AttemptQuestion>
     */
    private function kandidatSalah(int $studentId): Collection
    {
        return AttemptQuestion::query()
            ->whereHas('attempt', function ($q) use ($studentId) {
                $q->whereIn('status', [ExamAttempt::STATUS_DIKIRIM, ExamAttempt::STATUS_KEDALUWARSA])
                    ->whereHas('penempatan', fn ($p) => $p->where('student_id', $studentId))
                    ->whereHas('penempatan.exam.event', fn ($e) => $e->whereNotNull('results_published_at'));
            })
            ->whereHas('jawaban', fn ($j) => $j->where('is_correct', false))
            ->whereHas('soal', fn ($s) => $s->where('jenis', 'pilihan_ganda'))
            ->with([
                'soal',
                'attempt.penempatan.exam.subject:id,name',
            ])
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->unique('exam_question_id')
            ->values();
    }
}
