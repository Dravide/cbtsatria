<?php

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Atribut tambahan seorang pengawas.
 *
 * staff_code adalah nomor pegawai internal sekolah. Ia tidak dipakai untuk
 * login dan tidak sama dengan kode pengawas ruang - kode ruang diterbitkan
 * per ruang per event, dan itu hidup di room_entry_links, bukan di sini.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'user_id', 'staff_code', 'address'])]
class SupervisorProfile extends Model
{
    use BelongsToSchool;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
