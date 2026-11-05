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

/**
 * Penugasan mengajar: guru A mengajar mapel B di kelas C.
 *
 * ---------------------------------------------------------------------
 * Mengapa baris ini yang membatasi bank soal Guru
 * ---------------------------------------------------------------------
 * Wireframe plate 08 menaruh penugasan mengajar di laman Mata pelajaran,
 * bukan di laman Guru, dan alasannya bukan tata letak: inilah tabel yang
 * menentukan Guru mana boleh menyusun soal untuk mapel dan kelas mana. Admin
 * perlu melihat mapel dan penugasannya berdampingan, karena satu baris di
 * sini adalah satu izin.
 *
 * Konsekuensinya di Fase 4: Guru menyusun soal untuk (mapel, kelas) yang
 * muncul di sini, dan tidak untuk yang lain. Tabel ini adalah sumber
 * kebenarannya, bukan daftar di laman Guru.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'academic_year_id', 'teacher_id', 'subject_id', 'class_id'])]
class TeachingAssignment extends Model
{
    use BelongsToSchool, HasFactory;

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
}
