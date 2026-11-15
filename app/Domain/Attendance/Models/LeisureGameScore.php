<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu kiriman skor permainan waktu luang - wireframe-waktu-luang.html.
 *
 * ---------------------------------------------------------------------
 * Baris yang DITOLAK tetap tersimpan
 * ---------------------------------------------------------------------
 * `rejected_reason` null berarti diterima. Kiriman yang tidak masuk akal
 * ditulis dengan alasannya, bukan dibuang diam-diam: "'9.400 dalam 12 detik'
 * ditolak" adalah kalimat yang bisa ditunjukkan kepada Admin yang bertanya
 * mengapa seorang anak tidak ada di papan, dan baris yang tidak pernah
 * ditulis tidak bisa menjawab apa pun.
 *
 * Penyaringnya satu dan dipakai di mana-mana: `rejected_reason is null`.
 *
 * ---------------------------------------------------------------------
 * Model ini tidak punya method yang menghitung skor
 * ---------------------------------------------------------------------
 * Hitung ulangnya ada di `PenjagaSkorLuar`, dan itu memang bukan urusan
 * model. Model yang bisa menghitung skornya sendiri akan dipanggil dari
 * tempat lain yang tidak memeriksa durasi - dan pemeriksaan itulah satu-
 * satunya alasan kelas ini ada.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'student_id',
    'class_id',
    'exam_participant_id',
    'distance',
    'duration_ms',
    'score',
    'rejected_reason',
    'played_at',
])]
class LeisureGameScore extends Model
{
    use BelongsToSchool;

    protected $table = 'leisure_game_scores';

    /** Kiriman wajar: tidak ada satu pun alasan penolakan padanya. */
    public const DITERIMA = null;

    /** Durasi main di bawah ini mustahil untuk jarak berapa pun. */
    public const TOLAK_TERLALU_CEPAT = 'terlalu_cepat';

    /** Perbandingan jarak terhadap durasi melampaui kecepatan lari tertinggi. */
    public const TOLAK_TIDAK_SESUAI = 'tidak_sesuai';

    protected function casts(): array
    {
        return [
            'distance' => 'integer',
            'duration_ms' => 'integer',
            'score' => 'integer',
            'played_at' => 'datetime',
        ];
    }

    public function diterima(): bool
    {
        return $this->rejected_reason === self::DITERIMA;
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function kelas(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
