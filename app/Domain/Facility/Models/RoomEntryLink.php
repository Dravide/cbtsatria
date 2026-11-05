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
 * Tautan masuk ruang + kode pengawas.
 *
 * ---------------------------------------------------------------------
 * Dua faktor yang menunjuk hal yang sama
 * ---------------------------------------------------------------------
 * Wireframe detail plate 09 memisahkan perannya dengan tajam:
 *
 *   Tautan menentukan RUANG. Boleh panjang, boleh ditempel di pintu, dan
 *   sendirian ia tidak membuka apa pun - hanya menampilkan satu kolom kode.
 *
 *   Kode menentukan ORANG. Pendek supaya bisa diketik di depan pintu, dan
 *   justru karena pendek ia tidak boleh menjadi satu-satunya penjaga.
 *
 * Keduanya harus menunjuk ruang yang SAMA. Tautan R-01 dengan kode ruang R-02
 * ditolak - bukan karena kodenya salah, tetapi karena keduanya tidak sedang
 * berbicara tentang ruang yang sama. Pemeriksaan itu milik service di Fase 6,
 * dan bentuk datanya disiapkan di sini: kode tersimpan di baris milik ruangnya,
 * sehingga "kode ini milik ruang mana" selalu punya jawaban.
 *
 * ---------------------------------------------------------------------
 * code_hash, bukan code
 * ---------------------------------------------------------------------
 * Kode pengawas adalah kredensial. Menyimpannya apa adanya berarti siapa pun
 * yang membaca tabel ini - lewat backup, dump, atau satu query yang bocor -
 * dapat masuk ke setiap ruang tanpa pernah berdiri di depan pintunya.
 *
 * Yang disimpan hanya hash-nya. Kode mentahnya ditampilkan SEKALI, saat
 * diterbitkan, dan sesudah itu tidak ada cara membacanya kembali. Tombolnya
 * karena itu berbunyi "Terbitkan ulang", bukan "Lihat kode".
 *
 * use_count dicatat karena wireframe menampilkan chip "2 pemakaian" - Admin
 * perlu melihat bahwa sebuah tautan sudah dipakai lebih dari sekali, sebab
 * itu bisa berarti kartunya difoto orang lain.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'room_id',
    'slug',
    'code_hash',
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
