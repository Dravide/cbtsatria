<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `exams.publish_results` dijatuhkan - PRD §24 #9, blueprint §14.
 *
 * ---------------------------------------------------------------------
 * Mengapa dijatuhkan, bukan dibiarkan sebagai kolom yang tidak dibaca
 * ---------------------------------------------------------------------
 * Kolomnya ditambahkan Fase 5 dengan catatan: "Ditaruh per ujian, bukan per
 * event: pilihan yang lebih sempit, dan perluasan ke tingkat event kelak bisa
 * menimpanya tanpa membongkar kolomnya."
 *
 * Perluasan itu terjadi sekarang, dan yang membuat kolom lamanya harus
 * DIJATUHKAN - bukan sekadar berhenti dibaca - adalah aturan blueprint §14:
 * jangan tinggalkan kolom kembar. Kolom yang masih ada akan terisi lagi suatu
 * hari, oleh kode yang membaca katalog tabelnya alih-alih berkas migrasinya,
 * dan sejak itu ada dua jawaban atas "apakah hasil sudah dipublikasikan" yang
 * bisa berbeda.
 *
 * ---------------------------------------------------------------------
 * Lima pembaca sudah dipindah lebih dulu, dan itu urutannya
 * ---------------------------------------------------------------------
 * Menjatuhkan kolom sementara masih ada pembacanya berarti galat 500 di lima
 * tempat. Kelimanya dipindah di commit yang sama dengan migrasi ini:
 *
 *   JadwalSiswaController::adaNilaiKeluar()      -> results_published_at
 *   NilaiSiswaController::bentukBaris()          -> results_published_at
 *   ExamJadwalController (validasi simpan/ubah)  -> dihapus sama sekali
 *   Admin/Event/Jadwal.tsx (sakelar)             -> dipindah ke plate 21
 *   ExamRegistrar / ExamFactory / DatabaseSeeder -> tidak lagi menulisnya
 *
 * ---------------------------------------------------------------------
 * `down()` mengembalikannya, dan mengembalikannya TERISI
 * ---------------------------------------------------------------------
 * Migrasi yang bisa dibalik tetapi mengembalikan kolom kosong berarti
 * pembatalan migrasi mengubah data diam-diam: setiap ujian yang hasilnya
 * sudah dipublikasikan kembali terbaca belum dipublikasikan. `down()` karena
 * itu menyalin balik nilai dari eventnya - keadaan terdekat dengan yang
 * sebenarnya, dan cukup untuk membatalkan migrasi tanpa kehilangan artinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('publish_results');
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->boolean('publish_results')->default(false)->after('late_entry_deadline');
        });

        // Disalin dari event, bukan dibiarkan false: lihat catatan di atas.
        DB::statement('
            update exams
            inner join school_events on school_events.id = exams.event_id
                and school_events.school_id = exams.school_id
            set exams.publish_results = case
                when school_events.results_published_at is null then 0
                else 1
            end
        ');
    }
};
