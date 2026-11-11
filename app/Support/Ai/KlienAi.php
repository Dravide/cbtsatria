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
     * Langit-langit anggaran token untuk percobaan ulang setelah model kena
     * `max_tokens`. Dua kali lipat dari batas biasa, sejalan dengan
     * `PenulisSoalAi::BATAS_TOKEN_MAKS` (16000) - angka yang sama, karena
     * pelepasan itu memang milik satu permintaan yang sama.
     */
    public const BATAS_TOKEN_ULANG = 16000;

    /**
     * Kirim percakapan, kembalikan isi `content` yang sudah diurai sebagai JSON.
     *
     * `batas_waktu` boleh diturunkan pemanggil. Satu soal selesai jauh di
     * bawah 45 detik, tetapi sepuluh soal dalam satu keluaran butuh waktu
     * yang jauh lebih panjang - dan batas yang tetap membuat permintaan
     * yang sebenarnya akan berhasil diputus di tengah jalan, lalu ditagih
     * penuh tanpa hasil. Nilai yang tidak masuk akal diabaikan, bukan
     * dipercaya: konstanta di atas tetap menjadi lantai dan langit-langitnya.
     *
     * @param  array{base_url:string, model:string, api_key:string, batas_token?:int, batas_waktu?:int}  $konfigurasi
     * @param  array<int, array{role:string, content:string}>  $pesan
     * @return array<string, mixed>
     *
     * @throws GalatPenyedia
     */
    public function kirim(array $konfigurasi, array $pesan): array
    {
        $url = rtrim($konfigurasi['base_url'], '/').'/chat/completions';

        $batasWaktu = max(
            self::BATAS_WAKTU,
            (int) ($konfigurasi['batas_waktu'] ?? self::BATAS_WAKTU),
        );

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

        $tanggapan = $this->kirimPermintaan($url, $konfigurasi['api_key'], $body, $konfigurasi['model'], $batasWaktu);

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

            $tanggapan = $this->kirimPermintaan($url, $konfigurasi['api_key'], $body, $konfigurasi['model'], $batasWaktu);
        }

        if ($tanggapan->failed()) {
            Log::warning('Penyedia AI menolak permintaan.', [
                'status' => $tanggapan->status(),
                'model' => $konfigurasi['model'],
            ]);

            throw GalatPenyedia::status($tanggapan->status(), mb_substr($tanggapan->body(), 0, 300, 'UTF-8'));
        }

        // ---------------------------------------------------------------
        // Model yang menghabiskan anggaran tokennya untuk BERPIKIR tidak gagal;
        // ia belum sempat menjawab
        // ---------------------------------------------------------------
        // Model penalaran (seperti deepseek dengan `reasoning_content`) menulis
        // pemikirannya lebih dulu, dan `max_tokens` 1500 untuk SATU soal mudah
        // habis ditelan berpikir sebelum baris JSON pertama lahir - `finish_reason`
        // menjadi "length", `content` tetap null atau terpotong di tengah objek,
        // dan `urai()` melaporkan "bukan JSON" pada jawaban yang sebenarnya tidak
        // pernah ada. Pengamatan langsung pada penyedia yang dipakai (2026-11-11):
        // jenis dengan penalaran lebih panjang (isian rumpang) kena jauh lebih
        // sering daripada pilihan ganda, dan `jumlah` yang lebih besar lolos
        // karena anggarannya ikut dikalikan di `PenulisSoalAi`.
        //
        // Menggandakan anggaran lalu bertanya sekali lagi adalah perbaikan yang
        // hanya menyentuh keadaan "benar-benar kehabisan token": `finish_reason`
        // length adalah satu-satunya sinyalnya, dan jawaban yang selesai (`stop`)
        // tidak pernah diulang. Anggaran kedua dipakai apa yang dikirim, dan
        // percobaan ketiga tidak ada - model yang masih belum selesai di kolom
        // yang lebih besar bukan persoalan anggaran, melainkan output yang cacat.
        if ($this->kehabisanAnggaran($tanggapan)) {
            $body['max_tokens'] = min(
                self::BATAS_TOKEN_ULANG,
                ((int) ($body['max_tokens'] ?? 1500)) * 2,
            );

            Log::warning('Model AI kehabisan max_tokens; mencoba ulang dengan anggaran lebih besar.', [
                'model' => $konfigurasi['model'],
                'batas_token' => $body['max_tokens'],
            ]);

            $tanggapan = $this->kirimPermintaan($url, $konfigurasi['api_key'], $body, $konfigurasi['model'], $batasWaktu);

            if ($tanggapan->failed()) {
                Log::warning('Penyedia AI menolak permintaan ulang.', [
                    'status' => $tanggapan->status(),
                    'model' => $konfigurasi['model'],
                ]);

                throw GalatPenyedia::status($tanggapan->status(), mb_substr($tanggapan->body(), 0, 300, 'UTF-8'));
            }
        }

        return $this->urai($tanggapan->json());
    }

    /**
     * Model berhenti karena kehabisan anggaran token, bukan karena selesai.
     *
     * `finish_reason` "length" adalah sinyal dari penyedia bahwa respons
     * berhenti di tengah batas `max_tokens`. Alasan lain ("stop",
     * "tool_calls", "content_filter") bukan anggaran, dan tidak pantas diulang
     * dengan anggaran yang lebih besar - jawaban yang ditolak kebijakan tidak
     * akan berubah hanya karena diberi lebih banyak token.
     */
    private function kehabisanAnggaran(Response $tanggapan): bool
    {
        return $tanggapan->status() === 200
            && data_get($tanggapan->json(), 'choices.0.finish_reason') === 'length';
    }

    /**
     * Satu percobaan HTTP.
     *
     * Dipisahkan supaya fallback di atas tidak menyalin konfigurasi klien -
     * batas waktu yang tertinggal di satu salinan saja sudah cukup untuk
     * membuat satu jalur menggantung selamanya.
     */
    private function kirimPermintaan(string $url, string $kunci, array $body, string $model, int $batasWaktu): Response
    {
        try {
            return Http::timeout($batasWaktu)
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
