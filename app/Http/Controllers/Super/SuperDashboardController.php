<?php

namespace App\Http\Controllers\Super;

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Services\PantauPublik;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class SuperDashboardController
{
    public function index(): Response
    {
        $totalSekolah = School::query()->count();
        $sekolahAktif = School::query()->where('status', 'aktif')->count();
        $sekolahTangguh = School::query()->where('status', '!=', 'aktif')->count();

        $sesiBerjalan = ExamSession::query()
            ->withoutGlobalScopes()
            ->where('status', StatusSesi::Berjalan->value)
            ->with([
                'exam' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'school_id', 'event_id', 'subject_id', 'title'),
                'exam.event' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'),
                'exam.subject' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'),
                'exam.school:id,code,name,papan_slug',
            ])
            ->withCount('rooms')
            ->orderBy('access_opens_at')
            ->get();

        $sesiIds = $sesiBerjalan->pluck('id')->all();

        $attemptAktif = $sesiIds === []
            ? collect()
            : ExamAttempt::query()
                ->withoutGlobalScopes()
                ->where('status', ExamAttempt::STATUS_BERJALAN)
                ->whereHas('participant', fn ($q) => $q->withoutGlobalScopes()->whereIn('exam_session_id', $sesiIds))
                ->with(['participant' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'exam_session_id')])
                ->get();

        $attemptPerSesi = $attemptAktif->groupBy(fn (ExamAttempt $a) => (int) $a->participant?->exam_session_id);

        $radar = $sesiBerjalan->map(function (ExamSession $s) use ($attemptPerSesi) {
            $list = $attemptPerSesi->get($s->id, collect());
            $terkunci = $list->filter(fn (ExamAttempt $a) => $a->dikunci())->count();
            $terputus = $list->filter(fn (ExamAttempt $a) => ! $a->terhubung(PantauPublik::AMBANG_TERPUTUS))->count();

            return [
                'id' => (int) $s->id,
                'sekolah_id' => (int) $s->school_id,
                'sekolah' => $s->exam?->school?->name ?? 'Sekolah',
                'papan_slug' => $s->exam?->school?->papan_slug,
                'event' => $s->exam?->event?->name,
                'mapel' => $s->exam?->subject?->name ?? $s->exam?->title,
                'label' => $s->label(),
                'jam' => ($s->access_opens_at?->format('H:i') ?? '--:--').'–'.($s->hard_end_at?->format('H:i') ?? '--:--'),
                'ruang' => (int) ($s->rooms_count ?? 0),
                'mengerjakan' => $list->count(),
                'terkunci' => $terkunci,
                'terputus' => $terputus,
            ];
        })->values()->all();

        $pesertaKonkuren = $attemptAktif->count();
        $totalTerkunci = $attemptAktif->filter(fn (ExamAttempt $a) => $a->dikunci())->count();
        $totalTerputus = $attemptAktif->filter(fn (ExamAttempt $a) => ! $a->terhubung(PantauPublik::AMBANG_TERPUTUS))->count();

        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $queuedJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;

        return Inertia::render('Super/Dashboard', [
            'kpi' => [
                'total_sekolah' => $totalSekolah,
                'sekolah_aktif' => $sekolahAktif,
                'sekolah_tangguh' => $sekolahTangguh,
                'ujian_berjalan' => count($radar),
                'ruang_aktif' => (int) $sesiBerjalan->sum('rooms_count'),
                'peserta_konkuren' => $pesertaKonkuren,
                'terkunci' => $totalTerkunci,
                'terputus' => $totalTerputus,
                'failed_jobs' => $failedJobs,
                'queued_jobs' => $queuedJobs,
            ],
            'radar' => $radar,
            'diperbarui' => now()->format('H:i:s'),
        ]);
    }
}
