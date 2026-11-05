<?php

namespace App\Domain\Event\Models;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penugasan guru di dalam event - PRD §6.8.
 *
 * ---------------------------------------------------------------------
 * Berbeda dari TeachingAssignment, dan bukan penggantinya
 * ---------------------------------------------------------------------
 * `teaching_assignments` (Fase 2) menyatakan siapa mengajar apa SEPANJANG
 * tahun ajaran. Tabel ini menyatakan siapa bertanggung jawab atas apa DI
 * DALAM satu event - "Guru hanya memperoleh akses ke tugas yang secara
 * eksplisit diberikan kepadanya" (PRD §6.8).
 *
 * Perbedaannya nyata: seorang guru bisa mengajar Fisika di X-1 sepanjang
 * tahun tetapi tidak ditugaskan menyusun soal Fisika untuk UAS kali ini.
 * Menggabungkan keduanya berarti setiap guru otomatis mendapat akses ke
 * setiap event untuk setiap mapelnya - dan itu justru yang PRD §6.8 tolak.
 *
 * Tabel ini yang jadi dasar pembatasan akses Guru sejak Fase 4: Guru melihat
 * mapel di sebuah event hanya bila barisnya ada di sini.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'event_id',
    'teacher_id',
    'subject_id',
    'class_id',
    'activity_id',
])]
class EventTeacherAssignment extends Model
{
    use BelongsToSchool, HasFactory;

    /** @return BelongsTo<SchoolEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SchoolEvent::class, 'event_id');
    }

    /** @return BelongsTo<User, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function kelas(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** @return BelongsTo<EventActivity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(EventActivity::class, 'activity_id');
    }
}
