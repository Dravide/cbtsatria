<?php

namespace App\Domain\Exam\Models;

use App\Domain\Event\Models\EventRoom;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ruang yang dipakai satu sesi - PRD §9.4.
 *
 * ---------------------------------------------------------------------
 * Menunjuk event_rooms, bukan rooms
 * ---------------------------------------------------------------------
 * Ruang master adalah ruang sekolah; ruang yang dipakai satu sesi adalah
 * ruang yang SUDAH dialokasikan ke event. Menunjuk `rooms` langsung akan
 * membuat sesi bisa memakai ruang yang tidak pernah dialokasikan ke event
 * itu - dan pemeriksaan "kesesuaian event" (PRD §9.4) yang justru diminta
 * menjadi mustahil, karena datanya tidak pernah bertemu.
 *
 * Lewat event_rooms, ketidaksesuaian itu menjadi mustahil di lapisan basis
 * data: composite FK membuat baris yang menunjuk ruang di luar event tidak
 * bisa dibuat sama sekali.
 *
 * ---------------------------------------------------------------------
 * capacity_override nullable, dan null berarti "pakai alokasi event"
 * ---------------------------------------------------------------------
 * Pola yang sudah tiga kali dipakai: event_rooms.capacity_override,
 * exam_sessions.duration_minutes, dan sekarang ini. Pembedaan null dari nol
 * adalah satu-satunya alasan kolomnya nullable - nol berarti "ruang ini
 * tidak memuat siapa pun", yang merupakan pernyataan yang sah dan berbeda.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_session_id',
    'event_room_id',
    'capacity_override',
])]
class ExamSessionRoom extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'exam_session_rooms';

    protected function casts(): array
    {
        return [
            'capacity_override' => 'integer',
        ];
    }

    /** @return BelongsTo<ExamSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    /** @return BelongsTo<EventRoom, $this> */
    public function eventRoom(): BelongsTo
    {
        return $this->belongsTo(EventRoom::class, 'event_room_id');
    }

    /** @return HasMany<ExamParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ExamParticipant::class, 'exam_session_room_id');
    }

    /** @return HasMany<SupervisionAssignment, $this> */
    public function supervisors(): HasMany
    {
        return $this->hasMany(SupervisionAssignment::class, 'exam_session_room_id');
    }

    /**
     * Kapasitas efektif: timpaan sesi bila ada, kapasitas alokasi event
     * bila tidak.
     *
     * Ditulis di model karena pertanyaan "berapa orang yang muat di sini"
     * muncul di tempat yang berbeda-beda (penyebaran peserta, pemeriksaan
     * konflik, readiness gate), dan jawabannya harus satu.
     */
    public function kapasitasEfektif(): int
    {
        return $this->capacity_override
            ?? (int) $this->eventRoom?->kapasitasEfektif()
            ?? 0;
    }
}
