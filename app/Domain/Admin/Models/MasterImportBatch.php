<?php

namespace App\Domain\Admin\Models;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Admin\Enums\JenisImporMaster;
use App\Domain\Admin\Enums\StatusImporPengguna;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu berkas master data yang sedang diimpor - mapel, ruang, atau kelas.
 *
 * Bentuknya menyalin `UserImportBatch`, dan alasannya sama di setiap
 * bagiannya: pratinjau yang disimpan supaya Konfirmasi berarti "menyimpan yang
 * tadi dilihat", hash berkas sebagai penjaga idempotensi, dan berkasnya di
 * disk privat bernama acak.
 *
 * ---------------------------------------------------------------------
 * `academic_year_id` hanya terisi untuk kelas
 * ---------------------------------------------------------------------
 * Nama kelas unik per (sekolah, tahun ajaran) - "X-1" muncul lagi setiap
 * tahun. Karena itu baris kelas butuh satu tahun ajaran yang mengikatnya, dan
 * tahun itu hidup di sini alih-alih di dalam berkasnya: Konfirmasi datang
 * lebih lambat daripada pratinjaunya, dan Admin bisa sudah berganti tahun
 * ajaran di tab lain. Lihat `PenjajarKelas` untuk alasan lengkapnya.
 *
 * Kolomnya nullable karena mapel dan ruang tidak punya tahun ajaran sama
 * sekali - mengarang nilai untuk keduanya berarti menyimpan tahun yang tidak
 * pernah dipilih siapa pun.
 *
 * ---------------------------------------------------------------------
 * Keadaan berkasnya memakai `StatusImporPengguna`
 * ---------------------------------------------------------------------
 * Empat keadaannya identik - menunggu, dipratinjau, dikonfirmasi, gagal - dan
 * artinya juga identik. Enum kedua dengan case yang sama persis adalah enum
 * yang akan berbeda diam-diam pada perubahan pertama, dan yang mengalaminya
 * adalah importer yang tidak sedang diperbaiki.
 *
 * Namanya menyebut "pengguna" dan itu memang tidak lagi tepat. Menggantinya
 * berarti menyentuh impor pengguna, ekspornya, dan seluruh testnya - perubahan
 * yang tidak diminta siapa pun, demi satu kata. Yang lebih berguna adalah
 * mencatatnya di sini: kelak bila ketiga importer ini butuh keadaan yang
 * berbeda, saat itulah enum bersama ini dipecah.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'user_id',
    'jenis',
    'academic_year_id',
    'file_path',
    'nama_asli',
    'berkas_hash',
    'pratinjau_json',
    'status',
    'total_rows',
    'success_rows',
    'failed_rows',
    'created_rows',
    'updated_rows',
    'error_report_path',
    'imported_at',
])]
class MasterImportBatch extends Model
{
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'pratinjau_json' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function pengunggah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Jenisnya, atau null bila nilainya tidak dikenal.
     *
     * Nullable, bukan default: baris dengan jenis yang tidak dikenal harus
     * terlihat sebagai baris yang tidak bisa dibuka, bukan dibuka sebagai
     * impor mapel. Menyimpulkan jenisnya berarti menampilkan laman yang salah
     * untuk berkasnya - dan Admin yang melihat riwayat impor pengguna di laman
     * impor mapel akan menyimpulkan datanya tertukar.
     */
    public function jenisImpor(): ?JenisImporMaster
    {
        return JenisImporMaster::tryFrom((string) $this->jenis);
    }

    public function statusImpor(): StatusImporPengguna
    {
        return StatusImporPengguna::tryFrom((string) $this->status) ?? StatusImporPengguna::Menunggu;
    }
}
