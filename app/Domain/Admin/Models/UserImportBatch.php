<?php

namespace App\Domain\Admin\Models;

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
 * Satu berkas pengguna yang sedang diimpor - plate 05.
 *
 * Bentuknya menyalin `Question\Models\QuestionImportBatch`, dan alasannya
 * sama di setiap bagiannya: pratinjau yang disimpan supaya Konfirmasi berarti
 * "menyimpan yang tadi dilihat", hash berkas sebagai penjaga idempotensi, dan
 * berkas di disk privat bernama acak.
 *
 * ---------------------------------------------------------------------
 * `created_rows` dan `updated_rows` mengubah sifat pratinjaunya
 * ---------------------------------------------------------------------
 * Impor soal hanya punya satu arah - baris sah disimpan, baris rusak
 * dilewati - sehingga angka "sah" sudah cukup menggambarkan apa yang akan
 * terjadi. Impor pengguna punya DUA arah untuk baris yang sama-sama sah:
 * akun baru dibuat, atau akun lama diperbarui.
 *
 * Bagi Admin yang menekan Konfirmasi, "48 baris sah" tidak menjawab
 * pertanyaan yang ia punya - "apakah berkas ini menyentuh 48 akun yang sudah
 * ada?". Karena itu kedua angkanya dihitung pada langkah PRATINJAU dan
 * disimpan bersama hasilnya, sebelum ada satu baris pun ditulis.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'user_id',
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
class UserImportBatch extends Model
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

    public function statusImpor(): StatusImporPengguna
    {
        return StatusImporPengguna::tryFrom((string) $this->status) ?? StatusImporPengguna::Menunggu;
    }
}
