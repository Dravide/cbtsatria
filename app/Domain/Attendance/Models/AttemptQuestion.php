<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Urutan soal untuk SATU attempt - PRD §16.8, plate 22.
 *
 * ---------------------------------------------------------------------
 * Mengapa urutannya melekat pada attempt, bukan pada snapshot soal
 * ---------------------------------------------------------------------
 * `exams.shuffle_questions` dan `shuffle_options` (Fase 5) hanya punya arti
 * bila dua peserta yang sama-sama mengacak tidak menerima urutan yang sama.
 * Menyimpan urutan di `exam_questions` berarti menyimpan SATU urutan untuk
 * semua orang - yaitu tidak mengacak.
 *
 * `display_order` karena itu berbeda dari `exam_questions.position`: posisi
 * adalah urutan paket saat dibekukan, dan kolom ini adalah urutan yang
 * dilihat satu peserta tertentu. Nomor soal yang disebut pengawas di ruang
 * ujian harus nomor YANG DILIHAT SISWA - dan hanya kolom ini yang bisa
 * menjawabnya.
 *
 * ---------------------------------------------------------------------
 * option_order_json dan item_order_json bukan salinan
 * ---------------------------------------------------------------------
 * Keduanya mencatat urutan yang benar-benar tampil. Tanpa itu, jawaban
 * `{"opsi":["a"]}` tidak bisa ditafsirkan di kemudian hari: "a" yang mana,
 * posisi pertama dari empat yang diacak, atau kunci `a` di snapshot?
 * Menyimpan urutannya membuat jawaban lama tetap terbaca walau snapshot
 * berubah bentuk.
 *
 * Null berarti "sebagaimana di snapshot", dan itu memang keadaan untuk
 * ujian yang tidak mengacak pilihan.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_attempt_id',
    'exam_question_id',
    'display_order',
    'option_order_json',
    'item_order_json',
])]
class AttemptQuestion extends Model
{
    use BelongsToSchool;

    protected $table = 'attempt_questions';

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'option_order_json' => 'array',
            'item_order_json' => 'array',
        ];
    }

    /** @return BelongsTo<ExamAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    /** @return BelongsTo<ExamQuestion, $this> */
    public function soal(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'exam_question_id');
    }

    /** @return HasOne<StudentAnswer, $this> */
    public function jawaban(): HasOne
    {
        return $this->hasOne(StudentAnswer::class, 'attempt_question_id');
    }
}
