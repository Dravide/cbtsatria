<?php

namespace App\Support\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Klien satu bentuk API: chat completions yang kompatibel dengan OpenAI.
 *
 * =====================================================================
 * Mengapa SATU bentuk, bukan satu adapter per penyedia
 * =====================================================================
 * OpenAI, OpenRouter, Groq, DeepSeek, Together, LM Studio, dan Ollama
 * (sejak `/v1`) semuanya menerima `POST {base_url}/chat/completions` dengan
 * `Authorization: Bearer`. Menulis tujuh adapter berarti tujuh tempat yang
 * bisa berbeda diam-diam, tujuh test, dan tujuh kesempatan salah membaca
 * dokumentasi.
 *
 * Yang berbeda di antara mereka hanya nama model dan apakah
 * `response_format` diterima - dan yang kedua ditangani sebagai fallback di
 * bawah, bukan sebagai cabang per penyedia.
 *
 * =====================================================================
 * Kelas ini tidak tahu sekolah, Guru, maupun soal
 * =====================================================================
 * Ia menerima konfigurasi dan pesan, mengembalikan array. Semua keputusan
 * tentang APA yang diminta dan BAGAIMANA hasilnya dipakai tinggal di modul
 * Question. Itu bukan kerapian: dengan begitu kelas ini bisa diuji tanpa
 * satu pun baris basis data, dan `Http::fake()` cukup untuk seluruh
 * perilakunya.
 *
 * =====================================================================
 * Yang tidak pernah ditulis ke log
 * =====================================================================
 * Kunci API, header permintaan, isi pesan, dan jawaban mentah. Log yang
 * memuat kunci API adalah kunci API yang bocor ke semua orang yang bisa
 * membuka berkas log - termasuk lewat laman galat, tiket dukungan, dan
 * salinan basis data. Yang dicatat hanya status HTTP dan nama model.
 */
class KlienAi
{
    /**
     * Batas waktu permintaan penuh, detik.
     *
     * Guru MENUNGGU di depan layar dengan modal terbuka. 45 detik adalah
     * batas di mana ia masih mengira aplikasinya bekerja; lebih dari itu ia
     * menutup tab, dan permintaannya tetap ditagih.
     */
    public const BATAS_WAKTU = 45;

    /**
     * Batas waktu menyambung, detik.
     *
     * Dipisahkan dari batas penuh supaya "penyedia mati" bisa dibedakan dari
     * "modelnya lambat": yang pertama gagal dalam 10 detik dengan pesan yang
     * benar, yang kedua tetap menunggu sampai 45.
     */
    public const BATAS_SAMBUNG = 10;

    /**
     * Suhu rendah: soal bukan cerita.
     *
     * Model yang kreatif menghasilkan soal yang menarik dan kunci jawaban
     * yang tidak konsisten dengan opsinya.
     */
    public const SUHU = 0.4;

    /**
     * Kirim percakapan, kembalikan isi `content` yang sudah diurai sebagai JSON.
     *
     * @param  array{base_url:string, model:string, api_key:string, batas_token?:int}  $konfigurasi
     * @param  array<int, array{role:string, content:string}>  $pesan
     * @return array<string, mixed>
     *
     * @throws GalatPenyedia
     */
    public function kirim(array $konfigurasi, array $pesan): array
    {
        $url = rtrim($konfigurasi['base_url'], '/').'/chat/completions';

        $body = [
            'model' => $konfigurasi['model'],
            'messages' => $pesan,
            'temperature' => self::SUHU,
            'max_tokens' => (int) ($konfigurasi['batas_token'] ?? 1500),
            // Diminta hanya bila penyedia mendukungnya; lihat fallback di
            // bawah. Tanpa ini, model sering membungkus JSON dalam kalimat
            // pengantar, dan penguraiannya gagal pada keluaran yang
            // sebenarnya benar.
            'response_format' => ['type' => 'json_object'],
        ];

        $tanggapan = $this->kirimPermintaan($url, $konfigurasi['api_key'], $body, $konfigurasi['model']);

        // ---------------------------------------------------------------
        // Fallback `response_format`
        // ---------------------------------------------------------------
        // Sebagian penyedia menolak parameter ini dengan 400/422 karena
        // mereka tidak mengenalnya. Tanpa fallback, penyedia yang sepenuhnya
        // sah tampak "tidak bisa dipakai" - dan Admintidak punya cara
        // mengetahui bahwa yang salah hanyalah satu kunci opsional.
        //
        // Satu percobaan ulang, bukan perulangan: penyedia yang menolak dua
        // kali dengan alasan berbeda bukan masalah parameter ini, dan
        // setiap percobaan tambahan menagih sekolah.
        if ($this->menolakResponseFormat($tanggapan->status(), $tanggapan->body())) {
            unset($body['response_format']);

            $tanggapan = $this->kirimPermintaan($url, $konfigurasi['api_key'], $body, $konfigurasi['model']);
        }

        if ($tanggapan->failed()) {
            Log::warning('Penyedia AI menolak permintaan.', [
                'status' => $tanggapan->status(),
                'model' => $konfigurasi['model'],
            ]);

            throw GalatPenyedia::status($tanggapan->status(), mb_substr($tanggapan->body(), 0, 300, 'UTF-8'));
        }

        return $this->urai($tanggapan->json());
    }

    /**
     * Satu percobaan HTTP.
     *
     * Dipisahkan supaya fallback di atas tidak menyalin konfigurasi klien -
     * batas waktu yang tertinggal di satu salinan saja sudah cukup untuk
     * membuat satu jalur menggantung selamanya.
     */
    private function kirimPermintaan(string $url, string $kunci, array $body, string $model): Response
    {
        try {
            return Http::timeout(self::BATAS_WAKTU)
                ->connectTimeout(self::BATAS_SAMBUNG)
                ->withToken($kunci)
                ->acceptJson()
                ->asJson()
                ->post($url, $body);
        } catch (ConnectionException $e) {
            Log::warning('Penyedia AI tidak bisa dihubungi.', ['model' => $model]);

            throw GalatPenyedia::tidakTerhubung($e->getMessage());
        }
    }

    private function menolakResponseFormat(int $status, string $body): bool
    {
        if (! in_array($status, [400, 422], true)) {
            return false;
        }

        return str_contains(strtolower($body), 'response_format');
    }

    /**
     * Ambil `choices[0].message.content`, buang pagar kode, urai.
     *
     * Pagar ```` ```json ```` dibuang karena model tetap menambahkannya
     * meski diminta JSON murni - terutama saat penyedia tidak mendukung
     * `response_format` dan fallback di atas dipakai.
     *
     * @return array<string, mixed>
     */
    private function urai(mixed $json): array
    {
        $teks = data_get($json, 'choices.0.message.content');

        if (! is_string($teks) || trim($teks) === '') {
            throw GalatPenyedia::bukanJson('choices kosong atau content bukan teks');
        }

        $teks = trim($teks);
        $teks = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $teks) ?? $teks;

        try {
            $hasil = json_decode($teks, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw GalatPenyedia::bukanJson($e->getMessage());
        }

        if (! is_array($hasil)) {
            throw GalatPenyedia::bukanJson('content bukan objek JSON');
        }

        return $hasil;
    }
}
