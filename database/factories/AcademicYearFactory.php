<?php

namespace Database\Factories;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    protected $model = AcademicYear::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $mulai = fake()->numberBetween(2020, 2030);

        return [
            'school_id' => School::factory(),
            'name' => $mulai.'/'.($mulai + 1),
            'start_date' => $mulai.'-07-01',
            'end_date' => ($mulai + 1).'-06-30',
            'is_active' => false,
        ];
    }

    public function aktif(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    public function untukSekolah(School $school): static
    {
        return $this->state(fn () => ['school_id' => $school->id]);
    }
}
