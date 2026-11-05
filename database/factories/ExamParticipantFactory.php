<?php

namespace Database\Factories;

use App\Domain\Event\Models\EventParticipant;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamParticipant>
 */
class ExamParticipantFactory extends Factory
{
    protected $model = ExamParticipant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),

            // Urutannya menentukan - lihat catatan di ExamFactory.
            'school_id' => fn (array $a) => Exam::find($a['exam_id'])?->school_id,

            // Pesertanya diambil dari EVENT ujiannya, bukan dari
            // EventParticipant::factory() yang membuat event baru. Composite FK
            // menuntut keduanya sepakat, dan penempatan yang menunjuk peserta
            // event lain adalah kombinasi yang lolos FK (sekolahnya sama)
            // tetapi tidak berarti apa pun.
            'event_participant_id' => fn (array $a) => EventParticipant::factory()
                ->untukEvent(Exam::find($a['exam_id'])?->event)
                ->create()->id,

            // Diturunkan dari peserta eventnya, bukan dari User::factory().
            // Itu meniru satu-satunya cara yang sah menulisinya di produksi -
            // PenempatanService mengambilnya dari event participant, tidak dari
            // input.
            'student_id' => fn (array $a) => EventParticipant::find($a['event_participant_id'])?->student_id,

            // Nullable: peserta terdaftar di ujian sebelum sesinya dibentuk.
            'exam_session_id' => null,
            'exam_session_room_id' => null,
            'assigned_at' => null,
            'status' => ExamParticipant::STATUS_TERDAFTAR,
        ];
    }

    public function untukUjian(Exam $ujian): static
    {
        return $this->state(fn () => [
            'school_id' => $ujian->school_id,
            'exam_id' => $ujian->id,
        ]);
    }

    public function untukPesertaEvent(EventParticipant $peserta): static
    {
        return $this->state(fn () => [
            'school_id' => $peserta->school_id,
            'event_participant_id' => $peserta->id,
            'student_id' => $peserta->student_id,
        ]);
    }

    public function dikecualikan(): static
    {
        return $this->state(fn () => ['status' => ExamParticipant::STATUS_DIKECUALIKAN]);
    }
}
