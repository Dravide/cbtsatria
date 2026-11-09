<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahun ajaran sasaran satu berkas impor master data.
 *
 * =====================================================================
 * Mengapa kolom, bukan kolom di dalam berkasnya
 * =====================================================================
 * Nama kelas unik PER TAHUN AJARAN (`classes_academic_year_id_name_unique`),
 * bukan per sekolah - "X-1" muncul lagi setiap tahun dan itu bukan duplikat.
 * Karena itu baris kelas selalu butuh satu tahun ajaran yang mengikatnya, dan
 * pertanyaannya hanya dari mana tahun itu datang.
 *
 * Jawabannya: dari LAMAN, bukan dari berkas. Laman Kelas sudah memilih satu
 * tahun ajaran di bar atasnya, dan wireframe plate 07 menandaskan mengapa:
 * "berganti TA berarti berganti daftar kelas, bukan menyaring daftar yang
 * sama." Berkas yang membawa kolom `tahun` sendiri berarti dua tempat memilih
 * tahun yang sama, dan berkas yang lupa mengisinya tidak punya jawaban yang
 * benar - menebaknya berarti menulis kelas ke tahun ajaran yang salah, diam
 * -diam. Yang dipilih di layar terlihat di layar.
 *
 * =====================================================================
 * Mengapa kolomnya di baris BATCH, bukan hanya di query laman
 * =====================================================================
 * Impor berjalan tiga langkah, dan Konfirmasi datang lebih lambat - bisa
 * sesudah Admin berganti tahun ajaran di tab lain. Kalau tahunnya hanya hidup
 * di URL, Konfirmasi akan menulis ke tahun yang sedang terbuka di layar, bukan
 * ke tahun yang barisnya dipratinjau. Yang tersimpan di sini adalah yang
 * dipratinjau dan yang dikonfirmasi, dan keduanya tidak bisa berbeda.
 *
 * =====================================================================
 * Nullable, dan itu wajib
 * =====================================================================
 * Impor mapel dan impor ruang tidak punya tahun ajaran sama sekali. Kolom
 * NOT NULL dengan nilai bawaan berarti mengarang tahun ajaran untuk dua jenis
 * yang tidak membutuhkannya - dan baris lama mereka (yang sudah ada di basis
 * data sebelum migrasi ini) tidak punya nilai yang jujur untuk diisikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_import_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('academic_year_id')->nullable()->after('jenis');

            // RESTRICT, sama seperti seluruh tabel anak akademik sesudah Fase
            // 2: tahun ajaran yang masih mengikat baris impor tidak boleh
            // dihapus. Mengimpor kelas ke tahun ajaran yang sudah lenyap
            // adalah baris yang tidak bisa dibaca lagi.
            //
            // Composite, bukan `foreignId()->constrained()` biasa: `school_id`
            // ikut ke dalam kuncinya supaya tahun ajaran sekolah lain tidak
            // pernah bisa dirujuk - aturan yang sama dengan seluruh tabel
            // akademik.
            $table->foreign(['school_id', 'academic_year_id'])
                ->references(['school_id', 'id'])->on('academic_years')->restrictOnDelete();

            // =============================================================
            // Kunci idempotensi ikut memuat tahun ajaran
            // =============================================================
            // Tanpa ini, berkas kelas yang sama untuk tahun ajaran BERBEDA
            // ditolak sebagai "sudah pernah diunggah". Padahal itu impor yang
            // sah dan memang berbeda: nama kelas unik per tahun ajaran, dan
            // berkas "X-1..X-9" untuk 2026/2027 hampir selalu identik dengan
            // berkas untuk 2027/2028 - bentuk daftar kelas jarang berubah,
            // yang berubah isinya.
            //
            // =============================================================
            // Yang perlu diketahui tentang NULL di sini
            // =============================================================
            // MySQL menganggap setiap NULL berbeda di dalam unique index.
            // Untuk mapel dan ruang - yang tahunnya NULL - index ini karena
            // itu TIDAK menolak apa pun. Penjaga yang sebenarnya bekerja bagi
            // keduanya adalah pemeriksaan di `ImporMasterService::unggah()`,
            // yang membandingkan (pengguna, jenis, hash, tahun) secara
            // eksplisit dan menghasilkan pesan yang bisa dibaca Admin;
            // index ini adalah lapis kedua yang menutup jalur tulis lain.
            $table->dropUnique('impor_master_berkas_unik');
            $table->unique(
                ['school_id', 'jenis', 'academic_year_id', 'berkas_hash'],
                'impor_master_berkas_unik',
            );
        });
    }

    public function down(): void
    {
        Schema::table('master_import_batches', function (Blueprint $table) {
            $table->dropUnique('impor_master_berkas_unik');
            $table->unique(
                ['school_id', 'jenis', 'berkas_hash'],
                'impor_master_berkas_unik',
            );

            $table->dropForeign(['school_id', 'academic_year_id']);
            $table->dropColumn('academic_year_id');
        });
    }
};
