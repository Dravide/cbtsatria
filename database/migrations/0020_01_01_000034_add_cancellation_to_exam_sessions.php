<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembatalan sesi - dua kolom, bukan satu status.
 *
 * ---------------------------------------------------------------------
 * `status` saja tidak cukup, dan itu yang membuat kolom ini ada
 * ---------------------------------------------------------------------
 * `StatusSesi::Batal` sudah ada sejak Fase 5, lengkap dengan grafnya, dan
 * lima pembaca sudah menanganinya dengan benar: `TokenSesi` menolak
 * menerbitkan token, `AksesPengawas` menolak membuka ruang,
 * `PresensiController` menyebut sesinya berakhir, `KonflikJadwal` melewatinya,
 * dan `Jadwal.tsx` mengunci tombolnya. Yang tidak ada sampai sekarang adalah
 * PENULISNYA - dan karena itu satu-satunya jalan membatalkan jadwal adalah
 * menghapus barisnya, yang justru ditolak sendiri oleh
 * `PembersihRuangSesiService` ketika ruangannya punya berita acara atau
 * insiden ("Batalkan sesinya alih-alih menghapusnya").
 *
 * ---------------------------------------------------------------------
 * KAPAN dan MENGAPA, dan dua kolom karena itu dua pertanyaan
 * ---------------------------------------------------------------------
 * Status `batal` menjawab "apa keadaannya". Ia tidak menjawab "sejak kapan"
 * dan "atas alasan apa" - dua hal yang ditanyakan orang pertama begitu satu
 * sesi hilang dari jadwal, dan yang tidak bisa dijawab `activity_logs`
 * dengan enak karena lognya dibaca lewat laman lain.
 *
 * Nama kolomnya mengikuti `exam_attempts.lock_reason`/`locked_at`: kolom
 * berbahasa Inggris, keterangan berbahasa Indonesia. Satu tabel yang memakai
 * dua gaya penamaan untuk benda yang sama akan membuat kueri yang salah dan
 * tidak gagal.
 *
 * Keduanya NULL-able, dan sengaja tidak NOT NULL dengan bawaan: sesi yang
 * TIDAK dibatalkan memang tidak punya alasan pembatalan, dan alasan kosong
 * dengan nilai bawaan "-" adalah nilai yang harus diingat-ingat setiap kali
 * dibaca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('status');
            $table->string('cancel_reason', 200)->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
