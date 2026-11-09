<?php

namespace App\Domain\Facility\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tautan masuk ruang - dan HANYA tautan.
 *
 * ---------------------------------------------------------------------
 * Kolom kodenya pindah 2026-11-07, dan pemindahan itu isi laporannya
 * ---------------------------------------------------------------------
 * Sampai 2026-11-07 kelas ini memegang dua hal: `slug` dan `code_hash`.
 * Keduanya diterbitkan sekali oleh Admin dan keduanya tercetak pada kartu
 * yang sama yang ditempel di daun pintu - sehingga memfoto kartu itu sudah
 * cukup untuk masuk ke ruang, dan kode yang seharusnya "menentukan orang"
 * justru menentukan ruang untuk siapa pun yang memegangnya.
 *
 * Kode pengawas kini hidup di `event_committee_members.code`, milik
 * ORANG-nya - dan disimpan TERENKRIPSI, bukan ter-hash, karena ia harus bisa
 * ditampilkan kembali untuk dicetak ke kartu pengawas. Pembagiannya persis
 * seperti yang ditulis blueprint `:995`:
 *
 *   Tautan menentukan RUANG. Boleh panjang, boleh ditempel di pintu, dan
 *   sendirian ia tidak membuka apa pun - hanya menampilkan satu kolom kode.
 *
 *   Kode menentukan ORANG. Pendek supaya bisa diketik di depan pintu, dan
 *   justru karena pendek ia tidak boleh menjadi satu-satunya penjaga.
 *
 * ---------------------------------------------------------------------
 * Yang TETAP tinggal di sini: use_count dan last_used_at
 * ---------------------------------------------------------------------
 * Pencatatannya masih milik tautan, dan maknanya tidak berubah sedikit pun:
 * wireframe menampilkan chip "2 pemakaian", dan Admin perlu melihat bahwa
 * sebuah tautan sudah dipakai lebih dari sekali - sebab itu bisa berarti
 * kartunya difoto orang lain.
 *
 * Justru karena kodenya sudah tidak lagi menempel di kartu, angka ini
 * menjadi satu-satunya tanda kebocoran yang tersisa pada kartu itu.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'room_id',
    'slug',
    'expires_at',
    'revoked_at',
    'use_count',
    'bound_device_fingerprint',
    'last_used_at',
])]
class RoomEntryLink extends Model
{
    use BelongsToSchool, HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
            'use_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Apakah tautan ini masih berlaku.
     *
     * Definisi tunggal, dipakai bersama oleh laman Admin dan (Fase 6)
     * halaman pintu. Dua tempat yang masing-masing menyusun syaratnya sendiri
     * adalah cara paling mudah membuat "dicabut" berarti berbeda di dua layar.
     */
    public function berlaku(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Status yang ditampilkan di daftar tautan ruang (chip pada wireframe).
     */
    public function statusChip(): string
    {
        if ($this->revoked_at !== null) {
            return 'dicabut';
        }

        if (! $this->berlaku()) {
            return 'kedaluwarsa';
        }

        return $this->use_count > 0 ? 'aktif' : 'belum dicetak';
    }
}
