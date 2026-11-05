<?php

namespace App\Domain\Academic\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kelas - mis. "X-1", "XI TKJ 2".
 *
 * Namanya SchoolClass, bukan Class: `class` adalah kata kunci PHP dan
 * mendeklarasikan `class Class` adalah galat parse. Namanya berbeda, tabelnya
 * tetap `classes` sesuai PRD §16.3.
 *
 * Kelas terikat pada satu tahun ajaran. Karena itu "X-1" boleh muncul lagi
 * tahun depan tanpa dianggap duplikat - keunikannya per tahun ajaran, dan
 * itulah yang membuat promise di wireframe plate 07 bisa ditepati:
 * "berganti TA berarti berganti daftar kelas, bukan menyaring daftar yang sama."
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'academic_year_id',
    'name',
    'grade_level',
    'homeroom_teacher_id',
    'status',
])]
class SchoolClass extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'classes';

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Wali kelas.
     *
     * Menunjuk users.id, bukan teacher_profiles - lihat catatan di
     * TeacherProfile. Kolomnya nullable karena kelas tanpa wali adalah
     * keadaan yang sah di awal tahun ajaran.
     *
     * @return BelongsTo<User, $this>
     */
    public function homeroomTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'homeroom_teacher_id');
    }

    /** @return HasMany<ClassEnrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(ClassEnrollment::class, 'class_id');
    }

    /** @return HasMany<TeachingAssignment, $this> */
    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(TeachingAssignment::class, 'class_id');
    }
}
