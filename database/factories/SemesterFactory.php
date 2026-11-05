<?php

namespace Database\Factories;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Semester>
 */
class SemesterFactory extends Factory
{
    protected $model = Semester::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'name' => 'Ganjil',
            'start_date' => '2026-07-01',
            'end_date' => '2026-12-31',
            'is_active' => false,
        ];
    }

    public function genap(): static
    {
        return $this->state(fn () => [
            'name' => 'Genap',
            'start_date' => '2027-01-01',
            'end_date' => '2027-06-30',
        ]);
    }

    public function aktif(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }
}
