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
    /** Di bawah ini, permainannya belum mungkin menyentuh rintangan kedua. */
    public const DURASI_MINIMAL_MS = 3_000;

    /**
     * Satuan jarak per detik.
     *
     * Angka ini SENGAJA jauh di atas permainan normal: kecepatan awal
     * panggung menghasilkan sekitar 40 unit/detik, dan batas ini 4x lipatnya.
     * Yang ditangkap bukan pemain hebat, melainkan kiriman yang mengarang
     * angka.
     */
    public const UNIT_PER_DETIK_MAKS = 160;

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
