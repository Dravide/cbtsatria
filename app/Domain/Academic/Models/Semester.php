<?php

namespace App\Domain\Academic\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Semester di dalam satu tahun ajaran.
 *
 * Ia selalu milik satu AcademicYear - tidak ada semester yang berdiri sendiri.
 * Karena itu academic_year_id tidak nullable dan ikut ke dalam unique composite:
 * "Ganjil" boleh ada di 2025/2026 dan lagi di 2026/2027, tetapi tidak dua kali
 * di tahun ajaran yang sama.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'academic_year_id', 'name', 'start_date', 'end_date', 'is_active'])]
class Semester extends Model
{
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
