<?php

namespace App\Domain\Audit\Policies;

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;

/**
 * Log aktivitas: hanya baca, selamanya.
 *
 * Tidak ada method create, update, atau delete. Penulisan hanya lewat
 * ActivityLogger, dan penghapusan tidak pernah ditawarkan - wireframe
 * plate 24: "Laman ini tidak punya tombol hapus, dan itu disengaja."
 *
 * Kalau kelak ada kebutuhan retensi (mis. pemangkasan otomatis setelah
 * beberapa tahun), itu perintah terjadwal dengan kebijakan yang ditulis
 * eksplisit - bukan tombol di antarmuka operasional.
 */
class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function view(User $user, ActivityLog $log): bool
    {
        return $user->role->isAdmin();
    }

    public function export(User $user): bool
    {
        return $user->role === Role::Admin;
    }
}
