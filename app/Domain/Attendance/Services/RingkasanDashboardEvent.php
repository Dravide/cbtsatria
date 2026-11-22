<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Event\Models\EventNotification;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;

class RingkasanDashboardEvent
{
    /**
     * Ringkasan operasional event aktif untuk beranda Admin.
     *
     * @return array<string, mixed>|null
     */
    public function untukEventAktif(?int $eventId): ?array
    {
        if ($eventId === null) {
            return null;
        }

        $event = SchoolEvent::query()->find($eventId);
        if ($event === null) {
            return null;
        }

        $idExam = $event->exams()->pluck('id');
        $sesi = $idExam->isEmpty()
            ? collect()
            : ExamSession::query()
                ->whereIn('exam_id', $idExam)
                ->with(['exam:id,title,subject_id', 'exam.subject:id,name'])
                ->orderBy('access_opens_at')
                ->orderBy('session_number')
                ->get();

        $pesertaTotal = $idExam->isEmpty()
            ? 0
            : ExamParticipant::query()
                ->whereIn('exam_id', $idExam)
                ->where('status', ExamParticipant::STATUS_TERDAFTAR)
                ->count();

        $idPeserta = $idExam->isEmpty()
            ? collect()
            : ExamParticipant::query()
                ->whereIn('exam_id', $idExam)
                ->where('status', ExamParticipant::STATUS_TERDAFTAR)
                ->pluck('id');

        $mengerjakan = 0;
        $selesai = 0;
        $terkunci = 0;

        if ($idPeserta->isNotEmpty()) {
            $statusCounts = ExamAttempt::query()
                ->whereIn('exam_participant_id', $idPeserta)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $mengerjakan = (int) ($statusCounts->get(ExamAttempt::STATUS_BERJALAN) ?? 0);
            $selesai = (int) ($statusCounts->get(ExamAttempt::STATUS_DIKIRIM) ?? 0);
            $terkunci = (int) ($statusCounts->get(ExamAttempt::STATUS_DIKUNCI) ?? 0);
        }

        $notifikasiBelumDibaca = EventNotification::query()
            ->where('event_id', $event->id)
            ->whereNull('read_at')
            ->count();

        $notifikasiTerbaru = EventNotification::query()
            ->where('event_id', $event->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (EventNotification $n): array => [
                'id' => $n->id,
                'tingkat' => $n->tingkat,
                'judul' => $n->judul,
                'pesan' => $n->pesan,
                'konteks_label' => $n->konteks_label,
                'tautan' => $n->tautan,
                'dibaca' => $n->read_at !== null,
                'waktu' => $n->created_at?->format('d M H:i') ?? '—',
            ])
            ->values()
            ->all();

        $sesiTerdekat = $sesi
            ->sortBy(fn (ExamSession $s) => [
                $s->status->value === 'berlangsung' ? 0 : ($s->status->value === 'siap' ? 1 : 2),
                $s->access_opens_at?->timestamp ?? PHP_INT_MAX,
            ])
            ->take(5)
            ->map(fn (ExamSession $s): array => [
                'id' => $s->id,
                'label' => $s->label(),
                'ujian' => $s->exam?->subject?->name ?? $s->exam?->title,
                'tanggal' => $s->access_opens_at?->format('d M') ?? '—',
                'jam' => $s->access_opens_at?->format('H:i') ?? '—',
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
            ])
            ->values()
            ->all();

        return [
            'id' => $event->id,
            'angka' => [
                'sesi' => $sesi->count(),
                'peserta' => $pesertaTotal,
                'mengerjakan' => $mengerjakan,
                'selesai' => $selesai,
                'terkunci' => $terkunci,
                'notifikasi' => $notifikasiBelumDibaca,
            ],
            'sesi_terdekat' => $sesiTerdekat,
            'notifikasi_terbaru' => $notifikasiTerbaru,
        ];
    }
}
