<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode pengawas menjadi TERBACA KEMBALI - karena ia harus dicetak ke kartu.
 *
 * ---------------------------------------------------------------------
 * Mengapa kolomnya berubah, padahal baru saja dibuat
 * ---------------------------------------------------------------------
 * Migrasi sebelumnya (0017) memindahkan kode dari baris RUANG ke baris ORANG,
 * dan menyimpannya sebagai HASH satu arah (`code_hash`): kode hanya tampil
 * sekali, dan yang tersisa di basis data tidak bisa dibaca kembali. Itu benar
 * untuk satu pemakaian "tampil sekali".
 *
 * Pemilik produk kemudian meminta hal lain: kode pengawas harus SELALU
 * terlihat di laman Kepanitiaan, karena ia mau mencetaknya menjadi kartu
 * pengawas. Kode yang hanya tersimpan sebagai hash tidak bisa ditampilkan
 * lagi - dan tanpa kode yang bisa dibaca, tidak ada yang bisa dicetak ke
 * kartu. Karena itu bentuk simpannya berubah dari hash satu arah menjadi
 * ENKRIPSI dua arah (`code`): bisa dibaca kembali oleh aplikasi, tetapi tetap
 * tidak terbaca di dalam basis data.
 *
 * ---------------------------------------------------------------------
 * Mengapa terenkripsi, bukan teks biasa
 * ---------------------------------------------------------------------
 * Doktrinnya tidak berubah sedikit pun: kredensial tidak boleh ada di dalam
 * basis data dalam bentuk yang bisa dibaca siapa pun yang memegang dump-nya.
 * Enkripsi Laravel (APP_KEY) memenuhi keduanya sekaligus - aplikasi bisa
 * menampilkan kodenya untuk dicetak, sementara backup, dump, atau satu query
 * yang bocor tetap hanya melihat teks acak.
 *
 * Kolomnya sengaja diberi nama `code`, bukan `code_plain`, supaya tidak ada
 * yang mengira ia memang teks biasa. Yang membaca kolom ini harus lewat cast
 * `encrypted` di model - dan cast itulah yang menjamin isinya tidak pernah
 * tersimpan terbaca.
 *
 * ---------------------------------------------------------------------
 * `code_hash` DIJATUHKAN, dan itu bukan kerapian
 * ---------------------------------------------------------------------
 * Satu sumber kode untuk satu pintu. Membiarkan `code_hash` di samping `code`
 * berarti dua kolom yang sama-sama bisa dipakai memeriksa kode - dan suatu
 * hari kode yang dibaca dari satu kolom tidak cocok dengan yang ditampilkan
 * dari kolom lain. Kode lama yang sudah terbit tidak bisa dipulihkan dari
 * hash-nya, dan itu tidak apa-apa: kolom `code` yang kosong lebih jujur
 * daripada hash yatim yang tidak bisa dicetak. Admin menerbitkan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_committee_members', function (Blueprint $table) {
            // Panjang 255 cukup: enkripsi Laravel membungkus nilai dengan
            // base64 + IV + MAC, sehingga enam digit menjadi sekitar 200-an
            // karakter. Menyisakan ruang supaya tidak terpotong diam-diam.
            $table->string('code')->nullable()->after('phone');
        });

        Schema::table('event_committee_members', function (Blueprint $table) {
            $table->dropColumn('code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('event_committee_members', function (Blueprint $table) {
            // Kembali ke hash satu arah: nilai `code` yang terenkripsi tidak
            // bisa "di-hash ulang" menjadi kode yang sama tanpa membacanya,
            // dan mengarang satu berarti menulis kredensial yang tidak pernah
            // diterbitkan siapa pun. Kolom hash yang kosong lebih baik.
            $table->string('code_hash')->nullable()->after('phone');
        });

        Schema::table('event_committee_members', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
