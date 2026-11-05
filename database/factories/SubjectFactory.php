<?php

namespace Database\Factories;

use App\Domain\Academic\Models\Subject;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            // Kode unik per sekolah; 4 huruf cukup untuk menghindari tabrakan
            // ketika satu test membuat belasan mapel.
            'code' => Str::upper(Str::random(4)),
            'name' => fake()->randomElement([
                'Matematika', 'Bahasa Indonesia', 'Bahasa Inggris', 'Fisika',
                'Kimia', 'Biologi', 'Sejarah', 'Ekonomi', 'Produktif TKJ',
            ]),
            'category' => fake()->randomElement(['Wajib', 'Peminatan', 'Kejuruan']),
            'grade_level' => fake()->randomElement(['X', 'XI', 'XII', null]),
            'status' => 'aktif',
        ];
    }

    public function nonaktif(): static
    {
        return $this->state(fn () => ['status' => 'nonaktif']);
    }

    public function untukSekolah(School $school): static
    {
        return $this->state(fn () => ['school_id' => $school->id]);
    }
}
