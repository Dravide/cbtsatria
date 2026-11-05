<?php

namespace Database\Factories;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeachingAssignment>
 */
class TeachingAssignmentFactory extends Factory
{
    protected $model = TeachingAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => function (array $atribut) {
                return SchoolClass::find($atribut['class_id'])?->school_id;
            },
            'academic_year_id' => function (array $atribut) {
                return SchoolClass::find($atribut['class_id'])?->academic_year_id;
            },
            'teacher_id' => User::factory()->guru(),
            'subject_id' => Subject::factory(),
            'class_id' => SchoolClass::factory(),
        ];
    }

    /**
     * Penugasan lengkap dalam satu sekolah, supaya composite FK tidak
     * menolak kombinasi yang dibuat terpisah-pisah.
     */
    public function dalamSekolah(int $schoolId): static
    {
        return $this->state(fn () => [
            'school_id' => $schoolId,
            'teacher_id' => User::factory()->guru()->untukSekolah(
                School::find($schoolId)
            ),
            'subject_id' => Subject::factory()->untukSekolah(
                School::find($schoolId)
            ),
        ]);
    }
}
