<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\PengaturanAi;
use Illuminate\Support\Facades\Crypt;

/**
 * Menyimpan dan membaca konfigurasi penyedia AI satu sekolah.
 *
 * =====================================================================
 * Satu-satunya pintu masuk kolom `api_key_encrypted`
 * =====================================================================
 * Kolom itu sengaja TIDAK fillable (lihat `PengaturanAi`), sehingga satu-
 * satunya cara menulisnya adalah lewat kelas ini. Yang membuat itu penting
 * bukan kerapian: enkripsi harus terjadi SEBELUM nilai menyentuh model, dan
 * satu pemanggil yang menulis kuncinya langsung akan menyimpannya terbuka -
 * di kolom yang justru dinamai "encrypted", sehingga tidak ada yang curiga.
 *
 * =====================================================================
 * Kunci kosong berarti "jangan ubah", bukan "kosongkan"
 * =====================================================================
 * Dan itu aturan yang paling menentukan di kelas ini. Kunci API tidak pernah
 * dikirim ke peramban (hanya empat huruf terakhirnya), jadi setiap kali Admin
 * membuka tab AI, kolom kuncinya memang KOSONG. Kalau kosong berarti
 * "kosongkan", Admin yang memperbaiki nama model - satu tindakan yang tidak
 * ada hubungannya dengan kunci - akan mematikan Generate AI untuk seluruh
 * sekolah, dan pesan yang ia lihat hanya "tersimpan".
 *
 * Mengosongkan kunci tetap mungkin, tetapi sebagai tindakan tersendiri
 * (`hapusKunci()`) yang disebut namanya di layar.
 *
 * =====================================================================
 * Mengapa service di Tenancy, bukan method controller
 * =====================================================================
 * Penulisnya Admin, pembacanya Question, dan keduanya tidak boleh saling
 * menunjuk. Menaruh logika ini di `PengaturanAiController` berarti
 * `PenulisSoalAi` harus mengimpor `Admin\Http` - tepat yang dilarang
 * `DomainBoundariesTest`. Rumahnya di sini, dan pola itu sudah dipakai
 * `PengaturanBawaan`.
 */
class PengaturanAiSekolah
{
    /**
     * Konfigurasi milik satu sekolah, atau null bila belum pernah diatur.
     *
     * `$schoolId` boleh dikosongkan: saat dipanggil dari konteks Guru,
     * `SchoolScope` sudah menyaringnya, sehingga `first()` mengambil baris
     * milik sekolah Guru itu. Pemeriksaan tenant tetap dilakukan kueri, bukan
     * oleh pemanggil.
     */
    public function ambil(?int $schoolId = null): ?PengaturanAi
    {
        return PengaturanAi::query()
            ->when($schoolId !== null, fn ($q) => $q->where('school_id', $schoolId))
            ->first();
    }

    /**
     * Simpan konfigurasi.
     *
     * @param  array{base_url?:?string, model?:?string, api_key?:?string, aktif?:bool, batas_token?:int}  $data
     */
    public function simpan(int $schoolId, array $data, ?int $oleh = null): PengaturanAi
    {
        $pengaturan = $this->ambil($schoolId) ?? new PengaturanAi(['school_id' => $schoolId]);

        // Kunci hanya disentuh bila ada isi. `filled()` dan bukan `!== null`:
        // form mengirim string kosong, dan string kosong adalah "tidak
        // diisi ulang", bukan kunci kosong - lihat catatan kepala kelas.
        $kunci = trim((string) ($data['api_key'] ?? ''));

        $nilai = [
            'base_url' => filled($data['base_url'] ?? null) ? trim((string) $data['base_url']) : null,
            'model' => filled($data['model'] ?? null) ? trim((string) $data['model']) : null,
            'aktif' => (bool) ($data['aktif'] ?? false),
            'batas_token' => (int) ($data['batas_token'] ?? $pengaturan->batas_token ?? 1500),
            'diperbarui_oleh' => $oleh,
        ];

        if ($kunci !== '') {
            $nilai['api_key_encrypted'] = Crypt::encryptString($kunci);
        }

        $pengaturan->forceFill($nilai)->save();

        return $pengaturan->refresh();
    }

    /**
     * Buang kunci API, dan matikan AI bersamanya.
     *
     * AI yang menyala tanpa kunci akan gagal di setiap klik Generate dengan
     * pesan yang membingungkan Guru. Mematikan keduanya di satu tempat
     * membuat keadaan "tanpa kunci" tidak pernah terlihat sebagai "AI rusak".
     */
    public function hapusKunci(int $schoolId): bool
    {
        $pengaturan = $this->ambil($schoolId);

        if ($pengaturan === null) {
            return false;
        }

        $pengaturan->forceFill([
            'api_key_encrypted' => null,
            'aktif' => false,
        ])->save();

        return true;
    }
}
