<?php

namespace App\Support\Ai;

use RuntimeException;

/**
 * Kegagalan yang datang dari penyedia AI, bukan dari aplikasi ini.
 *
 * =====================================================================
 * Mengapa kelas sendiri, dan bukan `RuntimeException` biasa
 * =====================================================================
 * Controller harus bisa membedakan "penyedia menjawab 401" dari "kode kita
 * salah". Yang pertama adalah keadaan yang bisa diperbaiki Admin dalam satu
 * menit; yang kedua adalah 500 yang harus dilihat pengembang. Tanpa tipe
 * yang terpisah, satu-satunya pilihan adalah memeriksa pesan teksnya - dan
 * pesan teks berubah setiap kali kalimatnya diperbaiki.
 *
 * =====================================================================
 * Pesan di sini SELALU boleh dibaca Guru
 * =====================================================================
 * Karena itu ia ditulis dalam bahasa Indonesia, menyebut apa yang bisa
 * dilakukan, dan TIDAK pernah memuat: kunci API, header permintaan, body
 * permintaan, atau jawaban mentah penyedia. Jawaban mentah bisa memuat
 * potongan prompt, nama model internal, atau alamat yang bukan urusan Guru -
 * dan ia akan tampil di modal builder yang mungkin sedang diproyeksikan di
 * ruang guru.
 *
 * Pesan teknis mentahnya tetap berguna untuk pengembang, jadi ia disimpan di
 * properti terpisah dan hanya dibaca saat menulis log.
 */
class GalatPenyedia extends RuntimeException
{
    /**
     * @param  string  $pesan  Kalimat yang boleh dilihat Guru.
     * @param  string|null  $rincian  Untuk log saja - tidak pernah ke layar.
     */
    public function __construct(string $pesan, public readonly ?string $rincian = null)
    {
        parent::__construct($pesan);
    }

    public static function belumDiatur(): self
    {
        return new self(
            'Generate AI belum diatur. Minta Admin mengisi Base URL, model, dan API key '
            .'di Pengaturan sekolah, lalu menyalakan AI di sana.'
        );
    }

    /**
     * Jawaban dengan status HTTP yang tidak diharapkan.
     *
     * 401 dan 403 dibedakan dari sisanya karena keduanya adalah kesalahan
     * konfigurasi yang paling sering: kunci salah ketik, atau model yang
     * tidak tersedia bagi kunci itu. Kalimatnya menunjuk ke tempat
     * perbaikannya, bukan sekadar menyebut angkanya.
     */
    public static function status(int $status, ?string $rincian = null): self
    {
        $pesan = match (true) {
            $status === 401 => 'Penyedia AI menolak kunci API (401). Periksa kembali API key di Pengaturan.',
            $status === 403 => 'Penyedia AI menolak akses ke model itu (403). Periksa nama model di Pengaturan.',
            $status === 404 => 'Alamat penyedia AI tidak ditemukan (404). Periksa Base URL di Pengaturan - '
                .'alamatnya harus berakhiran seperti /v1.',
            $status === 429 => 'Penyedia AI sedang membatasi permintaan (429). Tunggu sebentar, lalu coba lagi.',
            $status >= 500 => "Penyedia AI sedang bermasalah ({$status}). Coba lagi beberapa saat lagi.",
            default => "Penyedia AI menolak permintaan ({$status}). Coba lagi, atau hubungi Admin.",
        };

        return new self($pesan, $rincian);
    }

    public static function tidakTerhubung(?string $rincian = null): self
    {
        return new self(
            'Tidak bisa menghubungi penyedia AI. Periksa Base URL dan sambungan internet sekolah.',
            $rincian,
        );
    }

    public static function bukanJson(?string $rincian = null): self
    {
        return new self(
            'Penyedia AI menjawab, tetapi bukan dalam bentuk JSON yang diminta. '
            .'Modelnya mungkin tidak mendukung mode JSON - coba model lain di Pengaturan.',
            $rincian,
        );
    }
}
