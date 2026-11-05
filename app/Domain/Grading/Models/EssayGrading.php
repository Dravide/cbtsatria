<?php

namespace App\Domain\Grading\Models;

use App\Domain\Attendance\Models\StudentAnswer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Koreksi satu jawaban uraian - PRD §13.3, wireframe plate 06.
 *
 * ---------------------------------------------------------------------
 * Satu baris per jawaban, dan `unique(student_answer_id)` yang menjaganya
 * ---------------------------------------------------------------------
 * Koreksi kedua menimpa yang pertama, bukan menambah baris: yang dibaca
 * laporan adalah nilai yang BERLAKU, dan dua baris untuk satu jawaban
 * berarti laporan yang menjumlahkan keduanya. Riwayat siapa mengoreksi apa
 * tetap ada - di `graded_at`, `teacher_id`, dan `activity_logs` saat Admin
 * membuka kembali kunci.
 *
 * ---------------------------------------------------------------------
 * `criteria_json` disimpan karena poin total saja tidak bisa menjawab
 * ---------------------------------------------------------------------
 * Plate 06 menampilkan rubrik sebagai daftar kotak yang dicentang, dan
 * pertanyaan pertama setiap siswa yang mendapat 7 dari 8 adalah "kurang di
 * mana". Poin total tanpa rinciannya tidak bisa menjawab itu - dan menjawab
 * "tanya gurumu" bukan jawaban yang bisa diberikan aplikasi.
 *
 * Bentuknya `[{"kriteria":"Langkah benar","poin":4}, ...]`, dan jumlahnya
 * WAJIB sama dengan `score` - ditegakkan `PenilaiUraian`, satu-satunya
 * penulis tabel ini, di dalam satu method yang menulis keduanya.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'student_answer_id',
    'teacher_id',
    'score',
    'criteria_json',
    'feedback',
    'status',
    'graded_at',
])]
class EssayGrading extends Model
{
    use BelongsToSchool;

    /**
     * Koreksi selesai dan berlaku. Satu-satunya nilai yang ditulis sekarang;
     * `status` ada agar koreksi yang dibatalkan bisa ditandai tanpa
     * menghapus jejaknya.
     */
    public const STATUS_FINAL = 'final';

    protected $table = 'essay_gradings';

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'criteria_json' => 'array',
            'graded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StudentAnswer, $this> */
    public function jawaban(): BelongsTo
    {
        return $this->belongsTo(StudentAnswer::class, 'student_answer_id');
    }

    /**
     * Guru yang mengoreksi. RESTRICT di basis data - lihat catatan migrasi:
     * nilai yang bisa dipersoalkan siswa harus punya penanggung jawab.
     *
     * @return BelongsTo<User, $this>
     */
    public function guru(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
