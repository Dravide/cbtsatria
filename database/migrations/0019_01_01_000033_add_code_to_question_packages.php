<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kode paket soal - dan mengapa ia dibangkitkan, bukan diketik.
 *
 * ---------------------------------------------------------------------
 * Paket tidak punya nama pendek, dan itu mulai menyakitkan
 * ---------------------------------------------------------------------
 * Nama paket adalah kalimat ("Matematika X - Penilaian Akhir Semester
 * Ganjil"), dan itulah yang benar: ia dibaca Admin saat memasang paket ke
 * event (plate 15). Tetapi kalimat tidak bisa disebut di ruang guru. "Paket
 * yang mana?" - "Yang AKM, yang kedua" - adalah percakapan yang sudah ada,
 * dan satu penanda pendek mengakhirinya.
 *
 * ---------------------------------------------------------------------
 * Kode mengikuti pola kode SOAL, dan itu disengaja
 * ---------------------------------------------------------------------
 * `questions.code` sudah berbentuk `Q-<subject_id>-<urut>` sejak Fase 4
 * (`BankSoalService::kodeBaru()`). Paket memakai pola yang sama dengan awalan
 * berbeda: `P-<subject_id>-<urut>`. Dua pola yang berbeda bentuk untuk dua
 * benda yang sederajat akan membuat Guru menebak mana yang mana.
 *
 * Kode TIDAK unik lintas mapel - dua mapel boleh sama-sama punya `P-6-1`
 * hanya bila salah satunya bukan mapel 6, dan itu mustahil karena nomor
 * mapelnya ikut tertulis. Yang dijaga indeks di bawah adalah uniknya per
 * sekolah, sama seperti `soal_kode_unik` menjaga kode soal per sekolah dan
 * mapel.
 *
 * ---------------------------------------------------------------------
 * Baris lama diisi di migrasi ini, bukan dibiarkan kosong
 * ---------------------------------------------------------------------
 * Kolomnya `NOT NULL` karena paket tanpa kode berarti satu-satunya bentuk
 * data yang tidak bisa ditampilkan laman daftar. Paket yang sudah ada diisi
 * di sini dengan urutan `id` di dalam mapelnya - urutan yang sama yang akan
 * dipakai `PaketSoalService::kodeBaru()` untuk paket berikutnya, sehingga
 * penomoran tidak melompat atau mengulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nullable dulu: baris lama belum punya kode, dan kolom NOT NULL
        // tanpa nilai bawaan akan menolak dirinya sendiri. Diperketat di
        // akhir `up()`, sesudah semuanya terisi.
        Schema::table('question_packages', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->after('subject_id');
        });

        // Isi baris lama. Dikelompokkan per (sekolah, mapel) supaya
        // penghitungnya sama dengan yang nanti dibaca `kodeBaru()`.
        $terakhir = [];

        DB::table('question_packages')
            ->orderBy('id')
            ->get(['id', 'school_id', 'subject_id'])
            ->each(function ($paket) use (&$terakhir) {
                $kunci = $paket->school_id.':'.$paket->subject_id;
                $urut = ($terakhir[$kunci] ?? 0) + 1;
                $terakhir[$kunci] = $urut;

                DB::table('question_packages')
                    ->where('id', $paket->id)
                    ->update(['code' => 'P-'.$paket->subject_id.'-'.$urut]);
            });

        Schema::table('question_packages', function (Blueprint $table) {
            $table->string('code', 40)->nullable(false)->change();

            $table->unique(['school_id', 'code'], 'paket_kode_unik');
        });
    }

    public function down(): void
    {
        Schema::table('question_packages', function (Blueprint $table) {
            $table->dropUnique('paket_kode_unik');
            $table->dropColumn('code');
        });
    }
};
