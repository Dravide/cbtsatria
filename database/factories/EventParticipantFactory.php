<?php

namespace Database\Factories;

use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Peserta event.
 *
 * ---------------------------------------------------------------------
 * Factory ini tertinggal di Fase 3, dan Fase 5 membayarnya
 * ---------------------------------------------------------------------
 * Fase 3 membangun tujuh model event tanpa satu factory pun. Fase 5
 * membutuhkannya karena exam_participants menunjuk event_participants lewat
 * composite FK - dan menulis barisnya dengan ::create() mentah berarti
 * menyalin school_id, event_id, dan student_id dengan tangan di setiap test
 * yang menyentuh penempatan.
 *
 * @extends Factory<EventParticipant>
 */
class EventParticipantFactory extends Factory
{
    protected $model = EventParticipant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => SchoolEvent::factory(),

            // Diturunkan dari eventnya, dan urutannya menentukan - closure
            // menerima array atribut yang sudah dievaluasi sampai kunci itu
            // saja. Composite FK di event_participants menuntut school_id,
            // event_id, dan student_id berasal dari sekolah yang sama, jadi
            // tiga factory yang berdiri sendiri-sendiri selalu ditolak MySQL.
            'school_id' => function (array $atribut) {
                return SchoolEvent::find($atribut['event_id'])?->school_id;
            },

            // Siswa dibuat DI SEKOLAH YANG SAMA, bukan User::factory() yang
            // memilih sekolahnya sendiri.
            'student_id' => function (array $atribut) {
                return User::factory()->siswa()->untukSekolah(
                    School::find($atribut['school_id'])
                )->create()->id;
            },

            // Null, dan itu keadaan yang sah: peserta manual boleh siswa yang
            // belum punya kelas pada tahun ajaran eventnya.
            'class_id' => null,
            'source' => EventParticipant::SUMBER_MANUAL,
            'status' => EventParticipant::STATUS_TERDAFTAR,
            'registered_at' => now(),
        ];
    }

    public function untukEvent(SchoolEvent $event): static
    {
        return $this->state(fn () => [
            'school_id' => $event->school_id,
            'event_id' => $event->id,
        ]);
    }

    public function untukSiswa(User $siswa): static
    {
        return $this->state(fn () => [
            'school_id' => $siswa->school_id,
            'student_id' => $siswa->id,
        ]);
    }

    public function dariKelas(): static
    {
        return $this->state(fn () => ['source' => EventParticipant::SUMBER_KELAS]);
    }

    public function dikecualikan(): static
    {
        return $this->state(fn () => ['status' => EventParticipant::STATUS_DIKECUALIKAN]);
    }
}
