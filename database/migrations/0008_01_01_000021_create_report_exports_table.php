<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antrean ekspor laporan - PRD §13.5, wireframe-admin.html plate 22.
 *
 * ---------------------------------------------------------------------
 * Mengapa tabel, bukan respons langsung
 * ---------------------------------------------------------------------
 * Plate 22 menulis di kakinya: "berkas besar: disiapkan di latar belakang".
 * Kalimat itu bukan hiasan - ekspor nilai satu angkatan adalah 1.284 baris
 * lintas enam ujian, dan menyiapkannya di dalam permintaan HTTP berarti
 * Admin menatap tombol yang berputar selama proses yang sama sekali tidak
 * ia butuhkan untuk melihat hasilnya.
 *
 * Karena itu permintaannya menulis SATU BARIS, dan worker yang membuat
 * berkasnya. `report_exports` adalah baris itu: apa yang diminta, oleh siapa,
 * dengan penyaring apa, dan bagaimana hasilnya.
 *
 * ---------------------------------------------------------------------
 * `parameter_json` menyimpan PENYARINGNYA, bukan hasilnya
 * ---------------------------------------------------------------------
 * Berkas yang sudah jadi disimpan di disk privat dan diunduh lewat rute
 * berwenang - pola `BerkasLaporanController` dari Fase 6. Yang disimpan di
 * baris ini adalah cara MEMBUATNYA, sehingga ekspor yang gagal bisa diulang
 * dengan penyaring yang persis sama, dan ekspor yang diminta ulang tidak
 * menghasilkan berkas yang berbeda dari yang tadi.
 *
 * ---------------------------------------------------------------------
 * `status_gagal` dan `pesan_gagal`, bukan hanya status
 * ---------------------------------------------------------------------
 * Ekspor gagal yang hanya berstatus "gagal" memaksa Admin menebak sebabnya -
 * dan yang paling sering terjadi adalah hal yang bisa dijelaskan dalam satu
 * kalimat ("event ini belum punya peserta"). Kolom pesannya diisi exception
 * yang tertangkap, dipotong 500 karakter; bukan jejak lengkap, karena yang
 * membacanya Admin, bukan pengembang.
 *
 * ---------------------------------------------------------------------
 * `requested_by` memakai RESTRICT, dan bukan karena pilihan
 * ---------------------------------------------------------------------
 * Yang diinginkan secara semantik: akun yang dihapus tidak menghapus antrean
 * ekspor yang sudah jalan. `SET NULL` tidak bisa dipakai - composite FK yang
 * membawa `school_id` NOT NULL ditolak MySQL dengan galat 1830, jebakan yang
 * sudah pernah menggigit tiga kali di fase ini.
 *
 * `restrictOnDelete()` tidak merugikan apa pun karena `User` memakai
 * `SoftDeletes`: menghapus Admin berarti mengisi `deleted_at`, dan FK-nya
 * tidak pernah dievaluasi. Antreannya tetap ada, dan siapa pemintanya tetap
 * tercatat.
 *
 * ---------------------------------------------------------------------
 * Tidak ada `updated_at`
 * ---------------------------------------------------------------------
 * Barisnya lahir menunggu dan berakhir selesai atau gagal - satu kali, dan
 * tidak pernah kembali. Yang berubah sesudahnya hanyalah kolom yang ditulis
 * worker, dan `finished_at` sudah menjawab "kapan". Menambahkan `updated_at`
 * berarti kolom yang selalu sama dengan `finished_at` untuk baris yang sudah
 * selesai, dan NULL untuk yang belum - dua kolom yang menjawab satu
 * pertanyaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('requested_by')->nullable();

            // Apa yang diminta. `nilai`, `kehadiran`, `pelanggaran`,
            // `analisis_butir`, `rekap_kelas` - lima tab plate 22.
            $table->string('jenis');

            // `xlsx`, `csv`, `pdf` - tiga tombol plate 22.
            $table->string('format');

            // Penyaringnya: event, mapel, kelas, sesi, ruang. JSON, karena
            // setiap jenis laporan memakai penyaring yang berbeda dan kolom
            // bersama untuk lima bentuk berarti lima kolom yang sebagian
            // besar NULL.
            $table->json('parameter_json')->nullable();

            // `menunggu` | `diproses` | `selesai` | `gagal`.
            $table->string('status')->default('menunggu');
            $table->unsignedInteger('total_baris')->nullable();
            $table->string('path')->nullable();
            $table->string('nama_berkas')->nullable();
            $table->unsignedBigInteger('ukuran')->nullable();
            $table->text('pesan_gagal')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Tanpa `updated_at` - lihat catatan kepala berkas. `created_at`
            // tetap ada karena ia menjawab "kapan Admin menekan tombolnya",
            // dan pertanyaan itu tidak bisa dijawab `started_at` (yang
            // kosong selama antrean belum tersentuh worker).
            $table->timestamp('created_at')->nullable();

            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'status', 'created_at'], 'ekspor_antrean_indeks');
            $table->index(['school_id', 'jenis'], 'ekspor_jenis_indeks');

            $table->foreign(['school_id', 'requested_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
