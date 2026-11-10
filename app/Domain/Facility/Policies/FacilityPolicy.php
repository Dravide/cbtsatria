<?php

namespace App\Domain\Facility\Policies;

use App\Domain\Facility\Models\Room;
use App\Domain\Facility\Models\RoomEntryLink;
use App\Domain\Identity\Models\User;

/**
 * Ruang dan tautan pintunya.
 *
 * Menerbitkan tautan setara dengan menerbitkan kredensial: tautan + kode
 * membuka ruang, dan ruang berisi jawaban ujian yang sedang berjalan. Karena
 * itu `terbitkanTautan` sengaja hanya untuk Admin - pengawas memakai tautannya,
 * tidak membuatnya.
 *
 * Perhatikan lagi apa yang tidak diperiksa: school_id. Alasannya sama dengan
 * seluruh Policy lain di sistem ini - scope yang memegang tenant, policy yang
 * memegang peran.
 */
class FacilityPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role->value, ['admin', 'supervisor'], true);
    }

    public function view(User $user, Room|RoomEntryLink $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function update(User $user, Room|RoomEntryLink $model): bool
    {
        return $user->role->isAdmin();
    }

    public function delete(User $user, Room|RoomEntryLink $model): bool
    {
        return false;
    }

    public function terbitkanTautan(User $user, Room $room): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Menyetel kapasitas banyak ruang sekaligus.
     *
     * Ability tersendiri, bukan `update`, karena `update` menuntut satu model
     * sebagai argumen - sedangkan operasi ini tidak memegang satu ruang pun
     * saat haknya diperiksa. Memanggil `update` dengan nama kelas akan gagal
     * dengan "too few arguments", bukan dengan "tidak berhak", dan galat yang
     * menyesatkan itu lebih buruk daripada satu baris tambahan di sini.
     *
     * Kapasitas menentukan berapa peserta yang boleh ditempatkan di sebuah
     * ruang, sehingga ia menyentuh perencanaan hari ujian - dan itu urusan
     * Admin, bukan pengawas yang hanya memakai tautannya.
     */
    public function kapasitasMassal(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function cabutTautan(User $user, RoomEntryLink $link): bool
    {
        return $user->role->isAdmin();
    }
}
