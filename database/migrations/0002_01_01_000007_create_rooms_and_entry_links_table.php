<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ruang + tautan masuk ruang (magic link per ruang).
 *
 * ---------------------------------------------------------------------
 * device_count memisahkan ruang berkomputer dari ruang BYOD
 * ---------------------------------------------------------------------
 * Wireframe plate 09 menampilkan R-01 dengan 32 kapasitas / 32 PC, dan R-04
 * dengan chip "BYOD". Perbedaannya bukan hiasan: ruang ber-PC punya perangkat
 * tetap yang bisa disiapkan sebelum ujian, ruang BYOD tidak. Karena itu
 * device_count adalah kolom tersendiri, bukan turunan dari capacity - ruang
 * kapasitas 40 dengan 0 PC adalah ruang BYOD, dan itu keadaan yang sah.
 *
 * ---------------------------------------------------------------------
 * Mengapa kode pengawas disimpan sebagai hash
 * ---------------------------------------------------------------------
 * Kode pengawas adalah kredensial: ia yang menentukan SIAPA yang boleh masuk
 * ke ruang itu. Menyimpannya apa adanya berarti siapa pun yang bisa membaca
 * tabel ini - lewat backup, lewat dump, lewat satu query yang bocor - dapat
 * masuk ke setiap ruang tanpa pernah berdiri di depan pintunya.
 *
 * Yang disimpan: hash. Yang ditampilkan sekali: kodenya, saat diterbitkan.
 * Sesudah itu tidak ada cara membacanya kembali, dan tombolnya berbunyi
 * "Terbitkan ulang", bukan "Lihat kode".
 *
 * ---------------------------------------------------------------------
 * Pembagian tugas antara tautan dan kode
 * ---------------------------------------------------------------------
 * Wireframe detail plate 09 menulisnya sebagai dua bagian, dan keduanya
 * harus bertahan:
 *
 *   Tautan menentukan RUANG. Ia boleh panjang, boleh ditempel di pintu,
 *   dan sendirian ia tidak membuka apa pun - hanya menampilkan satu kolom
 *   kode.
 *
 *   Kode menentukan ORANG. Ia pendek supaya bisa diketik di depan pintu,
 *   dan justru karena pendek ia tidak boleh menjadi satu-satunya penjaga.
 *
 * Keduanya harus menunjuk ruang yang sama. Tautan R-01 dengan kode ruang
 * R-02 ditolak - bukan karena kodenya salah, tetapi karena keduanya tidak
 * berbicara tentang ruang yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name')->nullable();
            $table->string('location')->nullable();
            $table->unsignedInteger('capacity')->default(0);
            $table->unsignedInteger('device_count')->default(0);
            $table->string('status')->default('aktif');
            $table->timestamps();

            $table->unique(['school_id', 'code']);
            $table->unique(['school_id', 'id']);
        });

        Schema::create('room_entry_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('room_id');

            // Unik GLOBAL, bukan per sekolah. Ia muncul di URL publik
            // (/p/r01-7k4m) dan tidak ada kode sekolah di URL itu untuk
            // memisahkannya - sama alasannya dengan username di tabel users.
            $table->string('slug')->unique();

            $table->string('code_hash');

            // Tidak ada kolom untuk kode mentahnya, dan itu disengaja.
            //
            // Godaan yang wajar: menyimpan plaintext sementara supaya Admin
            // bisa menekan muat ulang tepat setelah menerbitkan. Itu
            // membatalkan gunanya hash - kredensialnya kembali ada di tabel,
            // dan "sementara" berarti "sampai ada yang membersihkannya",
            // yang di lapangan berarti "selamanya".
            //
            // Yang dipakai: kode dikirim sekali lewat flash session, tepat
            // pada respons yang menerbitkannya. Muat ulang berikutnya
            // kehilangan kodenya, dan Admin yang memang butuh lagi
            // menerbitkan ulang - yang juga mencabut tautan lama, sehingga
            // kartu pintu yang beredar tidak menumpuk tanpa batas.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->string('bound_device_fingerprint')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'room_id']);
            $table->foreign(['school_id', 'room_id'])
                ->references(['school_id', 'id'])->on('rooms')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_entry_links');
        Schema::dropIfExists('rooms');
    }
};
