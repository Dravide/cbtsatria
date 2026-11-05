<?php

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Atribut tambahan seorang guru.
 *
 * Profil BUKAN tempat menyimpan identitas akun. Nama, username, dan status
 * aktif tetap di users - di sini hanya yang tidak dimiliki peran lain.
 *
 * Relasi menunjuk users.id, bukan teacher_profiles.user_id dari sisi lain:
 * PRD §17 butir 2 menegaskan satu user punya satu profil sesuai perannya,
 * jadi teacher_id / student_id / supervisor_id di seluruh sistem menunjuk
 * users, bukan tabel profil.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'user_id', 'nip', 'gender', 'address'])]
class TeacherProfile extends Model
{
    use BelongsToSchool;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
