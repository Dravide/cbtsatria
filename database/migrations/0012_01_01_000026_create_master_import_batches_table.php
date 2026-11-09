<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Berkas impor master data - mata pelajaran dan ruang.
 *
 * =====================================================================
 * Mengapa SATU tabel untuk dua jenis, bukan dua tabel kembar
 * =====================================================================
 * Bentuk kedua impor ini identik di setiap kolomnya: berkas diunggah, seluruh
 * barisnya diperiksa tanpa menyentuh basis data, Admin melihat hasilnya per
 * baris, lalu menekan Konfirmasi. Yang berbeda hanya DAFTAR KOLOMNYA - dan
 * daftar kolom sudah punya rumahnya sendiri sejak `PenjajarKolom` ada.
 *
 * Dua tabel kembar berarti dua tempat yang harus tetap sepakat soal pratinjau,
 * hash berkas, dan laporan galat - dan tempat kedua selalu yang terlupakan
 * saat salah satunya diperbaiki. Kolom `jenis` yang membedakannya, dan
 * `jenis` juga yang menentukan penjajar dan model mana yang dipakai service.
 *
 * =====================================================================
 * Mengapa disimpan di basis data, seperti dua importer sebelumnya
 * =====================================================================
 * Impor berjalan tiga langkah. Tanpa baris ini, "pratinjau" harus berarti
 * "urai ulang berkasnya", dan Admin yang menekan Konfirmasi lima menit
 * kemudian akan menyimpan hasil yang berbeda dari yang ia lihat.
 *
 * =====================================================================
 * `berkas_hash` unik per (sekolah, jenis)
 * =====================================================================
 * Mengunggah ulang berkas yang sama adalah dua kali impor yang sama. Di sini
 * akibatnya bukan duplikat - `code` unik per sekolah, sehingga barisnya hanya
 * akan MENIMPA - melainkan `updated_at` yang bergeser pada seluruh mapel
 * sekolah tanpa satu pun perubahan yang terlihat. Kerusakan yang tidak
 * berbunyi, sehingga tidak ada yang akan menyelidikinya.
 *
 * Per `jenis`, bukan per sekolah saja: berkas mapel yang diunggah di laman
 * ruang adalah berkas yang salah, dan menolaknya karena "sudah diunggah di
 * laman lain" adalah kalimat yang membingungkan untuk masalah yang sebenarnya
 * adalah salah laman.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();

            // Admin yang mengunggahnya. Impor master data adalah pekerjaan
            // Admin, sama seperti impor pengguna.
            $table->unsignedBigInteger('user_id');

            // 'mapel' atau 'ruang'. Bukan enum PHP: jejak baris lama harus
            // tetap bisa dibaca setelah daftar jenisnya bertambah, dan enum
            // yang kehilangan satu case membuat seluruh baris lamanya gagal
            // dibaca - termasuk baris yang isinya tidak pernah salah.
            $table->string('jenis', 20);

            $table->string('file_path', 255);
            $table->string('nama_asli', 255);
            $table->string('berkas_hash', 64);
            $table->json('pratinjau_json')->nullable();

            $table->string('status', 20)->default('menunggu');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);

            // Diisi pada langkah PRATINJAU - lihat catatan impor pengguna.
            // Angka yang menghentikan Admin SEBELUM ia menyimpan.
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);

            $table->string('error_report_path', 255)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['school_id', 'jenis', 'berkas_hash'],
                'impor_master_berkas_unik',
            );
            $table->index(['school_id', 'jenis', 'status'], 'impor_master_status');

            // Sasaran composite FK lain di tabel ini, dan alasan yang sama
            // dengan seluruh tabel anak sesudah Fase 2.
            $table->unique(['school_id', 'id']);

            $table->foreign(['school_id', 'user_id'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_import_batches');
    }
};
