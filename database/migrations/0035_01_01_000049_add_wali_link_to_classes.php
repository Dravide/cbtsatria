<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laman pantau wali per kelas - `wireframe-pantau-wali.html`.
 *
 * =====================================================================
 * Empat kolom, dan tidak satu pun menyimpan fakta yang sudah ada
 * =====================================================================
 * Seluruh angka yang ditampilkan laman itu - jam absen, jam mulai, lama
 * mengerjakan, sisa batas - sudah tinggal di `attendance_records`,
 * `exam_attempts`, dan `exam_sessions`. Yang belum ada hanyalah ALAMAT
 * publiknya, dan itu memang tidak bisa diturunkan dari data mana pun: ia
 * keputusan sekolah, per kelas, kapan saja bisa dinyalakan dan dimatikan.
 *
 * =====================================================================
 * Dua kolom di `classes`, bukan satu tabel `wali_links`
 * =====================================================================
 * Tautan wali adalah SATU per kelas - tidak ada riwayat, tidak ada beberapa
 * tautan aktif, tidak ada kedaluwarsa berjenjang. Tabel tersendiri untuk
 * relasi satu-ke-satu yang tidak pernah bercabang berarti satu join di
 * setiap pembacaan dan satu tempat lagi yang bisa berbeda dari kelasnya.
 *
 * Pola yang dipakai sama dengan `schools.papan_slug` (0026) dan
 * `room_entry_links`: slug nullable, dan yang NULL tidak punya alamat sama
 * sekali. Rute yang tidak pernah lahir lebih kuat daripada rute yang
 * menolak - yang kedua membuktikan alamatnya ada.
 *
 * =====================================================================
 * `wali_code` terenkripsi, BUKAN hash
 * =====================================================================
 * Alasannya sama dengan `event_committee_members.code`: kode ini dibagikan
 * wali kelas lewat grup, lalu Admin atau wali itu sendiri perlu bisa
 * MEMBACANYA KEMBALI untuk membagikannya ulang. Hash satu arah memaksa
 * menerbitkan ulang setiap kali ada orang tua yang bertanya.
 *
 * =====================================================================
 * `wali_foto` dan `wali_foto_boleh` - dua saklar, dan yang kedua di `schools`
 * =====================================================================
 * Foto berita acara memuat wajah SELURUH peserta ruang itu, bukan hanya anak
 * orang tua yang membukanya. Karena itu ia tidak boleh menyala karena satu
 * kebijaksanaan implisit: sekolah mengizinkannya lebih dulu
 * (`schools.wali_foto_boleh`), lalu wali kelas menyalakannya untuk kelasnya.
 * Bawaannya `false` di keduanya - yang tidak pernah diputuskan tetap mati.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            // NULL = laman wali untuk kelas ini tidak ada. Unik GLOBAL, bukan
            // per sekolah: slug dipakai di URL tanpa konteks tenant, dan dua
            // sekolah dengan slug sama berarti laman sekolah lain yang tayang.
            $table->string('wali_slug', 64)->nullable()->unique()->after('name');

            // Terenkripsi - lihat catatan kepala berkas. `text`, bukan
            // `string`: ciphertext Laravel jauh lebih panjang daripada enam
            // angka yang dikandungnya.
            $table->text('wali_code')->nullable()->after('wali_slug');

            $table->timestamp('wali_code_at')->nullable()->after('wali_code');

            // Bawaan MATI. Kelas yang sudah punya tautan tapi belum pernah
            // memutuskan soal foto tetap tidak menayangkannya.
            $table->boolean('wali_foto')->default(false)->after('wali_code_at');
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('wali_foto_boleh')->default(false)->after('papan_slug');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropUnique(['wali_slug']);
            $table->dropColumn(['wali_slug', 'wali_code', 'wali_code_at', 'wali_foto']);
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('wali_foto_boleh');
        });
    }
};
