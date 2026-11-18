<?php

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Atribut tambahan seorang guru.
 *
 * Profil BUKAN tempat menyimpan identitas akun. Nama, username, dan status
 * aktif tetap di users - di sini hanya yang tidak dimiliki peran lain.
 *
 * Relasi menunjuk users.id, bukan teacher_profiles.user_id dari sisi lain:
 * PRD §17 butir 2 menegaskan satu user punya satu profil sesuai perannya,
 * jadi teacher_id / student_id / supervisor_id di seluruh sistem menunjuk
 * users, bukan tabel profil.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'user_id', 'nip', 'gender', 'address', 'ai_limit', 'ai_used'])]
class TeacherProfile extends Model
{
    use BelongsToSchool;

    /** Batas bawaan pembuatan soal AI per guru. */
    public const KUOTA_BAWAAN = 20;

    protected function casts(): array
    {
        return [
            'ai_limit' => 'integer',
            'ai_used' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Ambil atau buat baris profil guru untuk melacak kuota generate AI.
     */
    public static function untukGuru(User $guru): self
    {
        return static::firstOrCreate(
            ['school_id' => $guru->school_id, 'user_id' => $guru->id],
            ['ai_limit' => self::KUOTA_BAWAAN, 'ai_used' => 0],
        );
    }

    /**
     * @return array{batas: int, terpakai: int, sisa: int}
     */
    public function ringkasKuotaAi(): array
    {
        $batas = max(0, (int) ($this->ai_limit ?? self::KUOTA_BAWAAN));
        $terpakai = max(0, (int) ($this->ai_used ?? 0));

        return [
            'batas' => $batas,
            'terpakai' => $terpakai,
            'sisa' => max(0, $batas - $terpakai),
        ];
    }
}
