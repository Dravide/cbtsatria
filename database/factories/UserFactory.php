<?php

namespace Database\Factories;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'role' => Role::Student,
            'name' => fake()->name(),
            // Unik global - lihat catatan di model User.
            'username' => Str::lower(Str::random(12)),
            'email' => null,
            'phone' => null,
            'password' => 'password',
            'is_active' => true,
            'last_login_at' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin]);
    }

    public function guru(): static
    {
        return $this->state(fn () => ['role' => Role::Teacher]);
    }

    public function siswa(): static
    {
        return $this->state(fn () => ['role' => Role::Student]);
    }

    public function pengawas(): static
    {
        return $this->state(fn () => ['role' => Role::Supervisor]);
    }

    public function nonaktif(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function untukSekolah(School $school): static
    {
        return $this->state(fn () => ['school_id' => $school->id]);
    }
}
