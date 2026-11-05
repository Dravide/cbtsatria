<?php

namespace App\Domain\Event\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aktivitas di dalam event - PRD §16.4.
 *
 * Satu event bisa berisi beberapa kegiatan yang waktunya berbeda: teori,
 * praktik, remedial, try out. Ini yang membuat jadwal tidak bisa ditaruh
 * langsung di event: "UAS Ganjil" berlangsung dua minggu, sementara ujian
 * Matematika hanya dua jam di salah satu hari itu.
 *
 * Fase 3 hanya membangun wadahnya. Pengisiannya lewat UI baru bermakna di
 * Fase 5 bersama jadwal dan sesi - sampai saat itu, aktivitas adalah tempat
 * yang sudah disiapkan, bukan fitur setengah jadi.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'event_id',
    'code',
    'name',
    'activity_type',
    'description',
    'start_at',
    'end_at',
    'status',
])]
class EventActivity extends Model
{
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SchoolEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SchoolEvent::class, 'event_id');
    }
}
