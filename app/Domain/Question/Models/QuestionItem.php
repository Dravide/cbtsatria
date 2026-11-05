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
 * Butir JODOH/URUT milik SATU soal.
 *
 * ---------------------------------------------------------------------
 * Jangan tertukar dengan QuestionPackageItem
 * ---------------------------------------------------------------------
 * Namanya berdekatan dan bedanya bukan gaya penamaan:
 *
 *   QuestionItem        = butir di dalam satu SOAL. Isi soal.
 *   QuestionPackageItem = butir di dalam satu PAKET. Daftar isi.
 *
 * Yang pertama menjawab "pasangan mana yang benar"; yang kedua menjawab
 * "soal mana saja yang ada di ujian ini". Menyatukannya berarti satu tabel
 * yang separuh barisnya tidak berlaku untuk separuh pertanyaan, dan setiap
 * kueri di atasnya harus ingat menyaring.
 *
 * ---------------------------------------------------------------------
 * `item_group` pada soal mengurutkan memang kosong separuh
 * ---------------------------------------------------------------------
 * Soal menjodohkan punya dua sisi (kiri dan kanan); soal mengurutkan hanya
 * punya satu. Kolomnya tetap ada dengan default 'kiri', dan pada soal urut
 * seluruh barisnya memakai nilai yang sama - itu bukan data yang berbohong,
 * melainkan satu kelompok yang kebetulan hanya berjumlah satu.
 *
 * `correct_match_key` dan `correct_position` keduanya nullable karena satu
 * baris hanya memakai salah satunya. Yang tidak dipakai memang kosong, dan
 * mengisinya dengan nilai default berarti menyimpan jawaban yang tidak
 * pernah diperiksa.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'question_id',
    'item_group',
    'item_key',
    'content_json',
    'correct_match_key',
    'correct_position',
    'position',
])]
class QuestionItem extends Model
{
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'content_json' => 'array',
        ];
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
