<?php

namespace App\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'title',
    'body',
    'nada',
    'target_roles',
    'expires_at',
    'created_by',
])]
class PlatformAnnouncement extends Model
{
    public const NADA_INFO = 'info';

    public const NADA_PENTING = 'penting';

    protected function casts(): array
    {
        return [
            'target_roles' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function penulis(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<self> $query */
    public function scopeBerlaku(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
