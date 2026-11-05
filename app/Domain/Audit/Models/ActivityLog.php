<?php

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Policies\ActivityLogPolicy;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan aktivitas. Append-only.
 *
 * UPDATED_AT = null karena tabelnya tidak punya kolom updated_at: sebuah
 * catatan tidak pernah berubah setelah ditulis. created_at ditulis manual
 * oleh ActivityLogger.
 */
#[ScopedBy(SchoolScope::class)]
#[UsePolicy(ActivityLogPolicy::class)]
#[Fillable([
    'school_id',
    'user_id',
    'action',
    'entity_type',
    'entity_id',
    'metadata_json',
    'ip_address',
    'created_at',
])]
class ActivityLog extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Aktor aksi ini.
     *
     * Nullable secara semantik: aksi otomatis - kunci ujian karena ambang
     * pelanggaran terlampaui, pemangkasan terjadwal - tidak punya pelaku
     * orang, dan memaksakan satu berarti mengarang aktor yang tidak ada.
     * Antarmuka menampilkannya sebagai "Sistem", dan itu memang jawabannya,
     * bukan nilai kosong yang disembunyikan.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
