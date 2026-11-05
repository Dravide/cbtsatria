<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapel tujuan satu berkas impor.
 *
 * =====================================================================
 * Mengapa kolom ini ada, dan mengapa ia tidak boleh disimpulkan
 * =====================================================================
 * Setiap soal yang lahir dari impor WAJIB punya `subject_id` -
 * `questions.subject_id` NOT NULL, dan itulah yang menentukan Guru mana yang
 * melihatnya kelak (QuestionPolicy menyaring lewat TeachingAssignment).
 *
 * Dua cara menyimpulkannya ditolak, dan keduanya ditolak karena alasan yang
 * bisa diperiksa:
 *
 *   1. DARI PAKET TUJUAN. Paket bersifat OPSIONAL - wireframe plate 04
 *      menyediakan tombol "Impor ke bank saja", dan menyiapkan persediaan
 *      soal tanpa ujian tertentu adalah pemakaian yang sah. Menyimpulkan
 *      mapel dari paket berarti impor tanpa paket tidak punya tujuan sama
 *      sekali.
 *
 *   2. DARI MAPEL GURU. Guru mengampu lebih dari satu mapel pada hampir
 *      setiap SMP, dan memilih salah satunya secara diam-diam berarti 50
 *      soal masuk ke mapel yang tidak diniatkan penulisnya - tersimpan rapi,
 *      tanpa satu pun galat, dan baru ketahuan saat paketnya kosong.
 *
 * Karena itu mapel dipilih Guru saat mengunggah, dan disimpan di barisnya.
 * Kolomnya nullable karena unggahan yang tidak pernah dilanjutkan ke
 * konfirmasi adalah keadaan yang sah; yang menuntutnya ada adalah langkah
 * konfirmasi, bukan langkah unggah.
 *
 * =====================================================================
 * Impor memakai `restrictOnDelete`, bukan cascade
 * =====================================================================
 * Mapel yang punya berkas impor tidak boleh hilang diam-diam: baris batch
 * menunjuk berkas di disk, dan menghapus mapelnya berarti menyisakan riwayat
 * impor yang menunjuk mapel yang tidak ada. `restrictOnDelete` membuat
 * penghapusan gagal dengan galat yang jelas, dan itu jawaban yang benar -
 * Admin yang ingin menghapus mapel harus tahu ada riwayat impor di
 * belakangnya.
 *
 * Nama constraint-nya eksplisit seperti seluruh tabel Fase 4: nama otomatis
 * Laravel untuk constraint ini menembus batas 64 karakter MySQL bila suatu
 * saat kolomnya bertambah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_import_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('subject_id')->nullable()->after('teacher_id');

            $table->foreign(['school_id', 'subject_id'], 'impor_mapel_asing')
                ->references(['school_id', 'id'])->on('subjects')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('question_import_batches', function (Blueprint $table) {
            $table->dropForeign('impor_mapel_asing');
            $table->dropColumn('subject_id');
        });
    }
};
