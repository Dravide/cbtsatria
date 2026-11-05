<?php

namespace Database\Factories;

use App\Domain\Academic\Models\Subject;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exam>
 */
class ExamFactory extends Factory
{
    protected $model = Exam::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => SchoolEvent::factory(),

            // Tiga atribut berturut-turut di bawah saling bergantung, dan
            // URUTANNYA menentukan. Closure di sini menerima array atribut
            // yang sudah dievaluasi sampai kunci itu saja, sehingga
            // `SchoolEvent::find($atribut['event_id'])` hanya punya isi bila
            // event_id ditulis lebih dulu - sebaliknya yang datang adalah
            // objek factory, dan PHP melempar "could not be converted to
            // string" dari tempat yang tidak menjelaskan apa pun.
            'school_id' => fn (array $a) => SchoolEvent::find($a['event_id'])?->school_id,

            // Mapel dan pembuatnya dibuat DI SEKOLAH YANG SAMA. Composite FK di
            // exams menuntut school_id cocok dengan event, subject, dan
            // created_by sekaligus, jadi factory yang berdiri sendiri-sendiri
            // selalu ditolak MySQL dengan 1452 - galat yang tidak menyebut
            // kolom mana yang salah.
            'subject_id' => fn (array $a) => Subject::factory()
                ->untukSekolah(School::find($a['school_id']))
                ->create()->id,

            'created_by' => null,
            'event_activity_id' => null,
            // NULL di sini bukan kelalaian: bank soal adalah Fase 4, dan ujian
            // tanpa paket adalah keadaan yang NYATA sepanjang Fase 5. Factory
            // yang mengisinya akan menyembunyikan justru keadaan yang paling
            // sering terjadi.
            'question_package_id' => null,
            'code' => 'UJ-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'Ujian '.fake()->randomElement(['Teori', 'Praktik', 'Susulan']),
            'exam_type' => 'Ujian akhir semester',
            'instructions' => null,
            'duration_minutes' => 60,
            'max_attempts' => 1,
            'shuffle_questions' => false,
            'shuffle_options' => false,
            'allow_back_navigation' => true,
            'allow_late_entry' => false,
            'late_entry_deadline' => null,
            'status' => 'draft',
        ];
    }

    public function untukEvent(SchoolEvent $event): static
    {
        return $this->state(fn () => [
            'school_id' => $event->school_id,
            'event_id' => $event->id,
        ]);
    }

    public function untukMapel(Subject $mapel): static
    {
        return $this->state(fn () => [
            'school_id' => $mapel->school_id,
            'subject_id' => $mapel->id,
        ]);
    }
}
