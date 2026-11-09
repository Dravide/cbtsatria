<?php

namespace App\Support\Ai;

/**
 * Pemeriksa alamat penyedia AI sebelum server menghubunginya.
 *
 * =====================================================================
 * Mengapa alamat ini diperiksa, padahal Admin yang mengisinya
 * =====================================================================
 * `base_url` bukan alamat yang dibuka peramban Admin - ia alamat yang
 * dihubungi SERVER. Perbedaan itu mengubah sifatnya: isian yang salah tidak
 * berhenti di layar Admin, ia menjadi permintaan HTTP yang keluar dari
 * jaringan sekolah.
 *
 * Tanpa pemeriksaan, satu isian `http://169.254.169.254/latest/meta-data`
 * membuat server membacakan kredensial cloud ke jawaban yang lalu ditampilkan
 * di layar Guru. Itu bukan ancaman teoretis: ia skenario SSRF paling klasik,
 * dan yang membukanya adalah orang yang memang punya akses ke laman
 * Pengaturan. Yang dicegah di sini bukan penyusup dari luar, melainkan Admin
 * yang menyalin alamatnya dari suatu tempat tanpa tahu apa yang ia salin -
 * dan, bila akunnya dipakai orang lain, pemakaian akun itu sebagai batu
 * loncatan.
 *
 * =====================================================================
 * Yang diloloskan, dan mengapa
 * =====================================================================
 *   https                       selalu
 *   http + localhost/127.0.0.1  hanya di luar production
 *   alamat privat (10/8, 172.16/12, 192.168/16, 169.254/16, ::1, fc00::/7)
 *                               hanya bila APP_ENV=local
 *
 * Baris kedua ada karena LM Studio dan Ollama berjalan di
 * `http://localhost:1234` di laptop yang sama - dan sekolah yang ingin
 * menyusun soal tanpa biaya memang pantas mendapatkannya. Baris ketiga ada
 * karena sekolah dengan server model di jaringan dalamnya adalah keadaan yang
 * sah, tetapi hanya di mesin pengembangan; di production, alamat privat
 * berarti permintaan yang tidak pernah keluar dari jaringan itu.
 *
 * =====================================================================
 * Batas yang harus dinyatakan, bukan diklaim lebih
 * =====================================================================
 * Resolusi DNS terjadi DUA kali: sekali di sini, sekali saat `Http`
 * menghubungi. Antara keduanya, sebuah nama domain bisa diarahkan ulang ke
 * alamat privat - DNS rebinding. Menutupnya sepenuhnya menuntut pinning IP
 * di tingkat transport, yang tidak disediakan Guzzle tanpa menulis handler
 * sendiri.
 *
 * Jadi yang ditutup di sini: salah ketik, alamat yang jelas menuju jaringan
 * dalam, dan `http` tanpa TLS. Yang TIDAK ditutup: penyedia jahat yang
 * sengaja membalas alamat berbeda. Untuk sekolah yang memasang penyedianya
 * sendiri, risiko itu sudah berada di luar cakupan aplikasi ini.
 */
final class UrlPenyedia
{
    /** @var array<int, string> */
    private const HOST_LOKAL = ['localhost', '127.0.0.1', '::1'];

    /**
     * @throws GalatPenyedia
     */
    public static function pastikan(string $url): void
    {
        $bagian = parse_url($url);

        // `filter_var($url, FILTER_VALIDATE_URL)` saja tidak cukup: ia
        // meloloskan `http://localhost:1234` tetapi juga tidak memberi tahu
        // apakah hostnya alamat IP - dan justru itu yang harus diperiksa.
        if ($bagian === false || blank($bagian['host'] ?? null)) {
            throw new GalatPenyedia('Base URL tidak sah. Tulis alamat lengkap, mis. https://api.openai.com/v1.');
        }

        $skema = strtolower((string) ($bagian['scheme'] ?? ''));
        $host = strtolower((string) $bagian['host']);
        $lokal = in_array($host, self::HOST_LOKAL, true) || self::alamatPrivat($host);

        if ($skema === 'https') {
            // https ke alamat privat tetap ditolak di production: TLS tidak
            // membuatnya lebih sah, hanya lebih sulit diintip.
            if ($lokal && ! app()->environment(['local', 'testing'])) {
                throw new GalatPenyedia('Base URL menunjuk ke alamat jaringan dalam. Alamat itu tidak boleh dipakai di server ini.');
            }

            return;
        }

        if ($skema !== 'http') {
            throw new GalatPenyedia('Base URL harus diawali https://.');
        }

        // http hanya untuk model yang berjalan di mesin yang sama - LM Studio
        // dan Ollama tidak punya sertifikat, dan menuntut https di sini
        // berarti fitur ini tidak bisa dipakai tanpa biaya sama sekali.
        if (in_array($host, self::HOST_LOKAL, true) && app()->environment(['local', 'testing'])) {
            return;
        }

        if ($lokal) {
            // Alamat privat lewat http berarti lalu lintas tanpa TLS di
            // jaringan sekolah - dan di production, alamat privat sekaligus
            // berarti SSRF. Ditolak dengan satu kalimat yang berlaku untuk
            // keduanya.
            throw new GalatPenyedia('Alamat jaringan dalam hanya boleh dipakai lewat https. Gunakan alamat publik penyedia, atau jalankan modelnya di localhost.');
        }

        throw new GalatPenyedia('Base URL harus diawali https://. http:// hanya diterima untuk localhost.');
    }

    /**
     * Apakah host berupa alamat IP di ruang privat / loopback / link-local.
     *
     * `filter_var(..., FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE |
     * FILTER_FLAG_NO_RES_RANGE)` mengembalikan string bila alamatnya PUBLIK.
     * Jadi nilainya `false` berarti "IP, tetapi di ruang yang dilarang" -
     * kecuali ia bukan IP sama sekali, dan itu juga `false`. Keduanya
     * diperiksa terpisah supaya nama domain biasa tidak ikut tertolak.
     */
    private static function alamatPrivat(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // IPv6 berkurung: `parse_url` mengembalikan `[::1]` apa adanya pada
        // sebagian versi PHP.
        $ip = trim($host, '[]');

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
