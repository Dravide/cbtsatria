<?php

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Atribut tambahan seorang siswa.
 *
 * nis adalah nomor induk sekolah - yang dipakai sehari-hari, dan yang
 * diturunkan menjadi username saat impor. nisn adalah nomor nasional, yang
 * tidak setiap sekolah punya. Keduanya unik per sekolah, bukan global:
 * dua sekolah berbeda boleh punya NIS yang kebetulan sama.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'user_id', 'nis', 'nisn', 'gender', 'address'])]
class StudentProfile extends Model
{
    use BelongsToSchool;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
