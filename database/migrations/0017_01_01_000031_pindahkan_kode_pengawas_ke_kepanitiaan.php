<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode pengawas pindah dari BARIS RUANG ke BARIS ORANG.
 *
 * ---------------------------------------------------------------------
 * Mengapa dipindahkan, bukan ditambahkan
 * ---------------------------------------------------------------------
 * Blueprint `:995` sudah menuliskan pembagiannya sejak awal:
 *
 *   "Tautan unik per ruang ... menentukan ruang mana yang dibuka; ia
 *    panjang, sulit ditebak, dan aman ditempel di pintu karena sendirian
 *    tidak membuka apa pun. Setelah tautan dibuka, pengawas mengetik KODE
 *    PENGAWAS enam angka untuk ruang itu; KODE MENENTUKAN SIAPA YANG MASUK."
 *
 * Yang berlaku sampai 2026-11-07 adalah kebalikannya: `code_hash` hidup di
 * `room_entry_links`, sehingga satu kode membuka satu RUANG untuk siapa pun
 * yang memegangnya - dan siapa pun yang memfoto kartu pintu memegang
 * keduanya sekaligus: tautan dan kodenya. Kalimat blueprint itu tidak bisa
 * menjadi benar selama kodenya disimpan di baris ruang; ia hanya bisa
 * menjadi benar bila kodenya menempel pada ORANG.
 *
 * ---------------------------------------------------------------------
 * Kolom lama DIJATUHKAN, dan itu bukan kerapian
 * ---------------------------------------------------------------------
 * Alasan yang sama persis dengan `exams.status` di migrasi
 * 0016_01_01_000030: kolom yang masih ada akan terisi lagi suatu hari, oleh
 * kode yang membaca katalog tabel alih-alih berkas migrasinya. Di sini
 * akibatnya lebih langsung daripada satu chip yang berbohong - dua sumber
 * kode untuk satu pintu berarti dua kode bisa membuka ruang yang sama, dan
 * justru itulah yang sedang dihapus.
 *
 * ---------------------------------------------------------------------
 * `code_hash` nullable, dan itu WAJIB
 * ---------------------------------------------------------------------
 * Dua sebab, dan keduanya nyata hari ini:
 *
 *   1. Panitia berjabatan ketua, koordinator, dan anggota memang tidak
 *      pernah butuh kode - kode pengawas adalah kredensial PINTU RUANG.
 *   2. Seluruh baris kepanitiaan yang sudah ada (36 di basis data
 *      pengembangan) lahir sebelum kolom ini, dan mengisinya dengan kode
 *      karangan berarti menulis kredensial yang tidak pernah diterbitkan
 *      siapa pun.
 *
 * NOT NULL akan menuntut salah satu dari keduanya.
 *
 * `code_issued_at` ada supaya laman Kepanitiaan bisa menyebut umur
 * kredensialnya ("terbit 07/11 14:02") tanpa membaca hash-nya. Kredensial
 * yang hidup berbulan-bulan harus bisa dilihat usianya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_committee_members', function (Blueprint $table) {
            $table->string('code_hash')->nullable()->after('phone');
            $table->timestamp('code_issued_at')->nullable()->after('code_hash');
        });

        Schema::table('room_entry_links', function (Blueprint $table) {
            $table->dropColumn('code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('room_entry_links', function (Blueprint $table) {
            // Nullable saat dibatalkan, dan itu jujur: kode yang sudah
            // diterbitkan lewat baris kepanitiaan tidak bisa "dipindahkan
            // kembali" ke tautan - satu kode bisa dipakai di banyak tautan
            // ruang, dan tautan tidak menyimpan orang.
            //
            // Mengarang satu kode di sini berarti menulis kredensial yang
            // tidak pernah diterbitkan siapa pun, dan yang tidak diketahui
            // siapa pun. Kolom yang kosong lebih baik daripada baris yang
            // berbohong tentang dirinya berlaku.
            $table->string('code_hash')->nullable()->after('slug');
        });

        Schema::table('event_committee_members', function (Blueprint $table) {
            $table->dropColumn(['code_hash', 'code_issued_at']);
        });
    }
};
