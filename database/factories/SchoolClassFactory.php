<?php

namespace Database\Factories;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolClass>
 */
class SchoolClassFactory extends Factory
{
    protected $model = SchoolClass::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'name' => fake()->randomElement(['X-1', 'X-2', 'XI TKJ 1', 'XI TKJ 2', 'XII AKL 1']),
            'grade_level' => 'X',
            'homeroom_teacher_id' => null,
            'status' => 'aktif',
        ];
    }

    /**
     * Kelas pada tahun ajaran tertentu.
     *
     * school_id ikut diselaraskan: kelas dan tahun ajaran yang berbeda
     * sekolah akan ditolak MySQL oleh composite FK, dan factory yang
     * menghasilkan kombinasi mustahil itu hanya menghasilkan test yang gagal
     * di tempat yang salah.
     */
    public function untukTahun(AcademicYear $tahun): static
    {
        return $this->state(fn () => [
            'school_id' => $tahun->school_id,
            'academic_year_id' => $tahun->id,
        ]);
    }
}
