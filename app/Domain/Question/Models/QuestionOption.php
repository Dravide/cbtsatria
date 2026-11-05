<?php

namespace App\Domain\Question\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu pilihan jawaban.
 *
 * ---------------------------------------------------------------------
 * `is_correct` ada di sini DAN di answer_key_json - duplikasi yang disengaja
 * ---------------------------------------------------------------------
 * Keduanya menyimpan fakta yang sama, dan itu memang harga yang dibayar.
 * Alasannya: `answer_key_json` adalah bentuk yang DIBEKUKAN ke snapshot,
 * sementara `is_correct` adalah bentuk yang bisa DIKUERI. "Tampilkan soal
 * yang kuncinya kosong" adalah pertanyaan yang tidak bisa dijawab JSON tanpa
 * memindai dan mengurai setiap baris.
 *
 * Yang menjaga keduanya tetap sama adalah BankSoalService: satu penulis, satu
 * transaksi. Tidak ada tempat lain di aplikasi ini yang boleh menulis salah
 * satunya sendirian - dan kalau itu terjadi, gejalanya bukan galat, melainkan
 * soal yang tampil dengan kunci yang berbeda dari yang dinilai mesin.
 *
 * ---------------------------------------------------------------------
 * `option_key` adalah huruf pendek, bukan id
 * ---------------------------------------------------------------------
 * "a", "b", "c" - dan itulah yang dipakai kunci jawaban, termasuk di kunci
 * yang tersimpan sebagai JSON. Memakai id berarti kunci jawaban bocor
 * sebagai nomor basis data ke setiap berkas ekspor, dan soal yang sama di
 * dua sekolah punya kunci yang tidak bisa dibandingkan.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'question_id', 'option_key', 'content_json', 'is_correct', 'position'])]
class QuestionOption extends Model
{
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'content_json' => 'array',
            'is_correct' => 'boolean',
        ];
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** @return HasMany<QuestionMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(QuestionMedia::class, 'option_id');
    }
}
