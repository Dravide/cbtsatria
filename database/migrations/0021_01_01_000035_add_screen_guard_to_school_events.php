<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiga penjaga layar sebagai kebijakan EVENT - susulan kendali perangkat siswa.
 *
 * ---------------------------------------------------------------------
 * Mengapa kolom di event, bukan bacaan langsung ke pengaturan sekolah
 * ---------------------------------------------------------------------
 * Ketiganya lahir di peramban (`resources/js/Components/layar.ts` dan tanda
 * air di `Siswa/Kerjakan.tsx`) dan sampai sekarang menyala untuk SEMUA ujian:
 * layar yang menyempit selalu dilaporkan sebagai `layar_terbagi`, dan tanda
 * air selalu dicetak. Keduanya bukan tanpa biaya.
 *
 *   - Ponsel lipat, rotasi, dan peramban dalam aplikasi lain bisa
 *     menghasilkan viewport sempit tanpa siapa pun membelah layar.
 *   - Tanda air menutupi isi soal dengan teks yang diulang delapan kali, dan
 *     pada layar 5 inci ia benar-benar mengurangi ruang baca.
 *
 * Sekolah yang ujiannya di ponsel pribadi butuh keduanya mati; sekolah yang
 * ujiannya di laboratorium butuh keduanya menyala. Satu angka sekolah tidak
 * bisa melayani keduanya.
 *
 * Yang membuatnya kolom EVENT, bukan bacaan langsung ke `schools.settings`:
 * kalimat di plate 23 - "Event yang sudah dibuat menyimpan nilainya sendiri
 * dan tidak ikut berubah" - akan menjadi bohong. Admin yang mematikan deteksi
 * di tengah ujian akan mengubah aturan main 300 anak yang sedang mengerjakan,
 * dan tidak ada satu pun tanda di layar yang memberi tahu dia.
 *
 * ---------------------------------------------------------------------
 * `detect_split_screen` dan `detect_float_window` DIPISAH
 * ---------------------------------------------------------------------
 * Keduanya diukur dari dua sisi berbeda: layar terbagi memakan LEBAR
 * (`innerWidth` separuh `screen.width`), jendela mengambang memakan TINGGI.
 * Menggabungkannya menjadi satu kolom berarti sekolah yang memaafkan jendela
 * mengambang - karena sebagian peramban ponsel memang begitu - ikut
 * kehilangan deteksi layar terbagi, yang justru layak dilaporkan.
 *
 * ---------------------------------------------------------------------
 * Bawaannya TRUE untuk ketiganya
 * ---------------------------------------------------------------------
 * Bukan karena lebih aman, melainkan karena itulah perilaku sebelum kolom
 * ini ada. Kolom baru yang bawaannya mematikan akan memadamkan tiga
 * penjagaan di seluruh sekolah pada satu kali migrasi, tanpa seorang pun
 * memintanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_events', function (Blueprint $table) {
            // Layar yang menyempit dari sisi LEBAR. Lihat `layar.lebar()`.
            $table->boolean('detect_split_screen')->default(true)->after('enforce_fullscreen');

            // Layar yang menyempit dari sisi TINGGI - jendela mengambang,
            // atau jendela aplikasi lain yang menutupi sebagian soal.
            $table->boolean('detect_float_window')->default(true)->after('detect_split_screen');

            // Tanda air nama dan nomor induk di atas soal. Satu-satunya
            // penangkal tangkapan layar yang mungkin: peramban tidak memberi
            // tahu bahwa tombolnya ditekan.
            $table->boolean('show_watermark')->default(true)->after('detect_float_window');
        });
    }

    public function down(): void
    {
        Schema::table('school_events', function (Blueprint $table) {
            $table->dropColumn(['detect_split_screen', 'detect_float_window', 'show_watermark']);
        });
    }
};
