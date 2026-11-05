<?php

namespace App\Domain\Exam\Models;

use App\Domain\Event\Models\EventParticipant;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penempatan satu siswa pada satu ujian - PRD §9.5.
 *
 * ---------------------------------------------------------------------
 * Menunjuk event_participant, dan student_id disimpan sebagai salinan
 * ---------------------------------------------------------------------
 * Keduanya ada, dan itu bukan duplikasi yang lalai. `event_participant_id`
 * adalah asal-usulnya - ia yang membuktikan siswa ini benar-benar peserta
 * event yang bersangkutan, lewat composite FK. `student_id` ada karena
 * hampir setiap query tentang penempatan menanyakan "siswa yang mana"
 * (laporan per kelas, pemeriksaan benturan peserta, daftar hadir), dan
 * menjawabnya lewat dua join setiap kali berarti setiap query itu bisa
 * salah menulis join-nya sendiri.
 *
 * Yang menjaga keduanya tetap konsisten adalah kenyataan bahwa
 * student_id HANYA ditulis di satu tempat - PenempatanService - dan
 * diambil dari event participant yang bersangkutan, bukan dari input.
 *
 * ---------------------------------------------------------------------
 * unique(exam_id, student_id) adalah aturan §9.5, bukan hiasan skema
 * ---------------------------------------------------------------------
 * "Satu siswa hanya memiliki satu penempatan aktif untuk satu ujian."
 * Tanpa constraint ini, penempatan ulang akan menambah baris kedua, dan
 * yang paling mungkin terjadi bukan galat - melainkan siswa yang muncul
 * dua kali di daftar hadir pengawas, dengan dua ruang berbeda.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_id',
    'event_participant_id',
    'student_id',
    'exam_session_id',
    'exam_session_room_id',
    'assigned_at',
    'status',
])]
class ExamParticipant extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'exam_participants';

    public const STATUS_TERDAFTAR = 'terdaftar';

    public const STATUS_DIKECUALIKAN = 'dikecualikan';

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Exam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /** @return BelongsTo<EventParticipant, $this> */
    public function pesertaEvent(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class, 'event_participant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** @return BelongsTo<ExamSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    /** @return BelongsTo<ExamSessionRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ExamSessionRoom::class, 'exam_session_room_id');
    }
}
