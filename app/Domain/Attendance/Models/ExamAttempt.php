<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Event\Support\AmbangPelanggaran;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu pengerjaan ujian oleh satu peserta - PRD §11, wireframe plate 15-16.
 *
 * ---------------------------------------------------------------------
 * Dibuat di Fase 6, DITULIS di Fase 7
 * ---------------------------------------------------------------------
 * Tabelnya ada sekarang karena yang MEMBACA adalah plate 15 (papan
 * monitoring) dan plate 16 (riwayat pelanggaran per siswa) - keduanya
 * bagian dari Fase 6. Yang MENULIS - `eligibility`, `start`, kunci baris,
 * heartbeat - adalah mesin ujian di Fase 7.
 *
 * Model ini karena itu tidak punya satu pun method yang mengubah keadaan.
 * Menambahkan `mulai()` di sini berarti menaruh aturan mesin ujian di modul
 * presensi, dan aturan itu akan bertabrakan dengan versi Fase 7 yang
 * sebenarnya.
 *
 * ---------------------------------------------------------------------
 * violation_count dan violation_worst_severity disimpan, bukan dihitung
 * ---------------------------------------------------------------------
 * Keduanya turunan dari exam_violations dan secara teori bisa dihitung.
 * Yang membuatnya disimpan adalah papan monitoring: ia menghitung keduanya
 * untuk SETIAP peserta di setiap ruang pada setiap penyegaran - dan
 * penyegaran berlangsung terus selama ujian berjalan, justru saat basis
 * data paling sibuk. Agregat per baris tabel adalah persis beban yang
 * membuat papan itu lambat ketika ia paling dibutuhkan.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_participant_id',
    'attempt_number',
    'started_at',
    'deadline_at',
    'submitted_at',
    'last_heartbeat_at',
    'last_seen_at',
    'device_type',
    'ip_address',
    'user_agent',
    'status',
    'violation_count',
    'violation_worst_severity',
    'lock_reason',
    'locked_at',
    'guard_flags_json',
])]
class ExamAttempt extends Model
{
    use BelongsToSchool;

    protected $table = 'exam_attempts';

    public const STATUS_BERJALAN = 'berjalan';

    public const STATUS_DIKIRIM = 'dikirim';

    public const STATUS_KEDALUWARSA = 'kedaluwarsa';

    public const STATUS_DIKUNCI = 'dikunci';

    public const STATUS_BATAL = 'batal';

    public function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'submitted_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'locked_at' => 'datetime',
            'guard_flags_json' => 'array',
        ];
    }

    /** @return BelongsTo<ExamParticipant, $this> */
    public function penempatan(): BelongsTo
    {
        return $this->belongsTo(ExamParticipant::class, 'exam_participant_id');
    }

    /** @return HasMany<ExamViolation, $this> */
    public function pelanggaran(): HasMany
    {
        return $this->hasMany(ExamViolation::class, 'exam_attempt_id');
    }

    public function dikunci(): bool
    {
        return $this->status === self::STATUS_DIKUNCI;
    }

    /**
     * Ambang pelanggaran yang berlaku untuk pengerjaan ini.
     *
     * Ambangnya milik EVENT, bukan milik ujian - lihat docblock `Exam`, yang
     * menolak menduplikasinya. Rantainya karena itu peserta -> ujian -> event,
     * dan satu-satunya alasan method ini ada di model adalah karena TIGA
     * tempat membutuhkannya dengan jalan yang sama: papan monitoring, riwayat
     * pelanggaran pengawas (plate 16), dan riwayat pelanggaran siswa (plate
     * 25 dan 29). Tiga tempat yang menembus rantai itu sendiri adalah tiga
     * tempat yang bisa lupa satu simpul.
     *
     * Rantai yang putus - penempatan yang dihapus, ujian tanpa event - jatuh
     * ke bawaan sekolah, bukan melempar. Riwayat pelanggaran yang tidak bisa
     * dibuka karena datanya tidak lengkap adalah riwayat yang tidak bisa
     * dipakai untuk hal yang paling membutuhkannya.
     */
    public function ambang(int $bawaan = 5): AmbangPelanggaran
    {
        return $this->penempatan?->exam?->event?->ambang($bawaan)
            ?? AmbangPelanggaran::dari(null, $bawaan);
    }

    /**
     * Apakah perangkatnya masih terhubung.
     *
     * Dihitung, bukan disimpan - dan itu keputusan sadar yang berlawanan
     * dengan violation_count di atas. Perbedaannya: pelanggaran adalah
     * PERISTIWA yang tidak berubah sesudah terjadi, sementara "terhubung"
     * adalah keadaan yang berubah sendiri seiring waktu tanpa ada yang
     * menulis apa pun. Menyimpannya berarti kolom yang harus diusap
     * penjadwal, dan penjadwal yang mati membuat papan monitoring
     * menampilkan siswa yang sudah pulang sebagai "masih mengerjakan".
     *
     * Ambangnya 90 detik, sesuai heartbeat 30 detik yang akan dipasang
     * Fase 7: tiga denyut yang hilang.
     */
    public function terhubung(int $ambangDetik = 90): bool
    {
        if ($this->last_heartbeat_at === null) {
            return false;
        }

        return $this->last_heartbeat_at->diffInSeconds(now()) <= $ambangDetik;
    }

    /** Nama status dalam bahasa Indonesia untuk chip di papan monitoring. */
    public function labelStatus(): string
    {
        return match ($this->status) {
            self::STATUS_BERJALAN => 'Mengerjakan',
            self::STATUS_DIKIRIM => 'Selesai',
            self::STATUS_KEDALUWARSA => 'Waktu habis',
            self::STATUS_DIKUNCI => 'Terkunci',
            self::STATUS_BATAL => 'Batal',
            default => $this->status,
        };
    }

    public function nadaStatus(): string
    {
        return match ($this->status) {
            self::STATUS_DIKIRIM => 'ok',
            self::STATUS_BERJALAN => 'info',
            self::STATUS_DIKUNCI => 'warn',
            default => 'mute',
        };
    }
}
