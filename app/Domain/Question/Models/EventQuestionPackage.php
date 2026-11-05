<?php

namespace App\Domain\Question\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat pemasangan paket ke sebuah mapel event - append-only.
 *
 * ---------------------------------------------------------------------
 * Model ini hidup di modul Question meski barisnya menunjuk Event
 * ---------------------------------------------------------------------
 * `event_subjects` dan `school_events` adalah tabel Event, dan model ini
 * menunjuk keduanya lewat id - bukan lewat model. Tidak ada satu pun impor
 * model Event di sini, dan itu bukan kebetulan: peta modul
 * (DomainBoundariesTest) hanya mengizinkan Question -> Identity, Tenancy,
 * Academic, Audit.
 *
 * Yang dijaga pembagian itu: Question tidak boleh tahu apa itu "event".
 * Barisnya adalah catatan pemasangan yang kebetulan menyebut sebuah id, dan
 * kalau suatu saat paket dipasang ke sesuatu yang bukan event, tabelnya tidak
 * perlu dibongkar.
 *
 * ---------------------------------------------------------------------
 * Tidak ada updated_at, dan itu bukan kelalaian
 * ---------------------------------------------------------------------
 * Baris ini menjawab "apa yang terjadi", dan jawaban itu hilang nilainya
 * kalau barisnya bisa diubah. Pola yang sama dipakai `exam_violations` dan
 * `attendance_logs` sejak Fase 6: `created_at` saja, tanpa `timestamps()`,
 * dan model menyetel `UPDATED_AT = null` supaya Eloquent tidak mencoba
 * menulis kolom yang tidak ada.
 *
 * ---------------------------------------------------------------------
 * `replaced_package_id` null punya DUA arti, dan keduanya benar
 * ---------------------------------------------------------------------
 * Null berarti "ini pemasangan pertama untuk mapel ini" - atau "ini
 * pelepasan, dan yang digantikan adalah paket yang tadi terpasang". Satu
 * kolom menjawab keduanya tanpa kolom jenis tersendiri, dan yang membedakan
 * adalah `question_package_id` di baris yang sama: baris pelepasan tetap
 * menyebut paket yang dilepas.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'event_id',
    'event_subject_id',
    'question_package_id',
    'assigned_by',
    'replaced_package_id',
    'assigned_at',
    'catatan',
])]
class EventQuestionPackage extends Model
{
    use BelongsToSchool, HasFactory;

    /** Baris riwayat tidak pernah disunting - lihat catatan kelas ini. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<QuestionPackage, $this> */
    public function paket(): BelongsTo
    {
        return $this->belongsTo(QuestionPackage::class, 'question_package_id');
    }

    /** @return BelongsTo<QuestionPackage, $this> */
    public function paketSebelumnya(): BelongsTo
    {
        return $this->belongsTo(QuestionPackage::class, 'replaced_package_id');
    }

    /** @return BelongsTo<User, $this> */
    public function pemasang(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
