<?php

namespace App\Domain\Academic\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mata pelajaran.
 *
 * `code` unik per sekolah - "MTK", "BIN", "IPA". Kode inilah yang dipakai
 * di paket soal, jadwal, dan laporan, jadi ia harus pendek dan stabil.
 *
 * grade_level nullable: sebagian mapel berlaku untuk semua tingkat.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'code', 'name', 'category', 'grade_level', 'status'])]
class Subject extends Model
{
    use BelongsToSchool, HasFactory;

    /** @return HasMany<TeachingAssignment, $this> */
    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(TeachingAssignment::class);
    }

    public function isAktif(): bool
    {
        return $this->status === 'aktif';
    }
}
