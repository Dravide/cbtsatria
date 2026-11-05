<?php

namespace App\Domain\Tenancy\Policies;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * Profil sekolah sendiri.
 *
 * Policy ini melayani School - satu-satunya model tanpa SchoolScope, karena
 * ia tabel induknya. Di sinilah school_id memang harus diperiksa, dan ini
 * satu-satunya tempat yang benar untuk melakukannya: tidak ada scope yang
 * menjaga School, jadi Policy-lah lapisannya.
 */
class SchoolPolicy
{
    public function view(User $user, School $school): bool
    {
        return $user->role->isAdmin() && $user->school_id === $school->id;
    }

    public function update(User $user, School $school): bool
    {
        return $user->role->isAdmin() && $user->school_id === $school->id;
    }

    /**
     * NPSN tidak pernah berubah lewat aplikasi.
     *
     * Wireframe plate 04 menguncinya di UI dengan alasan yang sama: NPSN
     * adalah identitas tenant yang dipakai sistem di belakang layar, dan
     * mengubahnya berarti memindahkan sekolah ke identitas lain - operasi
     * di luar aplikasi. Dinyatakan sebagai policy, bukan hanya atribut
     * disabled pada input, supaya tidak bisa dilewati dengan mengirim
     * request langsung.
     */
    public function ubahNpsn(User $user, School $school): bool
    {
        return false;
    }

    public function kelolaPengaturan(User $user, School $school): bool
    {
        return $user->role === Role::Admin && $user->school_id === $school->id;
    }
}
