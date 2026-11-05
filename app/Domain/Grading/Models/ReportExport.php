<?php

namespace App\Domain\Grading\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu permintaan ekspor laporan - PRD §13.5, wireframe plate 22.
 *
 * ---------------------------------------------------------------------
 * Baris ini adalah ANTREAN, bukan hasilnya
 * ---------------------------------------------------------------------
 * Plate 22 menulis di kakinya: "berkas besar: disiapkan di latar belakang".
 * Permintaan Admin menulis satu baris berstatus `menunggu` lalu langsung
 * kembali; worker (`BuatEksporLaporan`) yang mengisi `path` dan
 * `nama_berkas`. Selama satu jenis laporan masih bisa selesai dalam hitungan
 * milidetik, ia tetap lewat jalur yang sama - satu jalur, satu tempat yang
 * bisa gagal dengan benar.
 *
 * ---------------------------------------------------------------------
 * Tidak ada `updated_at`
 * ---------------------------------------------------------------------
 * Barisnya lahir menunggu dan berakhir selesai atau gagal, sekali. Yang
 * menjawab "kapan" adalah `finished_at`, dan kolom `updated_at` untuk baris
 * seperti ini selalu sama dengan `finished_at` atau NULL.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'requested_by',
    'jenis',
    'format',
    'parameter_json',
    'status',
    'total_baris',
    'path',
    'nama_berkas',
    'ukuran',
    'pesan_gagal',
    'started_at',
    'finished_at',
    'created_at',
])]
class ReportExport extends Model
{
    use BelongsToSchool;

    public const STATUS_MENUNGGU = 'menunggu';

    public const STATUS_DIPROSES = 'diproses';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_GAGAL = 'gagal';

    /**
     * Append-only. `created_at` ditulis manual saat baris dibuat karena
     * kolom `updated_at` tidak ada.
     */
    public const UPDATED_AT = null;

    protected $table = 'report_exports';

    protected function casts(): array
    {
        return [
            'parameter_json' => 'array',
            'total_baris' => 'integer',
            'ukuran' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Siapa yang meminta. Nullable karena akun yang dihapus tidak boleh
     * menghapus antrean yang sudah jalan - dan karena ekspor terjadwal
     * (kelak) tidak punya pelaku orang.
     *
     * @return BelongsTo<User, $this>
     */
    public function peminta(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function selesai(): bool
    {
        return $this->status === self::STATUS_SELESAI;
    }

    public function labelStatus(): string
    {
        return match ($this->status) {
            self::STATUS_MENUNGGU => 'Menunggu',
            self::STATUS_DIPROSES => 'Diproses',
            self::STATUS_SELESAI => 'Siap diunduh',
            default => 'Gagal',
        };
    }

    public function nadaStatus(): string
    {
        return match ($this->status) {
            self::STATUS_SELESAI => 'ok',
            self::STATUS_DIPROSES => 'info',
            self::STATUS_MENUNGGU => 'mute',
            default => 'bad',
        };
    }
}
