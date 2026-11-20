<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sinyal darurat infrastruktur dari pengawas ruang ke teknisi/admin.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_session_room_id',
    'jenis',
    'catatan',
    'pelapor_id',
    'pelapor_nama',
    'status',
    'ditangani_oleh',
    'ditangani_at',
])]
class RoomSignal extends Model
{
    use BelongsToSchool;

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_DITANGANI = 'ditangani';

    public const JENIS = [
        'wifi_putus' => 'Wi-Fi / Jaringan Putus',
        'listrik_padam' => 'Listrik Padam',
        'soal_bermasalah' => 'Soal / Media Bermasalah',
        'lainnya' => 'Kendala Lainnya',
    ];

    protected $table = 'room_signals';

    protected function casts(): array
    {
        return [
            'ditangani_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExamSessionRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ExamSessionRoom::class, 'exam_session_room_id');
    }

    /** @return BelongsTo<User, $this> */
    public function pelapor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pelapor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function penangan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditangani_oleh');
    }

    public function labelJenis(): string
    {
        return self::JENIS[$this->jenis] ?? $this->jenis;
    }
}
