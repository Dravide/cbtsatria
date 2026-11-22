<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\EventAnnouncement;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Tenancy\Models\School;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Jadwal dan pengumuman event publik - `/jadwal/{slug}`, TANPA login.
 *
 * =====================================================================
 * Bedanya dengan `PapanPengawas` dan `PantauPublik`
 * =====================================================================
 * Kedua laman itu sengaja dibatasi pada sesi "SEKARANG" (`MAKS_SESI = 3`)
 * agar panitia di lorong mendapat jawaban cepat. Laman ini melengkapinya
 * dengan gambaran seluruh rangkaian event (lintas hari) beserta pengumuman
 * resmi panitia (`EventAnnouncement`) yang sedang berlaku.
 *
 * =====================================================================
 * Batas isi: HANYA jadwal dan pengumuman
 * =====================================================================
 * Alamat ini tayang untuk seluruh internet. Yang boleh tampil:
 *
 *   - daftar event aktif/siap/selesai terbaru milik sekolah;
 *   - pengumuman berlaku (`EventAnnouncement::scopeBerlaku()`);
 *   - jadwal sesi per hari: jam, mapel, judul ujian, label sesi, durasi,
 *     tingkat/kelas sasaran, status sesi, dan penanda susulan.
 *
 * Yang TIDAK boleh: nama siswa, NIS, nomor kursi, token, nama pengawas,
 * berita acara, atau nilai dalam bentuk apa pun.
 */
class JadwalPublik
{
    /** Batas jumlah event terbaru yang ditampilkan sekaligus. */
    public const MAKS_EVENT = 5;

    /**
     * Susun daftar event beserta pengumuman dan jadwal lintas harinya.
     *
     * @return array<string, mixed>|null Null bila sekolah belum menyalakan slug publik.
     */
    public function susun(School $sekolah): ?array
    {
        if ($sekolah->papan_slug === null) {
            return null;
        }

        $events = SchoolEvent::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->whereIn('status', [
                EventStatus::Ongoing->value,
                EventStatus::Ready->value,
                EventStatus::Completed->value,
            ])
            ->orderByRaw(
                'CASE status WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END',
                [EventStatus::Ongoing->value, EventStatus::Ready->value],
            )
            ->orderBy('start_at')
            ->orderByDesc('id')
            ->limit(self::MAKS_EVENT)
            ->get();

        $eventIds = $events->pluck('id')->all();

        $pengumumanPerEvent = $eventIds === []
            ? collect()
            : EventAnnouncement::query()
                ->withoutGlobalScopes()
                ->where('school_id', $sekolah->id)
                ->whereIn('event_id', $eventIds)
                ->berlaku()
                ->orderByRaw('CASE nada WHEN ? THEN 0 ELSE 1 END', [EventAnnouncement::NADA_PENTING])
                ->orderByDesc('created_at')
                ->get()
                ->groupBy('event_id');

        $sesiPerEvent = $eventIds === []
            ? collect()
            : ExamSession::query()
                ->withoutGlobalScopes()
                ->where('school_id', $sekolah->id)
                ->where('status', '!=', StatusSesi::Batal->value)
                ->whereHas('exam', function ($q) use ($sekolah, $eventIds) {
                    $q->withoutGlobalScopes()
                        ->where('school_id', $sekolah->id)
                        ->whereIn('event_id', $eventIds);
                })
                ->with([
                    'exam' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'school_id', 'event_id', 'subject_id', 'title', 'duration_minutes'),
                    'exam.subject' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'),
                    'exam.classes' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'exam_id', 'class_id'),
                    'exam.classes.kelas' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name', 'grade_level'),
                ])
                ->orderBy('access_opens_at')
                ->orderBy('session_number')
                ->get()
                ->groupBy(fn (ExamSession $s) => (int) $s->exam?->event_id);

        return [
            'sekolah' => $sekolah->name,
            'diperbarui' => now()->format('H:i'),
            'events' => $events->map(fn (SchoolEvent $event) => $this->ringkasEvent(
                $event,
                $pengumumanPerEvent->get($event->id, collect()),
                $sesiPerEvent->get($event->id, collect()),
            ))->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, EventAnnouncement>  $pengumuman
     * @param  Collection<int, ExamSession>  $sesi
     * @return array<string, mixed>
     */
    private function ringkasEvent(SchoolEvent $event, Collection $pengumuman, Collection $sesi): array
    {
        $hari = $sesi
            ->groupBy(fn (ExamSession $s) => $s->access_opens_at?->format('Y-m-d') ?? 'tanpa-tanggal')
            ->map(function (Collection $daftar, string $kunci) {
                /** @var ExamSession|null $pertama */
                $pertama = $daftar->first();
                $tanggal = $pertama?->access_opens_at;

                return [
                    'tanggal' => $kunci,
                    'label' => $tanggal !== null
                        ? $tanggal->locale('id')->translatedFormat('l, d M Y')
                        : 'Jadwal menyusul',
                    'hari_ini' => $tanggal !== null && $tanggal->isToday(),
                    'sesi' => $daftar->map(fn (ExamSession $s) => $this->ringkasSesi($s))->values()->all(),
                ];
            })
            ->values()
            ->all();

        $tingkatTersedia = $sesi
            ->flatMap(fn (ExamSession $s) => $this->tingkatSesi($s))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'id' => (int) $event->id,
            'nama' => $event->name,
            'jenis' => $event->event_type,
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
            'rentang' => $this->formatRentang($event->start_at, $event->end_at),
            'tingkat' => $tingkatTersedia,
            'pengumuman' => $pengumuman->map(fn (EventAnnouncement $a) => [
                'id' => (int) $a->id,
                'judul' => $a->title,
                'isi' => $a->body,
                'nada' => $a->nada,
                'dibuat' => $a->created_at?->locale('id')->translatedFormat('d M, H:i'),
            ])->values()->all(),
            'hari' => $hari,
        ];
    }

    /** @return array<string, mixed> */
    private function ringkasSesi(ExamSession $sesi): array
    {
        $mulai = $sesi->access_opens_at?->format('H:i') ?? '--:--';
        $selesai = $sesi->hard_end_at?->format('H:i') ?? '--:--';

        $kelas = $sesi->exam?->classes
            ->map(fn ($ec) => $ec->kelas?->name)
            ->filter(fn ($nama) => is_string($nama) && trim($nama) !== '')
            ->unique()
            ->sort()
            ->values()
            ->all() ?? [];

        return [
            'id' => (int) $sesi->id,
            'label' => $sesi->label(),
            'ujian' => $sesi->exam?->title,
            'mapel' => $sesi->exam?->subject?->name,
            'jam' => "{$mulai}–{$selesai}",
            'durasi_menit' => $sesi->durasiMenit(),
            'status' => $sesi->status->value,
            'status_label' => $sesi->status->label(),
            'susulan' => $sesi->susulan(),
            'tingkat' => $this->tingkatSesi($sesi),
            'kelas' => $kelas,
        ];
    }

    /** @return array<int, string> */
    private function tingkatSesi(ExamSession $sesi): array
    {
        return $sesi->exam?->classes
            ->map(fn ($ec) => $ec->kelas?->grade_level)
            ->filter(fn ($t) => $t !== null && trim((string) $t) !== '')
            ->map(fn ($t) => trim((string) $t))
            ->unique()
            ->sort()
            ->values()
            ->all() ?? [];
    }

    private function formatRentang(?CarbonInterface $mulai, ?CarbonInterface $selesai): ?string
    {
        if ($mulai === null && $selesai === null) {
            return null;
        }

        if ($mulai !== null && $selesai !== null) {
            if ($mulai->isSameDay($selesai)) {
                return $mulai->locale('id')->translatedFormat('d M Y');
            }

            if ($mulai->isSameMonth($selesai)) {
                return $mulai->format('d').'–'.$selesai->locale('id')->translatedFormat('d M Y');
            }

            return $mulai->locale('id')->translatedFormat('d M').' – '.$selesai->locale('id')->translatedFormat('d M Y');
        }

        return ($mulai ?? $selesai)?->locale('id')->translatedFormat('d M Y');
    }
}
