<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Konfigurasi penyedia AI satu sekolah - laman Admin, tab AI.
 *
 * =====================================================================
 * `api_key_encrypted` TIDAK ADA di daftar Fillable
 * =====================================================================
 * Dan itu yang paling mudah salah. Kuncinya hanya boleh masuk lewat
 * `PengaturanAiSekolah::simpan()`, yang memanggil `forceFill()` setelah
 * mengenkripsinya. Bila kolom ini ikut fillable, satu `PengaturanAi::create(
 * $request->all())` di suatu tempat kelak menyimpan kunci dalam bentuk
 * MENTAH - dan yang membacanya nanti akan `decryptString()` sebuah teks
 * biasa, gagal, lalu melaporkan "kunci rusak" tanpa siapa pun curiga bahwa
 * kuncinya tersimpan terbuka di kolom yang justru dinamai "encrypted".
 *
 * =====================================================================
 * Mengapa dienkripsi, bukan di-hash
 * =====================================================================
 * Ini kebalikan dari `exam_tokens.token_hash`. Token ujian hanya perlu
 * DIBANDINGKAN, sehingga hash cukup dan lebih aman. Kunci API harus DIKIRIM
 * ke penyedia, jadi ia harus terbaca kembali. Enkripsi simetris adalah satu-
 * satunya pilihan, dan itu berarti kunci APP_KEY menjadi kunci seluruh
 * sekolah - konsekuensi yang sudah berlaku untuk `exam_tokens.token_encrypted`
 * dan tidak diulang di sini.
 *
 * =====================================================================
 * `petunjukKunci()` mendekripsi hanya untuk membuang sisanya
 * =====================================================================
 * Admin perlu melihat bahwa kuncinya SUDAH terisi tanpa kunci itu sendiri
 * melintas ke peramban. Empat huruf terakhir cukup untuk itu, dan teks
 * penuhnya tidak pernah keluar dari kelas ini - tidak ke props Inertia, tidak
 * ke log, tidak ke pesan galat.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'base_url',
    'model',
    'aktif',
    'batas_token',
    'diperbarui_oleh',
])]
class PengaturanAi extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $table = 'pengaturan_ai';

    /** Penanda di layar saat kuncinya sudah ada. */
    public const PENANDA_KUNCI = '••••••••';

    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
            'batas_token' => 'integer',
        ];
    }

    /**
     * Cukup lengkap untuk dipakai memanggil penyedia.
     *
     * Keempat syaratnya diperiksa bersamaan karena masing-masing sendirian
     * tidak berarti apa pun: URL tanpa model tidak bisa dipanggil, model
     * tanpa kunci ditolak 401, dan `aktif` yang mati adalah keputusan Admin
     * yang harus dihormati meski ketiga sisanya terisi.
     */
    public function siap(): bool
    {
        return $this->aktif
            && filled($this->base_url)
            && filled($this->model)
            && filled($this->api_key_encrypted);
    }

    /**
     * Kunci API dalam bentuk aslinya.
     *
     * Null bila kosong ATAU tidak bisa didekripsi. Dua keadaan itu sengaja
     * disatukan: bagi pemanggilnya keduanya berarti "tidak bisa dipakai",
     * dan membedakannya berarti membocorkan bahwa ada ciphertext yang rusak -
     * informasi yang tidak menolong siapa pun kecuali yang menyerang.
     */
    public function kunciApi(): ?string
    {
        if (blank($this->api_key_encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->api_key_encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Empat huruf terakhir kunci, untuk layar Admin.
     *
     * Bukan `ada_kunci` saja: Admin yang menyimpan kunci baru perlu melihat
     * bahwa yang tersimpan memang kunci yang ia maksud. Tiga huruf terakhir
     * cukup untuk itu dan tidak cukup untuk memakainya.
     */
    public function petunjukKunci(): ?string
    {
        $kunci = $this->kunciApi();

        if (blank($kunci)) {
            return null;
        }

        return self::PENANDA_KUNCI.mb_substr($kunci, -4);
    }
}
