<?php

namespace App\Domain\Event\Models;

use App\Domain\Academic\Models\Subject;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mata pelajaran yang diujikan di event - wireframe plate 15.
 *
 * ---------------------------------------------------------------------
 * `question_package_id` adalah kolom, bukan relasi - dan itu batas modul
 * ---------------------------------------------------------------------
 * Fase 4 menambahkan kolom paketnya, tetapi TIDAK menambahkan method relasi
 * `questionPackage()` di sini. Alasannya bukan kerapian: peta modul
 * (`DomainBoundariesTest`) melarang Event mengimpor model Question, dan
 * relasi Eloquent menuntut kelas modelnya disebutkan.
 *
 * Yang membacanya adalah `PemasanganPaket::ringkasPaket()` - sebuah service,
 * dan jalur service memang diizinkan peta. Yang kembali dari sana hanya teks
 * dan angka, sehingga Event tetap tidak pernah memegang QuestionPackage.
 *
 * ---------------------------------------------------------------------
 * Pemasangan paket dilakukan Admin, bukan Guru
 * ---------------------------------------------------------------------
 * Wireframe plate 15 mencatat alasannya: "mengubah paket setelah jadwal
 * tersimpan harus lewat satu pintu, dan pintu itu mencatat siapa yang
 * mengubahnya serta kapan." Guru menulis soal di bank soal (Fase 4); Admin
 * yang memutuskan paket mana dipakai di event ini.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'event_id', 'subject_id', 'question_package_id'])]
class EventSubject extends Model
{
    use BelongsToSchool, HasFactory;

    /** @return BelongsTo<SchoolEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SchoolEvent::class, 'event_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
