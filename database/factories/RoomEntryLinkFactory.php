<?php

namespace Database\Factories;

use App\Domain\Facility\Models\Room;
use App\Domain\Facility\Models\RoomEntryLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RoomEntryLink>
 */
class RoomEntryLinkFactory extends Factory
{
    protected $model = RoomEntryLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => fn (array $atribut) => Room::find($atribut['room_id'])?->school_id,
            'room_id' => Room::factory(),
            // Slug unik global - ia muncul di URL publik tanpa kode sekolah.
            'slug' => Str::lower(Str::random(4)).'-'.Str::lower(Str::random(4)),
            'expires_at' => now()->addHours(2),
            'revoked_at' => null,
            'use_count' => 0,
            'bound_device_fingerprint' => null,
            'last_used_at' => null,
        ];
    }

    public function kedaluwarsa(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subHour()]);
    }

    public function dicabut(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    public function untukRuang(Room $ruang): static
    {
        return $this->state(fn () => [
            'school_id' => $ruang->school_id,
            'room_id' => $ruang->id,
        ]);
    }
}
