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
 * Satu kriteria rubrik penilaian soal uraian.
 *
 * ---------------------------------------------------------------------
 * Mengapa tabel tersendiri, bukan satu blok JSON di explanation_json
 * ---------------------------------------------------------------------
 * PRD §16.5 menamainya tabel tersendiri, dan itu tepat karena rubrik dibaca
 * BARIS DEMI BARIS oleh Guru saat mengoreksi (Fase 8), bukan sebagai satu
 * blok. Menyimpannya di JSON berarti laman koreksi harus mengurai JSON untuk
 * menampilkan daftar centang - dan laman koreksi adalah laman yang paling
 * sering dibuka Guru dengan 300 jawaban menunggu.
 *
 * Rubrik juga satu-satunya isi soal yang punya barisnya sendiri di layar
 * koreksi: Guru mencentang per kriteria, bukan memberi satu angka.
 *
 * ---------------------------------------------------------------------
 * Soal tanpa rubrik tidak bisa dikoreksi dua orang dengan hasil yang sama
 * ---------------------------------------------------------------------
 * Itulah kalimat yang dipakai SkemaJenisSoal untuk menolak uraian tanpa
 * rubrik, dan `max_score` yang wajib lebih dari nol adalah konsekuensi yang
 * sama: kriteria bernilai nol tidak bisa dicentang maupun dilewati, ia hanya
 * memenuhi layar.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'question_id', 'criterion', 'description', 'max_score', 'position'])]
class EssayRubricCriterion extends Model
{
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'max_score' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
