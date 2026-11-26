<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Models\RoomSignal;
use App\Domain\Attendance\Services\PantauPublik;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\EventAnnouncement;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Tenancy\Models\School;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Layar publik mode IFP / TV - `/tv/{slug}`, TANPA login.
 *
 * =====================================================================
 * Mengapa terpisah dari `/pantau/{slug}`
 * =====================================================================
 * `/pantau/{slug}` dirancang untuk dibaca dan digulir di ponsel/laptop.
 * Laman ini dirancang khusus untuk layar besar 16:9 (IFP 65"-86" / TV
 * lorong) yang berjalan penuh satu layar tanpa scrollbar, berhuruf besar,
 * berganti halaman ruang otomatis, dan memadukan angka live dengan jam
 * hitung mundur, pengumuman panitia, serta jadwal sesi berikutnya.
 *
 * Batas privasi identik dengan `/pantau/{slug}` dan `/jadwal/{slug}`:
 * HANYA angka per ruang, kode ruang, pengawas, pengumuman, dan jadwal.
 * Tidak pernah memuat nama siswa, NIS, nomor kursi, token, atau nilai.
 */
class TvPublikController
{
    public function __construct(private readonly PantauPublik $pantau) {}

    public function tampilkan(string $slug): Response
    {
        $sekolah = $this->sekolah($slug);

        return Inertia::render('Publik/Tv', [
            'slug' => $slug,
            'denyut_url' => "/tv/{$slug}/denyut",
            ...$this->susunMuatan($sekolah),
        ]);
    }

    public function denyut(string $slug): JsonResponse
    {
        $sekolah = School::query()->where('papan_slug', $slug)->first();

        if ($sekolah === null) {
            return response()->json(['pesan' => 'Layar tidak ditemukan.'], 404);
        }

        return response()->json($this->susunMuatan($sekolah));
    }

    /** @return array<string, mixed> */
    private function susunMuatan(School $sekolah): array
    {
        $potret = $this->pantau->susun($sekolah);

        // Ketika sesi sudah selesai (tidak ada sesi berjalan maupun berikutnya),
        // layar TV kembali ke angka 0 dan mengosongkan daftar ruang.
        if (($potret['keadaan'] ?? null) === 'selesai') {
            $potret = [
                'ada' => false,
                'keadaan' => 'kosong',
                'sekolah' => $potret['sekolah'] ?? $sekolah->name,
                'diperbarui_jam' => $potret['diperbarui_jam'] ?? now()->format('H:i:s'),
                'sesi' => null,
                'ruang' => [],
                'angka' => [
                    'peserta' => 0,
                    'mengerjakan' => 0,
                    'selesai' => 0,
                    'belum_mulai' => 0,
                    'terputus' => 0,
                    'terkunci' => 0,
                ],
                'laporan' => [
                    'selesai' => 0,
                    'total' => 0,
                ],
            ];
        }

        $sesiAktifId = isset($potret['sesi']['id']) ? (int) $potret['sesi']['id'] : null;

        $selesaiIso = null;

        if ($sesiAktifId !== null) {
            $sesiModel = ExamSession::query()
                ->withoutGlobalScopes()
                ->where('school_id', $sekolah->id)
                ->whereKey($sesiAktifId)
                ->first(['id', 'hard_end_at']);

            $selesaiIso = $sesiModel?->hard_end_at?->toIso8601String();
        }

        $pengumuman = EventAnnouncement::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->berlaku()
            ->whereHas('event', function ($q) use ($sekolah) {
                $q->withoutGlobalScopes()
                    ->where('school_id', $sekolah->id)
                    ->whereIn('status', [
                        EventStatus::Ongoing->value,
                        EventStatus::Ready->value,
                    ]);
            })
            ->orderByRaw('CASE nada WHEN ? THEN 0 ELSE 1 END', [EventAnnouncement::NADA_PENTING])
            ->orderByDesc('created_at')
            ->limit(4)
            ->get()
            ->map(fn (EventAnnouncement $a) => [
                'id' => (int) $a->id,
                'judul' => $a->title,
                'isi' => $a->body,
                'nada' => $a->nada,
                'dibuat' => $a->created_at?->format('H:i'),
            ])
            ->values()
            ->all();

        $sesiBerikutnya = ExamSession::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->whereIn('status', [StatusSesi::Siap->value, StatusSesi::Draft->value])
            ->where('access_opens_at', '>', now())
            ->when($sesiAktifId !== null, fn ($q) => $q->where('id', '!=', $sesiAktifId))
            ->whereHas('exam.event', function ($q) use ($sekolah) {
                $q->withoutGlobalScopes()
                    ->where('school_id', $sekolah->id)
                    ->whereIn('status', [
                        EventStatus::Ongoing->value,
                        EventStatus::Ready->value,
                    ]);
            })
            ->with([
                'exam' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'subject_id', 'title'),
                'exam.subject' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'),
            ])
            ->orderBy('access_opens_at')
            ->limit(4)
            ->get()
            ->map(fn (ExamSession $s) => [
                'id' => (int) $s->id,
                'label' => $s->label(),
                'mapel' => $s->exam?->subject?->name,
                'ujian' => $s->exam?->title,
                'hari' => $s->access_opens_at?->isToday()
                    ? 'Hari ini'
                    : $s->access_opens_at?->locale('id')->translatedFormat('D, d M'),
                'jam' => ($s->access_opens_at?->format('H:i') ?? '--:--')
                    .'–'.($s->hard_end_at?->format('H:i') ?? '--:--'),
            ])
            ->values()
            ->all();

        $sinyal = RoomSignal::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->where('status', RoomSignal::STATUS_AKTIF)
            ->whereHas('room.session', function ($q) {
                $q->withoutGlobalScopes()
                    ->whereNotIn('status', [StatusSesi::Selesai->value, StatusSesi::Batal->value])
                    ->where(fn ($w) => $w->whereNull('hard_end_at')->orWhere('hard_end_at', '>=', now()));
            })
            ->with([
                'room' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'event_room_id'),
                'room.eventRoom' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'room_id'),
                'room.eventRoom.room' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code', 'name', 'location'),
            ])
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(fn (RoomSignal $s) => [
                'id' => (int) $s->id,
                'ruang_id' => (int) $s->exam_session_room_id,
                'ruang' => $s->room?->eventRoom?->room?->code ?? 'Ruang',
                'ruang_nama' => $s->room?->eventRoom?->room?->name,
                'lokasi' => $s->room?->eventRoom?->room?->location,
                'jenis' => $s->jenis,
                'label' => $s->labelJenis(),
                'catatan' => $s->catatan,
                'pelapor' => $s->pelapor_nama,
                'jam' => $s->created_at?->format('H:i'),
            ])
            ->values()
            ->all();

        return [
            'potret' => $potret,
            'selesai_iso' => $selesaiIso,
            'server_iso' => now()->toIso8601String(),
            'pengumuman' => $pengumuman,
            'sesi_berikutnya' => $sesiBerikutnya,
            'sinyal' => $sinyal,
        ];
    }

    private function sekolah(string $slug): School
    {
        $sekolah = School::query()->where('papan_slug', $slug)->first();

        if ($sekolah === null) {
            abort(404);
        }

        return $sekolah;
    }
}
