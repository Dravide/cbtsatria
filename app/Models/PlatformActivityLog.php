<?php

namespace App\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'school_id',
    'action',
    'metadata',
    'ip_address',
    'created_at',
])]
class PlatformActivityLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function aktor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<School, $this> */
    public function sekolah(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    /** @param array<string, mixed> $metadata */
    public static function catat(
        string $action,
        ?User $aktor = null,
        ?School $sekolah = null,
        array $metadata = [],
        ?string $ip = null,
    ): self {
        return self::query()->create([
            'user_id' => $aktor?->id,
            'school_id' => $sekolah?->id,
            'action' => $action,
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => $ip ?? request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
