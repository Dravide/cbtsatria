<?php

namespace App\Support\Cetak;

use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Services\PetaKursiEvent;
use App\Domain\Facility\Models\Room;
use App\Domain\Tenancy\Models\School;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Cetak nomor ruang A4 (nama event, nomor/kode ruang ukuran besar, dan daftar kelas).
 */
class NomorRuangPdf
{
    public function __construct(private readonly PetaKursiEvent $petaKursi) {}

    /**
     * @param  iterable<int, Room>  $ruang
     */
    public function buat(iterable $ruang, School $sekolah, ?int $eventAktifId = null): string
    {
        $event = $this->pilihEvent($sekolah->id, $eventAktifId);
        $eventRooms = $event === null
            ? collect()
            : EventRoom::query()
                ->withoutGlobalScopes()
                ->where('school_id', $sekolah->id)
                ->where('event_id', $event->id)
                ->get()
                ->keyBy('room_id');

        $halaman = [];

        foreach ($ruang as $r) {
            /** @var EventRoom|null $er */
            $er = $eventRooms->get($r->id);
            $kelas = [];

            if ($er !== null) {
                $kelas = collect($this->petaKursi->barisRuang((int) $er->id))
                    ->pluck('kelas')
                    ->filter(fn ($k) => is_string($k) && trim($k) !== '')
                    ->unique()
                    ->values()
                    ->all();

                sort($kelas, SORT_NATURAL | SORT_FLAG_CASE);
            }

            $halaman[] = [
                'kode' => $r->code,
                'nama' => $r->name,
                'lokasi' => $r->location,
                'event' => $event?->name,
                'kelas' => $kelas,
            ];
        }

        return Pdf::loadView('kartu.nomor-ruang', [
            'sekolah' => KopSekolah::dari($sekolah),
            'sekolahModel' => $sekolah,
            'event' => $event?->name,
            'halaman' => $halaman,
        ])->setPaper('a4', 'portrait')->output();
    }

    public function namaBerkas(): string
    {
        return 'nomor-ruang-a4.pdf';
    }

    private function pilihEvent(int $schoolId, ?int $eventAktifId): ?SchoolEvent
    {
        if ($eventAktifId !== null) {
            $aktif = SchoolEvent::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->whereKey($eventAktifId)
                ->first();

            if ($aktif !== null) {
                return $aktif;
            }
        }

        return SchoolEvent::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->orderByDesc('id')
            ->first();
    }
}
