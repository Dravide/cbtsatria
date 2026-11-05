<?php

namespace Database\Factories;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamClass;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamClass>
 */
class ExamClassFactory extends Factory
{
    protected $model = ExamClass::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),

            // Urutannya menentukan - lihat catatan di ExamFactory.
            'school_id' => fn (array $a) => Exam::find($a['exam_id'])?->school_id,

            'class_id' => fn (array $a) => SchoolClass::factory()
                ->untukTahun(AcademicYear::factory()
                    ->untukSekolah(School::find($a['school_id']))
                    ->create())
                ->create()->id,
        ];
    }

    public function untukUjian(Exam $ujian): static
    {
        return $this->state(fn () => [
            'school_id' => $ujian->school_id,
            'exam_id' => $ujian->id,
        ]);
    }

    public function untukKelas(SchoolClass $kelas): static
    {
        return $this->state(fn () => [
            'school_id' => $kelas->school_id,
            'class_id' => $kelas->id,
        ]);
    }
}
