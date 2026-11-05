<?php

namespace App\Domain\Event\Models;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kelas peserta event - PRD §6.7.
 *
 * Tabel ini ada supaya "kelas mana yang ikut" punya jawaban yang tersimpan,
 * bukan disimpulkan dari daftar peserta. Perbedaannya terlihat pada event yang
 * baru dibuat: kelas sudah ditetapkan, pesertanya belum ditarik. Tanpa tabel
 * ini, dua keadaan itu tidak bisa dibedakan - dan readiness gate tidak akan
 * bisa membedakan "kelas belum dipilih" dari "peserta belum ditarik", padahal
 * keduanya butuh perbaikan yang berbeda.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable(['school_id', 'event_id', 'class_id'])]
class EventClass extends Model
{
    use BelongsToSchool, HasFactory;

    /** @return BelongsTo<SchoolEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SchoolEvent::class, 'event_id');
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function kelas(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
