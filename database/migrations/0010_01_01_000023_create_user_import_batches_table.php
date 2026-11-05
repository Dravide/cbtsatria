<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Berkas impor pengguna - PRD §5.7, wireframe-admin.html plate 05.
 *
 * =====================================================================
 * Mengapa disimpan di basis data, seperti impor soal
 * =====================================================================
 * Impor berjalan tiga langkah: unggah -> pratinjau -> konfirmasi. Tanpa baris
 * ini, "pratinjau" harus berarti "urai ulang berkasnya", dan Admin yang
 * menekan Konfirmasi lima menit kemudian akan menyimpan hasil yang berbeda
 * dari yang ia lihat - atau tidak bisa sama sekali, karena berkasnya sudah
 * dibersihkan.
 *
 * `pratinjau_json` menyimpan hasil validasi per BARIS beserta nomor dan
 * muatannya. Yang kedua yang membuat Konfirmasi berarti "menyimpan apa yang
 * tadi dilihat", bukan "mengurai ulang berkasnya".
 *
 * =====================================================================
 * `created_rows` dan `updated_rows` bukan hiasan
 * =====================================================================
 * Keputusan sesi ini adalah "NIS/NIP yang sudah terdaftar MEMPERBARUI akun
 * lama", dan justifikasinya berbunyi "mengunggah ulang berkas yang diperbaiki
 * aman". Justifikasi itu hanya benar bila Admin bisa MELIHAT bahwa 38 akun
 * dibuat dan 10 diperbarui - SEBELUM ia menekan Konfirmasi. Tanpa dua kolom
 * ini satu-satunya angka yang terlihat adalah "48 tersimpan", dan berkas yang
 * salah ketik tidak bisa dibedakan dari impor yang bersih.
 *
 * Keduanya diisi pada langkah PRATINJAU, bukan konfirmasi, dan itulah
 * gunanya: angka yang menghentikan Admin sebelum ia menyimpan.
 *
 * =====================================================================
 * `berkas_hash` adalah satu-satunya penjaga idempotensi
 * =====================================================================
 * Unik per (sekolah, Admin). Mengunggah ulang berkas yang sama adalah dua kali
 * impor yang sama - dan pada mode "perbarui" akibatnya bukan duplikat yang
 * terlihat, melainkan 1.284 baris yang ditulis dua kali dan `updated_at` yang
 * bergeser pada seluruh akun sekolah. Gejalanya tidak berbunyi, sehingga tidak
 * ada yang akan menyelidikinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();

            // Admin yang mengunggahnya. Bukan `teacher_id` seperti impor
            // soal: impor pengguna adalah pekerjaan Admin, dan Policy-nya
            // (`UserPolicy::import`) sudah menyatakan itu.
            $table->unsignedBigInteger('user_id');

            $table->string('file_path', 255);
            $table->string('nama_asli', 255);
            $table->string('berkas_hash', 64);
            $table->json('pratinjau_json')->nullable();

            $table->string('status', 20)->default('menunggu');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);

            // Diisi pada langkah pratinjau - lihat catatan kepala berkas.
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);

            $table->string('error_report_path', 255)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['school_id', 'user_id', 'berkas_hash'],
                'impor_pengguna_berkas_unik',
            );
            $table->index(['school_id', 'user_id', 'status'], 'impor_pengguna_status');

            // Sasaran composite FK lain di tabel ini, dan alasan yang sama
            // dengan seluruh tabel anak sesudah Fase 2.
            $table->unique(['school_id', 'id']);

            $table->foreign(['school_id', 'user_id'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_import_batches');
    }
};
