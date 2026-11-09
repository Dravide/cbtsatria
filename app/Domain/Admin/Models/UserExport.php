<?php

namespace App\Domain\Admin\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu permintaan ekspor pengguna - PRD §5.7, wireframe plate 05.
 *
 * =====================================================================
 * Baris ini adalah ANTREAN, bukan hasilnya
 * =====================================================================
 * PRD §19.3: "Import dan ekspor berjalan melalui queue jika membutuhkan waktu
 * lama." Sekolah dengan 5.000 siswa adalah alasan yang cukup - dan
 * menyiapkannya di dalam permintaan HTTP berarti Admin menatap tombol berputar
 * untuk pekerjaan yang tidak ia butuhkan untuk melanjutkan.
 *
 * Permintaan Admin menulis SATU baris berstatus `menunggu` lalu langsung
 * kembali; worker (`BuatEksporPengguna`) yang mengisi `path` dan
 * `nama_berkas`. Riwayatnya muncul di laman Pengguna, dan tautan unduhnya
 * hanya hidup saat `selesai` - tautan yang berakhir 404 membuat Admin mengira
 * berkasnya hilang.
 *
 * =====================================================================
 * Berbeda dari `UserImportBatch`, dan bedanya bukan kerapian
 * =====================================================================
 * Impor punya `pratinjau_json` karena ia tiga langkah dan Admin harus melihat
 * barisnya SEBELUM menyimpan. Ekspor satu langkah: yang ada hanyalah "sedang
 * disiapkan" lalu "siap diunduh".
 *
 * `parameter_json` di sini menyimpan PENYARINGNYA - peran, kata kunci, status -
 * bukan hasilnya. Yang menyimpan cara MEMBUATNYA berarti ekspor yang gagal
 * bisa diulang dengan penyaring yang persis sama.
 *
 * =====================================================================
 * Tidak ada `updated_at`
 * =====================================================================
 * Barisnya lahir menunggu dan berakhir selesai atau gagal, sekali - lihat
 * catatan yang sama di `ReportExport`.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'requested_by',
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
class UserExport extends Model
{
    use BelongsToSchool, HasFactory;

    public const STATUS_MENUNGGU = 'menunggu';

    public const STATUS_DIPROSES = 'diproses';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_GAGAL = 'gagal';

    /**
     * Format yang ditawarkan, dan urutannya di modal.
     *
     * XLSX lebih dulu karena ia yang bisa dibuka Excel tanpa bertanya, dan
     * ekspor ini memang untuk disunting lalu diimpor kembali.
     *
     * @var array<int, string>
     */
    public const FORMAT = ['xlsx', 'csv'];

    /** Append-only; `created_at` ditulis manual oleh pemanggilnya. */
    public const UPDATED_AT = null;

    protected $table = 'user_exports';

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

    /** @return BelongsTo<User, $this> */
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

    /**
     * Nada lencananya, dihitung di sini.
     *
     * `nadaStatus()` di `ui.tsx` tidak mengenal `menunggu`/`diproses` - ia
     * memetakan kosakata status event, presensi, dan master data. Menghitungnya
     * dari sini berarti `ui.tsx` tidak perlu disentuh, dan menambahkan satu
     * kosakata status baru tidak berarti menyunting komponen bersama.
     */
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
