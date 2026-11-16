<?php

namespace App\Domain\Exam\Services;

use App\Domain\Event\Contracts\PemakaiRuangEvent;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\ExamSessionRoom;

/**
 * Menjawab "ruang mana yang masih dipakai sesi ujian" - lihat
 * `PemakaiRuangEvent` tentang mengapa bentuknya kontrak.
 */
class PemakaiRuangEventService implements PemakaiRuangEvent
{
    public function ruangTerpakaiSesi(SchoolEvent $event, array $roomIds): array
    {
        if ($roomIds === []) {
            return [];
        }

        // `roomIds` adalah id ruang MASTER; `event_rooms.room_id` yang
        // menunjuknya, dan `exam_session_rooms.event_room_id` yang menunjuk
        // baris event_rooms itu. Rantainya ditelusuri di sini, bukan di Event.
        $eventRoomIds = EventRoom::query()
            ->where('event_id', $event->id)
            ->whereIn('room_id', $roomIds)
            ->pluck('id');

        if ($eventRoomIds->isEmpty()) {
            return [];
        }

        return ExamSessionRoom::query()
            ->whereIn('event_room_id', $eventRoomIds)
            ->with(['eventRoom.room:id,code', 'session:id,session_number'])
            ->get()
            ->map(fn (ExamSessionRoom $r) => sprintf(
                '%s (%s)',
                $r->eventRoom?->room?->code ?? 'R-?',
                $r->session !== null ? 'S-'.$r->session->session_number : 'sesi ?',
            ))
            ->unique()
            ->values()
            ->all();
    }
}
