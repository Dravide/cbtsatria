<?php

namespace Database\Factories;

use App\Domain\Event\Models\EventRoom;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamSessionRoom>
 */
class ExamSessionRoomFactory extends Factory
{
    protected $model = ExamSessionRoom::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_session_id' => ExamSession::factory(),

            // Urutannya menentukan - lihat catatan di ExamFactory.
            'school_id' => fn (array $a) => ExamSession::find($a['exam_session_id'])?->school_id,

            // Ruangnya diambil dari EVENT sesinya, bukan dari EventRoom::factory()
            // yang membuat event baru. Inilah bedanya yang menentukan: sesi dan
            // ruang yang tidak berasal dari event yang sama menghasilkan baris
            // yang ditolak composite FK, dan - lebih buruk - kalaupun lolos
            // (sekolahnya sama) ia menjadi temuan `ruang-luar-event` di panel
            // validasi.
            'event_room_id' => fn (array $a) => EventRoom::factory()
                ->untukEvent(ExamSession::find($a['exam_session_id'])?->exam?->event)
                ->create()->id,

            // Null berarti "pakai kapasitas alokasi event" - bukan nol.
            'capacity_override' => null,
        ];
    }

    public function untukSesi(ExamSession $sesi): static
    {
        return $this->state(fn () => [
            'school_id' => $sesi->school_id,
            'exam_session_id' => $sesi->id,
        ]);
    }

    public function untukRuangEvent(EventRoom $ruang): static
    {
        return $this->state(fn () => [
            'school_id' => $ruang->school_id,
            'event_room_id' => $ruang->id,
        ]);
    }

    public function kapasitas(int $kursi): static
    {
        return $this->state(fn () => ['capacity_override' => $kursi]);
    }
}
