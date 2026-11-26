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

    /**
     * Berapa kursi ruang ini yang punya PC - wireframe-ruang-lab.html L1.
     *
     * =====================================================================
     * Lab campuran, dan mengapa TIDAK butuh kolom baru
     * =====================================================================
     * Lab komputer bukan ruang yang seluruhnya ber-PC. Di lab yang sama ada
     * anak yang memakai PC sekolah, ada yang membawa HP sendiri, dan ada yang
     * membawa HP tetapi tidak bisa memakainya (kuota habis, baterai lowbat,
     * kamera rusak). Yang membedakan ketiganya bukan ruangnya, melainkan
     * KURSINYA.
     *
     * Angka itu sudah ada: `rooms.device_count`. Ruang campuran adalah
     * `0 < device_count < capacity`, ruang BYOD adalah nol, dan ruang yang
     * seluruhnya ber-PC adalah sama dengan kapasitasnya. Tidak ada kolom
     * tambahan yang perlu ditulis hanya untuk menyatakan "campuran".
     *
     * ---------------------------------------------------------------------
     * Konvensi kursi: 1..device_count yang ber-PC
     * ---------------------------------------------------------------------
     * Lab yang PC-nya berderet dari depan cukup dengan konvensi ini. Lab yang
     * PC-nya berselang-seling butuh peta kursi sendiri, dan itu kelak daftar
     * nomor di `exam_session_rooms` - bukan kolom di `rooms`: susunan meja
     * bisa berubah antar sesi ketika PC dipindah. Selama peta itu belum ada,
     * konvensi di sini yang berlaku, dan ia berlaku untuk kedua bentuk.
     *
     * Kapasitas efektif tetap batas atasnya: menimpa kapasitas ruang menjadi
     * lebih kecil daripada jumlah PC berarti kursi ber-PC-nya ikut berkurang,
     * karena kursi yang tidak ada tidak bisa berisi PC.
     */
    public function kursiBerPerangkat(): int
    {
        $pc = (int) ($this->room?->device_count ?? 0);

        return max(0, min($pc, $this->kapasitasEfektif()));
    }

    /** Apakah ruang ini punya setidaknya satu kursi ber-PC. */
    public function adaPerangkat(): bool
    {
        return $this->kursiBerPerangkat() > 0;
    }

    /** Apakah ruang ini ditandai khusus perangkat (non-acak kelas reguler). */
    public function isKhususPerangkat(): bool
    {
        return (bool) ($this->room?->khusus_perangkat ?? false);
    }
}
