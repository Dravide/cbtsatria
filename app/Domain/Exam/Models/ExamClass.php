<?php

namespace App\Domain\Exam\Models;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kelas sasaran satu ujian - PRD §9.2 ("target kelas").
 *
 * ---------------------------------------------------------------------
 * Mengapa tabel sendiri, bukan kolom JSON di exams
 * ---------------------------------------------------------------------
 * Daftar kelas sasaran dibaca oleh tiga hal yang berbeda: penarikan peserta
 * ujian, laporan per kelas (PRD §14), dan pemeriksaan benturan peserta.
 * Ketiganya menjalankan query, bukan sekadar menampilkan daftarnya - dan
 * kolom JSON tidak bisa di-index maupun di-join.
 *
 * Ia juga bagian dari composite FK: kelas yang disasarkan harus kelas di
 * sekolah yang sama, dan itu dijaga basis data, bukan oleh pemeriksaan.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'exam_id', 'class_id'])]
class ExamClass extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'exam_classes';

    /** @return BelongsTo<Exam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function kelas(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
