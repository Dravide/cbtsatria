<?php

namespace Database\Factories;

use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Facility\Models\Room;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Ruang yang dialokasikan ke event.
 *
 * ---------------------------------------------------------------------
 * Factory ini tertinggal di Fase 3, dan Fase 5 membayarnya
 * ---------------------------------------------------------------------
 * Fase 3 membangun tujuh model event tanpa satu factory pun, dan
 * konsekuensinya setiap test menulis ::create() mentah dengan school_id yang
 * disalin tangan. Fase 5 membutuhkannya karena exam_session_rooms menunjuk
 * event_rooms.
 *
 * ---------------------------------------------------------------------
 * Aturan yang berlaku di seluruh factory Fase 5: AKARNYA ADALAH EVENT
 * ---------------------------------------------------------------------
 * Composite FK di modul ini menuntut setiap baris sepakat soal sekolahnya,
 * dan memang begitu seharusnya - tapi itu berarti factory yang membiarkan
 * setiap kolom membangun modelnya sendiri-sendiri selalu ditolak MySQL
 * dengan 1452, dan galatnya tidak menyebut kolom mana yang salah.
 *
 * Karena itu setiap atribut di bawah diturunkan dari satu akar yang sama:
 * event-nya. Ruangnya, sekolahnya, dan pemiliknya mengikuti event itu, bukan
 * memilih sendiri.
 *
 * @extends Factory<EventRoom>
 */
class EventRoomFactory extends Factory
{
    protected $model = EventRoom::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => SchoolEvent::factory(),

            // Urutannya menentukan: closure menerima array atribut yang sudah
            // dievaluasi sampai kunci itu saja, jadi event_id harus lebih dulu.
            'school_id' => fn (array $a) => SchoolEvent::find($a['event_id'])?->school_id,

            'room_id' => fn (array $a) => Room::factory()
                ->untukSekolah(School::find($a['school_id']))
                ->create()->id,

            // Null berarti "pakai kapasitas ruang master" - bukan nol.
            'capacity_override' => null,
            'status' => 'aktif',
        ];
    }

    public function untukEvent(SchoolEvent $event): static
    {
        return $this->state(fn () => [
            'school_id' => $event->school_id,
            'event_id' => $event->id,
        ]);
    }

    public function untukRuang(Room $ruang): static
    {
        return $this->state(fn () => [
            'school_id' => $ruang->school_id,
            'room_id' => $ruang->id,
        ]);
    }

    public function kapasitas(int $kursi): static
    {
        return $this->state(fn () => ['capacity_override' => $kursi]);
    }

    public function perbaikan(): static
    {
        return $this->state(fn () => ['status' => 'perbaikan']);
    }
}
