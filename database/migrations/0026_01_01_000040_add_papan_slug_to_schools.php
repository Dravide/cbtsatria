<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alamat papan publik pengawas - ditambahkan 2026-11-14.
 *
 * ---------------------------------------------------------------------
 * Mengapa slug milik SEKOLAH, bukan milik EVENT
 * ---------------------------------------------------------------------
 * Pertanyaan yang dijawab papan ini - "pengawas mana ada di ruang mana" -
 * berulang pada setiap event, dan alamat yang berubah setiap event berarti
 * tautan yang ditempel di lorong harus dicetak ulang setiap semester.
 * Dengan satu slug per sekolah, Admin mencetaknya sekali, dan isinya
 * berganti mengikuti event yang sedang berjalan.
 *
 * ---------------------------------------------------------------------
 * Nullable, dan null berarti papan itu TIDAK ADA
 * ---------------------------------------------------------------------
 * Ini yang membuat fitur ini aman dibuka: papan publik hanya hidup bila
 * Admin menyalakannya. Sekolah yang tidak pernah membuka tabnya tidak
 * membocorkan apa pun, dan tidak ada satu pun rute `/papan/...` yang bisa
 * ditebak - karena alamatnya tidak pernah lahir.
 *
 * Kolomnya tidak diberi nilai bawaan yang dibangkitkan otomatis: slug yang
 * lahir tanpa keputusan adalah alamat yang tayang di internet tanpa ada
 * yang pernah memilihnya.
 *
 * ---------------------------------------------------------------------
 * Unique lintas sekolah
 * ---------------------------------------------------------------------
 * Rute publik ini di-resolve TANPA konteks tenant (belum ada pengguna),
 * sehingga slug harus unik di seluruh tabel - bukan per sekolah. Unique
 * per sekolah akan membuat dua sekolah bisa memakai slug yang sama, dan
 * yang pertama ditemukan itulah yang tayang untuk keduanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('papan_slug', 64)->nullable()->unique()->after('npsn');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropUnique(['papan_slug']);
            $table->dropColumn('papan_slug');
        });
    }
};
