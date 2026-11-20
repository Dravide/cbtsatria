<?php

namespace App\Console\Commands;

use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Services\EventStatusService;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Console\Command;

/**
 * Sinkronisasi status event otomatis sesuai jadwal (`start_at`/`end_at`) dan kesiapan.
 */
class SinkronStatusEvent extends Command
{
    protected $signature = 'event:sinkron-status';

    protected $description = 'Perbarui status event otomatis berdasarkan jadwal mulai/selesai dan kesiapan';

    public function handle(EventStatusService $status, SchoolResolver $resolver): int
    {
        $berubah = 0;

        $events = SchoolEvent::tanpaTenant()
            ->whereNotIn('status', [EventStatus::Archived->value, EventStatus::Cancelled->value])
            ->get();

        foreach ($events as $event) {
            $asal = $event->status;
            $resolver->tetapkan($event->school_id);

            try {
                $baru = $status->sinkronkanOtomatis($event);

                if ($baru->status !== $asal) {
                    $berubah++;
                }
            } finally {
                $resolver->forget();
            }
        }

        $this->info("Sinkronisasi selesai: {$berubah} event diperbarui.");

        return self::SUCCESS;
    }
}
