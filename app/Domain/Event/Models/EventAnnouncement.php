<?php

namespace App\Domain\Event\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Papan pengumuman satu event - ditambahkan 2026-11-14.
 *
 * ---------------------------------------------------------------------
 * Mengapa pesan, bukan penanda
 * ---------------------------------------------------------------------
 * `results_announced_at` menandai bahwa hasil sudah diumumkan; ia tidak
 * membawa kalimat apa pun. Padahal justru kalimat yang dibutuhkan siswa
 * pada pagi hari ujian - "sesi 2 mundur 15 menit", "ruang R-03 pindah ke
 * lab komputer". Pengumuman adalah pesan yang dibaca siswa, bukan status
 * yang diperiksa sistem.
 *
 * ---------------------------------------------------------------------
 * `berlaku()` adalah definisi "sedang tayang", dan ia tinggal di sini
 * ---------------------------------------------------------------------
 * Tiga laman membutuhkannya (beranda siswa, laman gerbang, konsol Admin),
 * dan tiga tempat yang menulis sendiri `where('expires_at', ...)` adalah
 * tiga tempat yang suatu hari tidak sepakat tentang apa artinya null.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'event_id',
    'title',
    'body',
    'nada',
    'expires_at',
    'created_by',
])]
class EventAnnouncement extends Model
{
    use BelongsToSchool, HasFactory;

    public const NADA_INFO = 'info';

    public const NADA_PENTING = 'penting';

    /** Batas judul dan isi, dipakai validasi dan laman sekaligus. */
    public const MAKS_JUDUL = 120;

    public const MAKS_ISI = 1000;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SchoolEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SchoolEvent::class, 'event_id');
    }

    /** @return BelongsTo<User, $this> */
    public function penulis(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Yang sedang tayang pada detik ini.
     *
     * `expires_at` null berarti berlaku sampai dihapus; yang sudah lewat
     * berhenti tayang dengan sendirinya tanpa Admin menyunting apa pun.
     *
     * @param  Builder<self>  $query
     */
    public function scopeBerlaku(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    public function kadaluwarsa(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(now());
    }
}
