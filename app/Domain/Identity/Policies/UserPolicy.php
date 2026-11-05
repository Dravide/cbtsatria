<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;

/**
 * Siapa yang boleh mengelola akun.
 *
 * Perhatikan apa yang TIDAK diperiksa di sini: school_id.
 *
 * Itu disengaja, dan itu aturan yang harus bertahan. SchoolScope sudah
 * memastikan User yang sampai ke policy ini selalu berasal dari sekolah
 * pemanggilnya; baris sekolah lain tidak pernah ada untuknya. Policy yang
 * menambahkan $user->school_id === $model->school_id justru merusak
 * jaminan itu: ia hanya tercapai bila barisnya ADA, dan keberadaannya
 * terbukti lewat 403 - padahal yang benar adalah 404, seolah barisnya
 * memang tidak pernah ada.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->role->isAdmin() || $user->is($model);
    }

    public function create(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Tidak ada yang boleh menghapus akun lewat antarmuka.
     *
     * Akun menonaktifkan, bukan menghapus - riwayat ujian, presensi, dan
     * jawaban menunjuk ke sana, dan menghapusnya berarti memutus riwayat
     * yang justru harus tetap bisa ditelusuri.
     */
    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function resetPassword(User $user, User $model): bool
    {
        return $user->role === Role::Admin;
    }

    public function import(User $user): bool
    {
        return $user->role->isAdmin();
    }
}
