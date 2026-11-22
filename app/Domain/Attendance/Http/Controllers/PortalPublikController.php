<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\EventAnnouncement;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Tenancy\Models\School;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal 1 pintu laman publik sekolah - `/info/{slug}`, TANPA login.
 *
 * Menyatukan seluruh laman publik (`/jadwal`, `/ruang`, `/wali`, `/papan`,
 * `/pantau`, `/tv`) di balik satu alamat dan satu QR yang ditempel di
 * gerbang/mading. Memakai `schools.papan_slug` yang sama; sekolah tanpa
 * slug mengembalikan 404.
 *
 * Batas privasi: tidak pernah mengirim nama siswa, NIS, nomor kursi,
 * token, nilai, atau daftar kelas terkunci.
 */
class PortalPublikController
{
    public function tampilkan(string $slug): Response
    {
        $sekolah = School::query()->where('papan_slug', $slug)->first();

        if ($sekolah === null) {
            abort(404);
        }

        $sesiBerjalan = ExamSession::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->where('status', StatusSesi::Berjalan->value)
            ->whereHas('exam.event', function ($q) use ($sekolah) {
                $q->withoutGlobalScopes()
                    ->where('school_id', $sekolah->id)
                    ->whereIn('status', [EventStatus::Ongoing->value, EventStatus::Ready->value]);
            })
            ->with([
                'exam' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'event_id', 'subject_id', 'title'),
                'exam.event' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'),
                'exam.subject' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'),
            ])
            ->withCount('rooms')
            ->orderBy('access_opens_at')
            ->first();

        $sesiBerikut = $sesiBerjalan !== null
            ? null
            : ExamSession::query()
                ->withoutGlobalScopes()
                ->where('school_id', $sekolah->id)
                ->whereIn('status', [StatusSesi::Siap->value, StatusSesi::Draft->value])
                ->where('access_opens_at', '>', now())
                ->whereHas('exam.event', function ($q) use ($sekolah) {
                    $q->withoutGlobalScopes()
                        ->where('school_id', $sekolah->id)
                        ->whereIn('status', [EventStatus::Ongoing->value, EventStatus::Ready->value]);
                })
                ->with([
                    'exam' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'event_id', 'subject_id', 'title'),
                    'exam.event' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'),
                    'exam.subject' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'),
                ])
                ->withCount('rooms')
                ->orderBy('access_opens_at')
                ->first();

        $sesi = $sesiBerjalan ?? $sesiBerikut;

        $pengumuman = EventAnnouncement::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->berlaku()
            ->whereHas('event', function ($q) use ($sekolah) {
                $q->withoutGlobalScopes()
                    ->where('school_id', $sekolah->id)
                    ->whereIn('status', [EventStatus::Ongoing->value, EventStatus::Ready->value]);
            })
            ->orderByRaw('CASE nada WHEN ? THEN 0 ELSE 1 END', [EventAnnouncement::NADA_PENTING])
            ->orderByDesc('created_at')
            ->first();

        $jumlahKelasWali = SchoolClass::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->where('status', 'aktif')
            ->whereNotNull('wali_slug')
            ->count();

        return Inertia::render('Publik/Portal', [
            'sekolah' => $sekolah->name,
            'slug' => $slug,
            'diperbarui' => now()->format('H:i'),
            'keadaan' => $sesiBerjalan !== null ? 'berjalan' : 'siaga',
            'sesi_ringkas' => $sesi === null ? null : [
                'berjalan' => $sesiBerjalan !== null,
                'event' => $sesi->exam?->event?->name,
                'mapel' => $sesi->exam?->subject?->name,
                'ujian' => $sesi->exam?->title,
                'label' => $sesi->label(),
                'tanggal' => $sesi->access_opens_at?->isToday()
                    ? 'Hari ini'
                    : $sesi->access_opens_at?->locale('id')->translatedFormat('l, d M'),
                'jam' => ($sesi->access_opens_at?->format('H:i') ?? '--:--')
                    .'–'.($sesi->hard_end_at?->format('H:i') ?? '--:--'),
                'jumlah_ruang' => (int) ($sesi->rooms_count ?? 0),
            ],
            'pengumuman' => $pengumuman === null ? null : [
                'id' => (int) $pengumuman->id,
                'judul' => $pengumuman->title,
                'isi' => $pengumuman->body,
                'nada' => $pengumuman->nada,
                'dibuat' => $pengumuman->created_at?->format('H:i'),
            ],
            'jumlah_kelas_wali' => $jumlahKelasWali,
        ]);
    }
}
