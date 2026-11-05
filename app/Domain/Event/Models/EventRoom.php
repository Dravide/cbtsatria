<?php

namespace App\Domain\Event\Models;

use App\Domain\Facility\Models\Room;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ruang yang dialokasikan ke event - PRD §6.9.
 *
 * ---------------------------------------------------------------------
 * Kapasitas boleh ditimpa, dan null berarti "pakai kapasitas master"
 * ---------------------------------------------------------------------
 * Meja ujian yang diberi jarak memuat lebih sedikit orang daripada kelas
 * biasa; ruang yang dipakai untuk ujian praktik memuat lebih sedikit lagi.
 * Karena itu alokasi boleh membawa kapasitasnya sendiri.
 *
 * Yang penting adalah membedakan null dari nol, dan itu satu-satunya alasan
 * kolomnya nullable: null berarti "pakai kapasitas ruang master", nol berarti
 * "ruang ini tidak memuat siapa pun". Menyatukan keduanya - mis. dengan
 * default 0 - berarti setiap ruang yang tidak ditimpa terbaca sebagai ruang
 * tanpa kapasitas, dan pemeriksaan kesiapan akan menolak setiap event.
 *
 * ---------------------------------------------------------------------
 * Pemeriksaan benturan bukan di sini
 * ---------------------------------------------------------------------
 * PRD §6.9 meminta sistem memeriksa "benturan penggunaan ruang pada waktu
 * yang sama". Waktu itu milik sesi, dan sesi baru ada di Fase 5. Fase 3
 * memeriksa kapasitas - jumlah peserta yang ditugaskan ke ruang versus
 * kapasitas efektif - karena itulah bagian yang datanya sudah ada. Benturan
 * jadwal dilaporkan jujur sebagai "belum diperiksa", bukan dianggap lulus.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'event_id', 'room_id', 'capacity_override', 'status'])]
class EventRoom extends Model
{
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'capacity_override' => 'integer',
        ];
    }

    /** @return BelongsTo<SchoolEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SchoolEvent::class, 'event_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Kapasitas efektif: timpaan bila ada, kapasitas master bila tidak.
     *
     * Ditulis di model karena pertanyaan "berapa orang yang muat di sini"
     * muncul di lebih dari satu tempat (readiness gate, laman jadwal di Fase
     * 5, pembagian peserta ke ruang), dan jawabannya harus satu.
     */
    public function kapasitasEfektif(): int
    {
        return $this->capacity_override ?? (int) $this->room?->capacity ?? 0;
    }
}
