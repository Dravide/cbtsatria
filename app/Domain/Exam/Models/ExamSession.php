<?php

namespace App\Domain\Exam\Models;

use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu gelombang pelaksanaan ujian - PRD §9.3.
 *
 * ---------------------------------------------------------------------
 * Tiga waktu, dan masing-masing menjawab pertanyaan berbeda
 * ---------------------------------------------------------------------
 * PRD §9.3 menyebut "waktu pembukaan akses", "batas akhir memulai", dan
 * "batas akhir mutlak". Ketiganya mudah disatukan menjadi satu, dan
 * menyatukannya menghilangkan tepat perbedaan yang membuat ujian bisa
 * dijalankan:
 *
 *   access_opens_at   - kapan siswa boleh masuk.
 *   access_closes_at  - kapan orang terakhir boleh MULAI.
 *   hard_end_at       - kapan ujian benar-benar berhenti, tanpa kecuali.
 *
 * Yang kedua itulah kebijakan keterlambatan (PRD §24 #7). Sesi 07:30-09:00
 * dengan hard_end_at 09:30 berarti: siswa yang datang 08:45 masih boleh
 * mulai dan mengerjakan sampai 09:30, bukan sampai 60 menit penuh. Tanpa
 * waktu kedua, pilihan yang tersisa hanya dua-duanya salah - tolak yang
 * terlambat, atau biarkan mereka mengerjakan melewati jam ruangnya dipakai.
 *
 * ---------------------------------------------------------------------
 * hard_end_at TIDAK dihitung dari durasi
 * ---------------------------------------------------------------------
 * Ia diketik Admin, bukan diturunkan. Alasannya: ruang yang dipakai sesi
 * berikutnya adalah batas yang nyata, dan batas itu ditentukan gedung,
 * bukan oleh durasi ujian yang diketik di form.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_id',
    'session_number',
    'access_opens_at',
    'access_closes_at',
    'hard_end_at',
    'duration_minutes',
    'status',
])]
class ExamSession extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'exam_sessions';

    protected function casts(): array
    {
        return [
            'session_number' => 'integer',
            'access_opens_at' => 'datetime',
            'access_closes_at' => 'datetime',
            'hard_end_at' => 'datetime',
            'duration_minutes' => 'integer',
            'status' => StatusSesi::class,
        ];
    }

    /** @return BelongsTo<Exam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /** @return HasMany<ExamSessionRoom, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(ExamSessionRoom::class);
    }

    /** @return HasMany<ExamParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ExamParticipant::class);
    }

    /** @return HasMany<ExamToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(ExamToken::class);
    }

    /**
     * Token yang masih berlaku. Null bila belum diterbitkan atau sudah
     * dicabut - dan kedua keadaan itu memang berarti "tidak ada token",
     * bukan "ada token yang salah".
     */
    public function tokenAktif(): ?ExamToken
    {
        return $this->tokens()
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Label sesi seperti yang ditampilkan plate 16 dan 18: `S-01`.
     */
    public function label(): string
    {
        return 'S-'.$this->session_number;
    }

    /**
     * Ruang sesi ini yang belum punya satu pengawas pun.
     *
     * Ditaruh di model, bukan di layanan, karena pertanyaan "ruang mana yang
     * belum bisa dibuka" muncul di tiga tempat yang berbeda - SesiService
     * menolak membukanya, TokenSesi menolak menerbitkannya, dan plate 17
     * menghitungnya untuk KPI - dan jawabannya harus satu.
     *
     * @return Collection<int, ExamSessionRoom>
     */
    public function ruangTanpaPengawas(): Collection
    {
        return $this->rooms()
            ->whereDoesntHave('supervisors')
            ->with('eventRoom.room:id,code')
            ->get();
    }

    /**
     * Durasi efektif: durasi sesi bila ada, durasi ujian bila tidak.
     *
     * Null berarti "ikut ujian", bukan "nol menit" - pembedaan yang sama
     * dengan capacity_override di event_rooms dan di exam_session_rooms.
     */
    public function durasiMenit(): int
    {
        return $this->duration_minutes ?? (int) $this->exam?->duration_minutes ?? 0;
    }
}
