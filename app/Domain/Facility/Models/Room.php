<?php

namespace App\Domain\Facility\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ruang ujian - "R-01", "Lab TKJ 2".
 *
 * ---------------------------------------------------------------------
 * device_count, dan mengapa ia bukan turunan capacity
 * ---------------------------------------------------------------------
 * Wireframe plate 09 memperlihatkan tiga keadaan yang berbeda dan semuanya
 * sah: R-01 kapasitas 32 dengan 32 PC, R-04 kapasitas 40 dengan chip "BYOD",
 * dan R-09 berstatus "perbaikan" sehingga tidak dipakai meski kapasitasnya
 * cukup.
 *
 * Ruang BYOD adalah device_count = 0. Tidak ada cara menebaknya dari
 * kapasitas, dan menebaknya berarti salah menyiapkan ruang pada hari ujian.
 * Karena itu ia kolomnya sendiri.
 *
 * ---------------------------------------------------------------------
 * Ruang master dipakai ulang lintas event
 * ---------------------------------------------------------------------
 * Baris di sini adalah ruang fisik, bukan penempatan di sebuah event. Satu
 * ruang yang sama muncul di banyak event sepanjang tahun. Penempatannya
 * per event hidup di tabel event_rooms (Fase 3), dan tautan masuknya di
 * room_entry_links - yang memang terikat ruang, bukan event, karena satu
 * ruang memakai satu tautan pintu untuk semua ujiannya.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'code', 'name', 'location', 'capacity', 'device_count', 'status'])]
class Room extends Model
{
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'device_count' => 'integer',
        ];
    }

    /** @return HasMany<RoomEntryLink, $this> */
    public function entryLinks(): HasMany
    {
        return $this->hasMany(RoomEntryLink::class);
    }

    public function isAktif(): bool
    {
        return $this->status === 'aktif';
    }

    /**
     * Ruang BYOD: tanpa perangkat tetap milik sekolah.
     */
    public function isByod(): bool
    {
        return $this->device_count === 0;
    }

    /**
     * Satu tautan pintu yang sedang berlaku, bila ada.
     *
     * "Berlaku" berarti belum dicabut dan belum kedaluwarsa. Query-nya di
     * sini, bukan di controller, supaya definisinya satu dan sama di setiap
     * tempat yang bertanya.
     */
    public function tautanAktif(): ?RoomEntryLink
    {
        return $this->entryLinks()
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->first();
    }
}
