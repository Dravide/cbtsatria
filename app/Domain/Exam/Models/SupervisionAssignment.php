<?php

namespace App\Domain\Exam\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penugasan seorang pengawas pada satu ruang-sesi - PRD §9.6.
 *
 * ---------------------------------------------------------------------
 * Ini bukan "pengawas" di kepanitiaan event, dan perbedaannya menentukan
 * ---------------------------------------------------------------------
 * Plate 13 (kepanitiaan) mencatat SIAPA pengawasnya di tingkat event - satu
 * daftar nama, sekali. Tabel ini mencatat penugasan RUANG-SESI: siapa yang
 * berdiri di R-02 pada sesi 1.
 *
 * Blueprint menempatkan keduanya di modul berbeda (Committee nomor 5,
 * Supervision nomor 11), dan alasannya bukan kerapian: kepanitiaan adalah
 * daftar orang, penugasan adalah hak akses. Kalimat plate 17 menyatakannya
 * langsung - "penugasan inilah yang memberi Pengawas hak melihat dan
 * memverifikasi presensi di ruang itu. Tanpa penugasan, ia tidak melihat
 * apa pun."
 *
 * Konsekuensinya: pengawas yang ada di kepanitiaan tetapi tidak ditugaskan
 * ke satu ruang pun TIDAK bisa membuka ruang mana pun. Itu bukan celah,
 * itu memang aturannya - dan itulah sebabnya readiness gate memeriksa
 * penugasan, bukan daftar panitia.
 *
 * ---------------------------------------------------------------------
 * start_at / end_at nullable: null berarti "ikuti jendela sesi"
 * ---------------------------------------------------------------------
 * PRD §9.6 menuntut kolomnya ada; §16.6 tidak memberikannya. Yang dipilih
 * adalah pola yang sama dengan tiga kolom override lain di sistem ini:
 * kolomnya ada, tetapi kosong berarti mewarisi.
 *
 * Mengisinya NOT NULL berarti menyimpan waktu yang sama di dua tempat, dan
 * saat Admin menggeser sesi tanpa menggeser penugasannya, tidak ada yang
 * tahu mana yang benar. Kolom ini hanya diisi bila pengawas benar-benar
 * bergantian di tengah sesi.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_session_room_id',
    'supervisor_id',
    'assigned_by',
    'start_at',
    'end_at',
    'status',
])]
class SupervisionAssignment extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'supervision_assignments';

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExamSessionRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ExamSessionRoom::class, 'exam_session_room_id');
    }

    /** @return BelongsTo<User, $this> */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /**
     * Jendela waktu efektif penugasan ini.
     *
     * Null pada salah satu ujung berarti "ikuti sesi", dan itu bukan kasus
     * tepi - ia keadaan NORMAL. Seluruh pemeriksaan benturan pengawas
     * bertumpu pada metode ini, karena tanpa penyelesaian warisan ini setiap
     * penugasan akan tampak tidak punya waktu sama sekali.
     *
     * @return array{0: ?CarbonInterface, 1: ?CarbonInterface}
     */
    public function jendela(): array
    {
        $sesi = $this->room?->session;

        return [
            $this->start_at ?? $sesi?->access_opens_at,
            $this->end_at ?? $sesi?->hard_end_at,
        ];
    }
}
