<?php

namespace App\Domain\Event\Models;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Peserta event - wireframe plate 14, PRD §6.7.
 *
 * ---------------------------------------------------------------------
 * Peserta ditarik SEKALI di sini, bukan ditentukan per ujian
 * ---------------------------------------------------------------------
 * Wireframe plate 14 mencatatnya, dan itu keputusan yang menentukan bentuk
 * seluruh event berikutnya: "kalau peserta ditentukan per ujian, menambah
 * satu siswa di tengah event berarti mengulang pekerjaan di belasan tempat."
 *
 * Satu siswa hadir di satu event, dan kehadirannya berlaku untuk seluruh
 * ujian di dalamnya. Ujian yang diikuti sebagian peserta dinyatakan lewat
 * pengecualian (status), bukan lewat daftar peserta yang berbeda.
 *
 * ---------------------------------------------------------------------
 * Sumber: "kelas" vs "manual" - dan mengapa itu disimpan
 * ---------------------------------------------------------------------
 * Kolom `source` bukan hiasan tabel. Ia menjawab pertanyaan yang muncul
 * setiap kali kelas ditarik ulang: apa yang terjadi pada peserta yang
 * ditambahkan Admin satu per satu?
 *
 *   source = 'kelas'  - berasal dari keanggotaan kelas. Bila kelasnya
 *                       dikeluarkan dari event, pesertanya ikut.
 *   source = 'manual' - ditambahkan Admin secara sengaja. Ia pengecualian
 *                       yang dibuat dengan niat, dan menarik ulang daftar
 *                       kelas tidak boleh menghapusnya.
 *
 * Tanpa kolom ini, kedua asal itu tidak bisa dibedakan sesudahnya, dan
 * pilihannya hanya dua - keduanya buruk: menghapus peserta manual saat kelas
 * ditarik ulang, atau membiarkan peserta dari kelas yang sudah dikeluarkan.
 *
 * ---------------------------------------------------------------------
 * class_id boleh null
 * ---------------------------------------------------------------------
 * Peserta manual boleh siswa yang belum punya kelas pada tahun ajaran event
 * - mis. siswa pindahan yang sedang dilengkapi berkasnya. Menjadikannya
 * wajib berarti menolak keadaan yang justru alasan peserta manual ada.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'event_id',
    'student_id',
    'class_id',
    'source',
    'status',
    'registered_at',
])]
class EventParticipant extends Model
{
    use BelongsToSchool, HasFactory;

    public const SUMBER_KELAS = 'kelas';

    public const SUMBER_MANUAL = 'manual';

    public const STATUS_TERDAFTAR = 'terdaftar';

    public const STATUS_DIKECUALIKAN = 'dikecualikan';

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SchoolEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SchoolEvent::class, 'event_id');
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function kelas(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
