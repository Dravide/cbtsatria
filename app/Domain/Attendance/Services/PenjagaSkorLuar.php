<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\LeisureGameScore;

/**
 * Pemeriksa kiriman skor permainan - satu-satunya pintu yang memutuskan.
 *
 * =====================================================================
 * Skor DIHITUNG ULANG, bukan dipercaya
 * =====================================================================
 * Peramban mengirim dua angka: jarak yang ditempuh dan durasi mainnya.
 * Skornya sendiri TIDAK dikirim, dan itu keputusan yang membuat seluruh
 * penjagaan ini mungkin: angka yang dihitung peramban adalah angka yang bisa
 * disunting peramban. Yang tersimpan adalah hasil hitung di sini.
 *
 * =====================================================================
 * Dua aturan, dan keduanya harus LONGGAR
 * =====================================================================
 * Penjaga yang terlalu ketat menolak anak yang mainnya bagus - dan anak yang
 * ditolak setelah main 90 detik adalah anak yang berhenti memainkannya, lalu
 * berhenti mempercayai angka apa pun di aplikasi ini. Dua ambang di bawah
 * karena itu diletakkan jauh di luar permainan yang jujur, supaya yang
 * tertangkap benar-benar kiriman palsu:
 *
 *   1. TERLALU CEPAT - kurang dari `DURASI_MINIMAL_MS` tidak mungkin cukup
 *      untuk satu pun rintangan. Skor wajar terendah lahir sekitar detik
 *      kelima.
 *   2. TIDAK SESUAI - jarak per detik melampaui `UNIT_PER_DETIK_MAKS`. Batas
 *      ini diturunkan dari kenyataan paling dasar: rintangan bergerak ke
 *      kiri dengan kecepatan yang NAIK pelan-pelan, dan jarak hanya bisa
 *      bertambah selama pemain belum terbentur. Kiriman yang menjanjikan
 *      jarak ribuan dalam tiga detik tidak bisa dihasilkan permainannya.
 *
 * =====================================================================
 * Apa yang TIDAK diperiksa di sini
 * =====================================================================
 * Kiriman ganda berturut-turut tidak ditolak di sini, melainkan oleh
 * `throttle` di rutenya. Itu pemisahan yang disengaja: penolakan di sini
 * bersifat PERMANEN (barisnya tetap tersimpan sebagai ditolak), sementara
 * kiriman cepat bisa saja anak yang menekan "Main lagi" lalu terbentur
 * seketika. Yang satunya aturan data, yang lain pembatas laju - dan
 * mencampurnya membuat kiriman sah tercatat seolah curang.
 */
final class PenjagaSkorLuar
{
    /**
     * Durasi minimal yang dianggap mustahil.
     *
     * ---------------------------------------------------------------------
     * Dikalibrasi ulang 2026-11-15, dari 3.000 menjadi 400
     * ---------------------------------------------------------------------
     * Nilai pertama (tiga detik) dipilih dengan mengira-ngira, dan ia menolak
     * SETIAP permainan jujur yang berakhir cepat. Data sungguhan dari
     * panggung yang benar-benar dimainkan: sepuluh kiriman berturut-turut
     * berdurasi 592-1.738 ms, semuanya ditolak.
     *
     * Rintangan pertama bisa tiba kurang dari satu detik setelah mulai, dan
     * anak yang terbentur di situ memang belum bermain lama - bukan berarti
     * kirimannya karangan. Batas 400 ms hanya menangkap kiriman yang lebih
     * pendek dari waktu yang dibutuhkan satu bingkai penuh untuk dihitung.
     */
    public const DURASI_MINIMAL_MS = 400;

    /**
     * Satuan jarak per detik.
     *
     * ---------------------------------------------------------------------
     * Dikalibrasi ulang 2026-11-15, dari 160 menjadi 900
     * ---------------------------------------------------------------------
     * Nilai pertamanya dipilih dengan mengira-ngira, dan ia menolak SETIAP
     * permainan jujur. Buktinya dari data sungguhan: kiriman berturut-turut
     * dari panggung yang benar-benar dimainkan menghasilkan laju 373-376
     * satuan per detik - konsisten, bukan acak - dan semuanya ditolak.
     *
     * Sebabnya, jarak dihitung dari GERAK RINTANGAN di panggungnya: satu
     * bingkai menggeser rintangan sebesar `kecepatan × skala`, dan jaraknya
     * ditambah `geser / skala` - sehingga `skala` saling menghapus dan yang
     * tersisa adalah `kecepatan × 60` per detik. Kecepatan awalnya 6,2, jadi
     * 372 satuan per detik, dan itulah angka yang terlihat di data. Angka
     * 160 adalah tebakan tanpa menghitungnya, dan tebakan itu salah lebih
     * dari dua kali lipat.
     *
     * Batas 900 karena itu 2,4x laju awal - dan panggungnya mempercepat
     * sangat pelan (`PERCEPATAN` 0,0012 per bingkai), sehingga permainan
     * yang berlangsung dua menit pun tidak mendekatinya. Yang ditangkap
     * tetap kiriman yang mengarang angka, bukan pemain hebat.
     */
    public const UNIT_PER_DETIK_MAKS = 900;

    /**
     * Skor dari jarak dan durasi.
     *
     * Rumusnya SENGAJA sesederhana mungkin dan sama dengan yang dipakai
     * panggungnya: satu satuan jarak, satu angka skor. Setiap pembobotan yang
     * lebih rumit membuat dua tempat yang harus menghitung dengan cara yang
     * sama - dan yang kedua selalu tertinggal saat yang pertama diubah.
     */
    public function hitungSkor(int $distance): int
    {
        return max(0, $distance);
    }

    /**
     * Alasan penolakan, atau `null` bila kiriman diterima.
     *
     * @return string|null Nama konstanta `LeisureGameScore::TOLAK_*`.
     */
    public function periksa(int $distance, int $durationMs): ?string
    {
        if ($durationMs < self::DURASI_MINIMAL_MS) {
            return LeisureGameScore::TOLAK_TERLALU_CEPAT;
        }

        // Detik, bukan milidetik, supaya batasnya terbaca sebagai "per
        // detik" - dan pembagiannya di sini satu-satunya tempat yang perlu
        // ingat satuannya.
        $perDetik = $distance / ($durationMs / 1000);

        if ($perDetik > self::UNIT_PER_DETIK_MAKS) {
            return LeisureGameScore::TOLAK_TIDAK_SESUAI;
        }

        return LeisureGameScore::DITERIMA;
    }
}
