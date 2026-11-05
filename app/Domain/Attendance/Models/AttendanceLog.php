<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat perubahan presensi - append-only.
 *
 * ---------------------------------------------------------------------
 * Mengapa setiap perubahan menulis baris BARU, bukan menimpa
 * ---------------------------------------------------------------------
 * PRD §10.5: "setiap perubahan presensi mencatat identitas Pengawas, waktu,
 * dan alasan". Kalimat itu tidak bisa dipenuhi oleh satu kolom
 * `attendance_status` yang ditimpa: begitu ditimpa, yang hilang justru
 * fakta yang paling mungkin dipersoalkan - siswa yang semula dicatat tidak
 * hadir lalu dikoreksi menjadi hadir, dan siapa yang mengoreksinya.
 *
 * Karena itu Admin yang mengoreksi presensi TIDAK PERNAH menyunting baris
 * lama. Ia menulis baris baru di sini, dan barisnya menyebut status lama,
 * status baru, alasannya, dan siapa pelakunya. Yang menyunting adalah satu-
 * satunya cara membuat riwayat berbohong.
 *
 * ---------------------------------------------------------------------
 * Hanya created_at, tanpa updated_at
 * ---------------------------------------------------------------------
 * Baris ini menjawab "siapa mengubah apa, kapan, mengapa" - dan jawaban itu
 * kehilangan seluruh nilainya kalau barisnya bisa diubah. `UPDATED_AT = null`
 * bukan kelalaian; ia yang membuat "tidak bisa dihapus" berlaku juga pada
 * tingkat model.
 *
 * ---------------------------------------------------------------------
 * pelaku_label NOT NULL
 * ---------------------------------------------------------------------
 * Berbeda dari attendance_records yang labelnya boleh kosong: setiap baris
 * log PASTI ditulis oleh seseorang, dan orang itu belum tentu punya akun.
 * Kolom yang boleh kosong di sini berarti baris log yang tidak bisa
 * dipertanggungjawabkan - persis yang membuat log ini tidak berguna.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'attendance_record_id',
    'changed_by',
    'pelaku_label',
    'old_status',
    'new_status',
    'reason',
    'created_at',
])]
class AttendanceLog extends Model
{
    use BelongsToSchool;

    protected $table = 'attendance_logs';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'old_status' => StatusPresensi::class,
            'new_status' => StatusPresensi::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AttendanceRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    /** @return BelongsTo<User, $this> */
    public function pelaku(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
