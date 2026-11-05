<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Insiden ruang ujian - wireframe plate 15 ("Catat insiden").
 *
 * ---------------------------------------------------------------------
 * Berbeda dari exam_violations, dan perbedaannya bukan kerapian
 * ---------------------------------------------------------------------
 * Pelanggaran adalah perbuatan SISWA yang tertangkap mesin ujian: pindah
 * tab, keluar dari layar penuh, menyalin soal. Ia selalu melekat pada satu
 * attempt, dan sifatnya terukur.
 *
 * Insiden adalah kejadian yang DILAPORKAN ORANG dan tidak melekat pada
 * siapa pun: listrik mati, siswa pingsan, ruang sebelah berisik, kabel
 * jaringan tercabut. Ketiga contoh pertama tidak punya attempt, dan
 * memaksanya masuk ke exam_violations berarti mengarang attempt untuk
 * kejadian yang tidak mengerjakan apa pun.
 *
 * Karena itu `exam_participant_id` nullable: insiden ruang yang tidak
 * menunjuk siswa tertentu adalah insiden yang paling sering terjadi.
 *
 * ---------------------------------------------------------------------
 * supervisor_id nullable, pelaku_label NOT NULL
 * ---------------------------------------------------------------------
 * Pelapornya bisa pemegang tautan ruang yang tidak punya akun. Membuat
 * `supervisor_id` wajib berarti insiden tidak bisa dicatat dari ponsel di
 * depan pintu - tepat di tempat yang paling mungkin melihatnya.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_session_room_id',
    'exam_participant_id',
    'supervisor_id',
    'pelaku_label',
    'incident_type',
    'description',
    'occurred_at',
    'status',
])]
class ExamIncident extends Model
{
    use BelongsToSchool;

    protected $table = 'exam_incidents';

    public const JENIS = [
        'listrik' => 'Listrik / perangkat mati',
        'jaringan' => 'Jaringan terputus',
        'kesehatan' => 'Peserta sakit',
        'keterlambatan' => 'Keterlambatan',
        'kehilangan' => 'Soal / perangkat hilang',
        'lain' => 'Lain-lain',
    ];

    public const STATUS_TERBUKA = 'terbuka';

    public const STATUS_DITANGANI = 'ditangani';

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExamSessionRoom, $this> */
    public function ruangSesi(): BelongsTo
    {
        return $this->belongsTo(ExamSessionRoom::class, 'exam_session_room_id');
    }

    /** @return BelongsTo<ExamParticipant, $this> */
    public function peserta(): BelongsTo
    {
        return $this->belongsTo(ExamParticipant::class, 'exam_participant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function pengawas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /** Label jenis yang dirender di daftar; jatuh ke nilai mentah bila tak dikenal. */
    public function labelJenis(): string
    {
        return self::JENIS[$this->incident_type] ?? $this->incident_type;
    }

    public function terbuka(): bool
    {
        return $this->status === self::STATUS_TERBUKA;
    }
}
