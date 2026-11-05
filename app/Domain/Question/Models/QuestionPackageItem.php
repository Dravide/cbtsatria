<?php

namespace App\Domain\Question\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris daftar isi sebuah paket.
 *
 * ---------------------------------------------------------------------
 * Jangan tertukar dengan QuestionItem
 * ---------------------------------------------------------------------
 *   QuestionPackageItem = butir di dalam satu PAKET. Daftar isi.
 *   QuestionItem        = butir di dalam satu SOAL. Isi soal.
 *
 * ---------------------------------------------------------------------
 * `points_override` NULL berarti "pakai bobot soalnya" - dan itu bukan nol
 * ---------------------------------------------------------------------
 * Nol berarti "soal ini tidak berbobot di paket ini", dan itu permintaan yang
 * sah: soal bonus yang dikerjakan tetapi tidak dihitung. Menyatukan keduanya
 * dengan default 0 berarti setiap butir yang tidak disetel Guru diam-diam
 * tidak bernilai - ujian yang seluruh soalnya bernilai nol, dan nilainya baru
 * terlihat setelah siswa selesai mengerjakan.
 *
 * Kolomnya juga menentukan apa yang dibekukan: `exam_questions.bobot` berisi
 * hasil akhirnya (override bila ada, bobot soal bila tidak), bukan rumusnya.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'question_package_id', 'question_id', 'points_override', 'position'])]
class QuestionPackageItem extends Model
{
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'points_override' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<QuestionPackage, $this> */
    public function paket(): BelongsTo
    {
        return $this->belongsTo(QuestionPackage::class, 'question_package_id');
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Bobot yang BERLAKU di paket ini.
     *
     * Diperhitungkan di sini, bukan di pemanggil: total bobot paket (yang
     * ditampilkan di plate 05 dan dipakai Guru untuk mengejar angka 100)
     * dihitung dengan menjumlahkan method ini per butir, dan dua tempat yang
     * menghitungnya sendiri-sendiri adalah dua angka yang akan berbeda.
     */
    public function bobotBerlaku(): float
    {
        if ($this->points_override !== null) {
            return (float) $this->points_override;
        }

        return (float) ($this->question?->scoring_config_json['bobot'] ?? 1);
    }
}
