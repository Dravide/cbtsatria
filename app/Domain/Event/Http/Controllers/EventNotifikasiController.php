<?php

namespace App\Domain\Event\Http\Controllers;

use App\Domain\Event\Models\EventNotification;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Services\NotifikasiEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EventNotifikasiController
{
    public function index(Request $request, SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);
        $request->session()->put('event_aktif', $event->id);

        $jenis = $request->string('jenis')->toString();
        $status = $request->string('status')->toString();

        $query = EventNotification::query()
            ->where('event_id', $event->id)
            ->when($jenis !== '', fn ($q) => $q->where('jenis', $jenis))
            ->when($status === 'belum', fn ($q) => $q->whereNull('read_at'))
            ->when($status === 'dibaca', fn ($q) => $q->whereNotNull('read_at'));

        $baris = $query
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (EventNotification $n): array => [
                'id' => $n->id,
                'jenis' => $n->jenis,
                'tingkat' => $n->tingkat,
                'judul' => $n->judul,
                'pesan' => $n->pesan,
                'konteks_label' => $n->konteks_label,
                'tautan' => $n->tautan,
                'dibaca' => $n->read_at !== null,
                'waktu' => $n->created_at?->format('d M H:i:s') ?? '—',
            ])
            ->values()
            ->all();

        $semua = EventNotification::query()->where('event_id', $event->id);

        return Inertia::render('Admin/Event/Notifikasi', [
            'event' => [
                'id' => $event->id,
                'kode' => $event->code,
                'nama' => $event->name,
                'status' => $event->status->value,
                'status_label' => $event->status->label(),
            ],
            'angka' => [
                'total' => (clone $semua)->count(),
                'belum_dibaca' => (clone $semua)->whereNull('read_at')->count(),
                'terkunci' => (clone $semua)->where('jenis', EventNotification::JENIS_TERKUNCI)->count(),
                'ruang_terdampak' => (clone $semua)
                    ->whereNotNull('exam_session_room_id')
                    ->distinct('exam_session_room_id')
                    ->count('exam_session_room_id'),
            ],
            'baris' => $baris,
            'saring' => [
                'jenis' => $jenis,
                'status' => $status,
            ],
        ]);
    }

    public function ringkas(SchoolEvent $event, NotifikasiEventService $service): JsonResponse
    {
        Gate::authorize('view', $event);

        return response()->json($service->ringkas($event));
    }

    public function baca(
        Request $request,
        SchoolEvent $event,
        EventNotification $notifikasi,
        NotifikasiEventService $service,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('view', $event);
        abort_unless($notifikasi->event_id === $event->id, 404);

        $service->tandaiDibaca($notifikasi);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }

    public function bacaSemua(
        Request $request,
        SchoolEvent $event,
        NotifikasiEventService $service,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('view', $event);

        $service->tandaiSemuaDibaca($event);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('sukses', 'Semua notifikasi event ditandai sudah dibaca.');
    }
}
