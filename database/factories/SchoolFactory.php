<?php

namespace Database\Factories;

use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Str::upper(Str::random(6)),
            'name' => 'SMK Negeri '.fake()->numberBetween(1, 20),
            // 8 digit, unik. NPSN tidak pernah diketik di layar login.
            'npsn' => (string) fake()->unique()->numberBetween(10000000, 99999999),
            'logo_path' => null,
            'address' => fake()->address(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('02#-#######'),
            'timezone' => 'Asia/Jakarta',
            'status' => 'aktif',
            'settings' => null,
        ];
    }

    public function nonaktif(): static
    {
        return $this->state(fn () => ['status' => 'nonaktif']);
    }
}
