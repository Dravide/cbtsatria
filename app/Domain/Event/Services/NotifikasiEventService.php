<?php

namespace App\Domain\Event\Services;

use App\Domain\Event\Models\EventNotification;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Support\PengaturanBawaan;

class NotifikasiEventService
{
    /**
     * Catat notifikasi anomali event bila jenis tersebut diaktifkan di pengaturan sekolah.
     *
     * @param  array{
     *   school_id: int,
     *   event_id: int,
     *   exam_session_id?: int|null,
     *   exam_session_room_id?: int|null,
     *   jenis: string,
     *   tingkat: string,
     *   judul: string,
     *   pesan: string,
     *   konteks_label?: string|null,
     *   tautan?: string|null,
     * }  $data
     */
    public function catat(array $data): ?EventNotification
    {
        if (! $this->aktifUntuk((int) $data['school_id'], $data['jenis'])) {
            return null;
        }

        return EventNotification::query()->create([
            'school_id' => $data['school_id'],
            'event_id' => $data['event_id'],
            'exam_session_id' => $data['exam_session_id'] ?? null,
            'exam_session_room_id' => $data['exam_session_room_id'] ?? null,
            'jenis' => $data['jenis'],
            'tingkat' => $data['tingkat'],
            'judul' => mb_substr($data['judul'], 0, 120),
            'pesan' => mb_substr($data['pesan'], 0, 255),
            'konteks_label' => isset($data['konteks_label']) ? mb_substr((string) $data['konteks_label'], 0, 60) : null,
            'tautan' => $data['tautan'] ?? null,
        ]);
    }

    public function aktifUntuk(int $schoolId, string $jenis): bool
    {
        $sekolah = School::query()->find($schoolId);
        $pengaturan = PengaturanBawaan::gabung($sekolah?->settings);

        return match ($jenis) {
            EventNotification::JENIS_TERKUNCI => (bool) ($pengaturan['notifikasi_terkunci'] ?? true),
            EventNotification::JENIS_PELANGGARAN => (bool) ($pengaturan['notifikasi_pelanggaran'] ?? true),
            EventNotification::JENIS_TERPUTUS => (bool) ($pengaturan['notifikasi_terputus_massal'] ?? true),
            EventNotification::JENIS_INSIDEN => (bool) ($pengaturan['notifikasi_insiden'] ?? true),
            default => true,
        };
    }

    /**
     * Ringkasan untuk ikon lonceng & toast di header Admin.
     *
     * @return array{
     *   belum_dibaca: int,
     *   kritis_belum_dibaca: int,
     *   suara: bool,
     *   daftar: array<int, array<string, mixed>>
     * }
     */
    public function ringkas(SchoolEvent $event, int $batas = 12): array
    {
        $sekolah = School::query()->find($event->school_id);
        $pengaturan = PengaturanBawaan::gabung($sekolah?->settings);

        $belumDibaca = EventNotification::query()
            ->where('event_id', $event->id)
            ->whereNull('read_at')
            ->count();

        $kritisBelumDibaca = EventNotification::query()
            ->where('event_id', $event->id)
            ->whereNull('read_at')
            ->where('tingkat', EventNotification::TINGKAT_BAD)
            ->count();

        $daftar = EventNotification::query()
            ->where('event_id', $event->id)
            ->orderByDesc('id')
            ->limit($batas)
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
                'waktu' => $n->created_at?->format('H:i') ?? '',
                'tanggal' => $n->created_at?->format('d M H:i:s') ?? '',
            ])
            ->values()
            ->all();

        return [
            'belum_dibaca' => $belumDibaca,
            'kritis_belum_dibaca' => $kritisBelumDibaca,
            'suara' => (bool) ($pengaturan['notifikasi_suara'] ?? false),
            'daftar' => $daftar,
        ];
    }

    public function tandaiDibaca(EventNotification $notifikasi): void
    {
        if ($notifikasi->read_at === null) {
            $notifikasi->forceFill(['read_at' => now()])->save();
        }
    }

    public function tandaiSemuaDibaca(SchoolEvent $event): int
    {
        return EventNotification::query()
            ->where('event_id', $event->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
