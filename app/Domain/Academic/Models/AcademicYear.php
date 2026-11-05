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
 * Tahun ajaran - mis. "2026/2027".
 *
 * ---------------------------------------------------------------------
 * "aktif" di sini TIDAK sama dengan "berjalan" di event
 * ---------------------------------------------------------------------
 * Wireframe plate 10 menandaskan pemisahan kosakata ini: status event memakai
 * "berjalan", bukan "aktif", justru karena "aktif" sudah dipakai tahun ajaran
 * dan semester. Kalau keduanya memakai kata yang sama, "event aktif" menjadi
 * ambigu di percakapan sehari-hari - dan ambiguitas itu berakhir pada Admin
 * yang membuka tahun ajaran keliru.
 *
 * ---------------------------------------------------------------------
 * Hanya satu yang aktif, dan itu tidak dijaga di sini
 * ---------------------------------------------------------------------
 * is_active adalah bendera, bukan aturan. Aturannya ("hanya satu, dan
 * mengaktifkan yang baru berarti menonaktifkan yang lama") hidup di
 * AcademicYearService, di dalam satu transaksi. Menaruhnya di model berarti
 * aturan itu hanya berlaku bila semua orang memakai model - dan seeder,
 * importer, serta console command tidak selalu lewat sana.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'name', 'start_date', 'end_date', 'is_active'])]
class AcademicYear extends Model
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

    /** @return HasMany<Semester, $this> */
    public function semesters(): HasMany
    {
        return $this->hasMany(Semester::class);
    }

    /** @return HasMany<SchoolClass, $this> */
    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }
}
