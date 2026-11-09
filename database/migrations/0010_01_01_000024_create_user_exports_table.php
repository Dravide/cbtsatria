<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antrean ekspor pengguna - PRD §5.7, wireframe-admin.html plate 05.
 *
 * =====================================================================
 * Mengapa tabel sendiri, bukan `report_exports`
 * =====================================================================
 * Tiga alasan yang berdiri sendiri, dan masing-masing cukup:
 *
 *   1. MODUL. `Grading` tidak ada di daftar impor Admin
 *      (`DomainBoundariesTest::ARAH_MODEL_DIIZINKAN`), sehingga memakai
 *      `Grading\Models\ReportExport` dari Admin TERTANGKAP test batas -
 *      bukan lolos lewat celah regex seperti `Enums\` dan `Import\`. Kali ini
 *      penegakannya bekerja, dan yang benar adalah mematuhinya.
 *
 *   2. BENTUKNYA BEDA. `BuatEksporLaporan` mewajibkan
 *      `parameter_json['event_id']` dan terikat `LaporanData::ambil()`.
 *      Ekspor pengguna tidak punya event - ia punya sekolah.
 *
 *   3. `LaporanController::mintaEkspor` menuntut event aktif. Menumpangkan
 *      ekspor pengguna ke sana berarti menuntut Admin memilih event lebih
 *      dulu untuk mengekspor daftar siswa.
 *
 * =====================================================================
 * Tidak ada kolom `jenis`
 * =====================================================================
 * `report_exports` memilikinya karena ada lima laporan berbeda di plate 22.
 * Di sini hanya ada SATU hal yang diekspor, dan nama tabelnya sudah
 * menjawabnya. Kolom yang hanya punya satu nilai sah adalah kolom yang
 * berbohong tentang adanya pilihan - dan pembaca berikutnya akan mencarinya.
 *
 * =====================================================================
 * `format` sengaja TANPA `pdf`
 * =====================================================================
 * PDF adalah artefak cetak yang tidak bisa diimpor kembali, dan justru
 * "bisa diimpor kembali" itu gunanya ekspor ini: Admin mengekspor daftar
 * siswa, memperbaikinya di Excel, lalu mengunggahnya lagi. Daftar hadir cetak
 * sudah punya rumahnya di halaman kelas.
 *
 * =====================================================================
 * `requested_by` memakai RESTRICT, dan alasannya sudah tertulis di Fase 8
 * =====================================================================
 * `SET NULL` tidak bisa dipakai: composite FK yang membawa `school_id` NOT NULL
 * ditolak MySQL dengan galat 1830. RESTRICT tidak merugikan apa pun karena
 * `User` memakai `SoftDeletes` - menghapus Admin berarti mengisi `deleted_at`,
 * dan FK-nya tidak pernah dievaluasi.
 *
 * =====================================================================
 * Tanpa `updated_at`
 * =====================================================================
 * Barisnya lahir menunggu dan berakhir selesai atau gagal, satu kali. Yang
 * menjawab "kapan" adalah `finished_at`; `updated_at` akan selalu sama
 * dengannya atau NULL - dua kolom untuk satu pertanyaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('requested_by')->nullable();

            // `xlsx` | `csv` - tanpa `pdf`, lihat catatan kepala berkas.
            $table->string('format', 10);

            // Penyaringnya, bukan hasilnya: peran (tab), kata kunci, status.
            // Berkas yang sudah jadi disimpan di disk privat dan diunduh lewat
            // rute berwenang.
            $table->json('parameter_json')->nullable();

            // `menunggu` | `diproses` | `selesai` | `gagal`.
            $table->string('status', 20)->default('menunggu');
            $table->unsignedInteger('total_baris')->nullable();
            $table->string('path', 255)->nullable();
            $table->string('nama_berkas', 255)->nullable();
            $table->unsignedBigInteger('ukuran')->nullable();
            $table->text('pesan_gagal')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['school_id', 'id']);

            // Riwayat lima terakhir per sekolah dibaca pada setiap pembukaan
            // laman Pengguna, dan penyaringnya selalu (school_id, urut
            // created_at).
            $table->index(['school_id', 'created_at'], 'ekspor_pengguna_riwayat');
            $table->index(['school_id', 'status'], 'ekspor_pengguna_status');

            $table->foreign(['school_id', 'requested_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_exports');
    }
};
