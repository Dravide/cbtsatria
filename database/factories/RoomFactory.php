<?php

namespace Database\Factories;

use App\Domain\Facility\Models\Room;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'code' => 'R-'.fake()->unique()->numberBetween(1, 200),
            'name' => fake()->randomElement(['Lab Komputer', 'Ruang Teori', 'Aula', 'Lab TKJ']),
            'location' => 'Gedung '.fake()->randomElement(['A', 'B', 'C']),
            'capacity' => 32,
            // Bawaan: ruang ber-PC. Ruang BYOD dibuat lewat ->byod().
            'device_count' => 32,
            'status' => 'aktif',
        ];
    }

    /**
     * Ruang BYOD: tanpa perangkat tetap milik sekolah (wireframe plate 09, R-04).
     */
    public function byod(): static
    {
        return $this->state(fn () => ['device_count' => 0]);
    }

    public function perbaikan(): static
    {
        return $this->state(fn () => ['status' => 'perbaikan']);
    }

    public function untukSekolah(School $school): static
    {
        return $this->state(fn () => ['school_id' => $school->id]);
    }
}
