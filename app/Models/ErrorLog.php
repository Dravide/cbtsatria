<?php

namespace App\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan galat aplikasi terpusat dengan kode referensi per portal (ER-ADM-001, ER-GR-001, dst.).
 */
#[Fillable([
    'code',
    'portal',
    'fingerprint',
    'status_code',
    'exception_class',
    'message',
    'file',
    'line',
    'trace',
    'url',
    'method',
    'route_name',
    'school_id',
    'user_id',
    'user_role',
    'ip_address',
    'user_agent',
    'context',
    'occurrences',
    'first_seen_at',
    'last_seen_at',
    'resolved_at',
    'resolved_by',
    'resolution_note',
])]
class ErrorLog extends Model
{
    public const PORTAL_ADM = 'ADM';
    public const PORTAL_GR = 'GR';
    public const PORTAL_PGW = 'PGW';
    public const PORTAL_SSW = 'SSW';
    public const PORTAL_SUP = 'SUP';
    public const PORTAL_PUB = 'PUB';
    public const PORTAL_SYS = 'SYS';

    /** @var array<string, string> */
    public const LABEL_PORTAL = [
        self::PORTAL_ADM => 'Admin',
        self::PORTAL_GR => 'Guru',
        self::PORTAL_PGW => 'Pengawas',
        self::PORTAL_SSW => 'Siswa',
        self::PORTAL_SUP => 'Super Admin',
        self::PORTAL_PUB => 'Publik',
        self::PORTAL_SYS => 'Sistem',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'status_code' => 'integer',
            'line' => 'integer',
            'occurrences' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<School, $this> */
    public function sekolah(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    /** @return BelongsTo<User, $this> */
    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function penyelesai(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
