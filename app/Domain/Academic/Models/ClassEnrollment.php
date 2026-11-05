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
 * Keanggotaan siswa di satu kelas.
 *
 * school_id di sini tampak berlebihan - ia bisa diturunkan dari class_id.
 * Ia ada karena composite FK membutuhkannya, dan justru itulah gunanya:
 * baris ini menyatakan "siswa X di kelas Y" dan MySQL menolak pernyataan itu
 * bila X dan Y tidak berasal dari sekolah yang sama. Tidak ada lapisan
 * aplikasi yang perlu memeriksanya.
 *
 * status dipisahkan dari users.is_active dengan sengaja. Siswa yang pindah
 * kelas di tengah tahun tetap aktif sebagai akun, tetapi keanggotaannya di
 * kelas lama berhenti - dua keadaan berbeda, dua kolom berbeda.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'class_id',
    'student_id',
    'academic_year_id',
    'enrollment_date',
    'status',
])]
class ClassEnrollment extends Model
{
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return ['enrollment_date' => 'date'];
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function kelas(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
