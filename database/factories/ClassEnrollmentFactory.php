<?php

namespace Database\Factories;

use App\Domain\Academic\Models\ClassEnrollment;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassEnrollment>
 */
class ClassEnrollmentFactory extends Factory
{
    protected $model = ClassEnrollment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => fn (array $atribut) => SchoolClass::find($atribut['class_id'])?->school_id,
            'class_id' => SchoolClass::factory(),
            'student_id' => User::factory()->siswa(),
            'academic_year_id' => fn (array $atribut) => SchoolClass::find($atribut['class_id'])?->academic_year_id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'aktif',
        ];
    }

    public function untukKelas(SchoolClass $kelas): static
    {
        return $this->state(fn () => [
            'school_id' => $kelas->school_id,
            'class_id' => $kelas->id,
            'academic_year_id' => $kelas->academic_year_id,
        ]);
    }

    public function untukSiswa(User $siswa): static
    {
        return $this->state(fn () => [
            'student_id' => $siswa->id,
            'school_id' => $siswa->school_id,
        ]);
    }
}
