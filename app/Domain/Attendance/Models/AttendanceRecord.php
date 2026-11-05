<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Presensi satu peserta pada satu penempatan - PRD §10, wireframe plate 13.
 *
 * ---------------------------------------------------------------------
 * Menunjuk PENEMPATAN, bukan siswa dan bukan sesi
 * ---------------------------------------------------------------------
 * Baris ini menjawab "apakah orang yang duduk di kursi ini hadir". Karena
 * itu yang ditunjuknya adalah `exam_participant_id`, dan unique-nya ada di
 * sana. Siswa yang ditempatkan pada dua sesi punya dua baris - dan memang
 * harus, karena dua sesi adalah dua kehadiran yang berbeda.
 *
 * Akibat lanjutannya yang penting: barisnya hancur bersama penempatannya
 * (cascade). Presensi atas penempatan yang sudah dilepas adalah presensi
 * yang tidak bisa dijelaskan siapa pun.
 *
 * ---------------------------------------------------------------------
 * Tidak ada exam_session_id dan exam_session_room_id di tabel ini
 * ---------------------------------------------------------------------
 * Keduanya bisa dibaca lewat penempatan, dan menyalinnya berarti dua tempat
 * menyimpan fakta yang sama. Begitu Admin memindahkan seorang peserta ke
 * ruang lain, salinan itu tertinggal - dan yang tertinggal adalah justru
 * kolom yang dipakai daftar kursi pengawas. Satu sumber kebenaran ada di
 * `exam_participants`, dan seluruh query presensi per ruang menempuhnya.
 *
 * ---------------------------------------------------------------------
 * verified_by dan pelaku_label: dua kolom untuk dua keadaan yang berbeda
 * ---------------------------------------------------------------------
 * `verified_by` berisi akun pengawas bila ia masuk lewat akunnya. Lewat
 * tautan ruang tidak ada akun, dan yang tersisa hanya `pelaku_label` -
 * mis. "R-01 (tautan ruang)". Keduanya bisa terisi sekaligus bila pengawas
 * mengerjakan presensi dari perangkat yang sudah login.
 *
 * Yang membuat label itu bukti dan bukan karangan adalah kredensial
 * ruangnya sendiri dapat diaudit: diterbitkan Admin, setiap pemakaian
 * menaikkan `use_count`, dan setiap pembukaan tercatat di activity_logs.
 * Inilah yang dirender plate 13 sebagai "Diverifikasi 07:42 oleh R-01
 * (Anda)".
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_participant_id',
    'attendance_status',
    'verified_by',
    'verified_at',
    'pelaku_label',
    'notes',
])]
class AttendanceRecord extends Model
{
    use BelongsToSchool;

    protected $table = 'attendance_records';

    protected function casts(): array
    {
        return [
            'attendance_status' => StatusPresensi::class,
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExamParticipant, $this> */
    public function penempatan(): BelongsTo
    {
        return $this->belongsTo(ExamParticipant::class, 'exam_participant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifikator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return HasMany<AttendanceLog, $this> */
    public function log(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function sudahDiverifikasi(): bool
    {
        return $this->attendance_status !== StatusPresensi::Pending;
    }
}
