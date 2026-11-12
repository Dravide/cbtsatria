<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\StatusPengakuan;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengakuan siswa setelah memindai kode presensi - plate B4-B6.
 *
 * ---------------------------------------------------------------------
 * Baris ini BUKAN presensi, dan tidak pernah menjadi presensi
 * ---------------------------------------------------------------------
 * Presensi tetap ditulis oleh `KehadiranService` saat pengawas menekan
 * tombolnya. Baris ini hanya menyatakan "saya hadir di ruang ini, sekarang",
 * dan ia menyimpannya sebagai fakta tersendiri supaya:
 *
 *   - waktu PINDAI tidak hilang. Status hadir/terlambat diturunkan dari jam
 *     ini, bukan dari jam konfirmasi - pengawas yang baru menekan tombol 20
 *     menit kemudian tidak boleh membuat siswa yang datang tepat waktu
 *     tercatat terlambat;
 *   - pengawas melihat siapa saja yang sudah mengaku sebelum ia memutuskan;
 *   - "atas dasar apa anak saya dicatat hadir" bisa dijawab dari layar yang
 *     sama (`pindai 07:32`), bukan dari kolom tersembunyi.
 *
 * ---------------------------------------------------------------------
 * unique per penempatan, dan itu yang membuat pindai ganda aman
 * ---------------------------------------------------------------------
 * Memindai dua kali karena tombolnya tersentuh dua kali tidak boleh menulis
 * dua baris atau membalik status yang sudah diputuskan pengawas menjadi
 * `belum` - plate B6, penolakan keempat. Unik di `(school_id,
 * exam_participant_id)` yang menegakkannya di lapisan basis data, bukan
 * hanya di controller.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'attendance_scan_code_id',
    'exam_participant_id',
    'scanned_at',
    'status',
    'confirmed_at',
])]
class AttendanceAcknowledgement extends Model
{
    use BelongsToSchool;

    protected $table = 'attendance_acknowledgements';

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'status' => StatusPengakuan::class,
        ];
    }

    /** @return BelongsTo<ExamParticipant, $this> */
    public function penempatan(): BelongsTo
    {
        return $this->belongsTo(ExamParticipant::class, 'exam_participant_id');
    }

    /** @return BelongsTo<AttendanceScanCode, $this> */
    public function kode(): BelongsTo
    {
        return $this->belongsTo(AttendanceScanCode::class, 'attendance_scan_code_id');
    }
}
