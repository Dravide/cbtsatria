<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presensi, insiden, attempt, pelanggaran, dan berita acara.
 *
 * PRD §10 (presensi & pengawasan), §11 (pelanggaran), §12 (berita acara),
 * §16.7-§16.9, wireframe plate 11-18.
 *
 * ---------------------------------------------------------------------
 * Mengapa delapan tabel dalam satu berkas
 * ---------------------------------------------------------------------
 * Kedelapannya lahir bersama dan urutan `down()`-nya satu cerita: tanda
 * tangan dan berkas bergantung pada berita acara, berita acara dan insiden
 * bergantung pada ruang-sesi, pelanggaran bergantung pada attempt, dan
 * attempt bergantung pada penempatan. Memecahnya menjadi delapan migrasi
 * berarti delapan kali membaca urutan yang sama untuk memahami satu cerita.
 *
 * ---------------------------------------------------------------------
 * Empat jebakan yang sudah pernah menggigit, dan penopangnya di sini
 * ---------------------------------------------------------------------
 * 1. NAMA INDEKS MENEMBUS 64 KARAKTER.
 *    Nama yang dibangkitkan Laravel untuk
 *    `session_report_signatures_school_id_session_report_id_peran_unique`
 *    berjumlah 65 karakter, dan MySQL menolaknya dengan
 *    "1059 Identifier name ... is too long". Karena itu setiap indeks
 *    gabungan di berkas ini diberi nama eksplisit: `kehadiran_unik`,
 *    `attempt_unik`, `laporan_unik`, `berkas_laporan_unik`,
 *    `ttd_laporan_unik`, `pelanggaran_waktu`. Kesalahan yang sama sudah
 *    tiga kali terjadi di fase sebelumnya.
 *
 * 2. `SET NULL` MUSTAHIL PADA COMPOSITE FK YANG MEMBAWA school_id NOT NULL.
 *    MySQL harus men-null-kan school_id juga dan menolaknya (galat 1005).
 *    Jadi `exam_session_room_id` di `session_reports` dan `exam_incidents`
 *    memakai `restrictOnDelete()`. Konsekuensinya jujur: ruang-sesi yang
 *    sudah punya berita acara tidak bisa dihapus langsung, dan
 *    `SesiService` memang mengosongkan dulu di dalam transaksi.
 *
 * 3. KOLOM NULLABLE DI DALAM COMPOSITE FK DILEWATI PEMERIKSAANNYA
 *    (MATCH SIMPLE). Karena itu `changed_by`, `supervisor_id`,
 *    `resolved_by`, `ditandatangani_oleh`, dan `dibuat_oleh` boleh nullable
 *    berdampingan dengan `school_id` NOT NULL - dan memang HARUS nullable,
 *    karena permintaan yang datang lewat tautan ruang tidak membawa akun.
 *    Pola yang sama sudah dipakai `exam_tokens.created_by`.
 *
 * 4. `pelaku_label` BUKAN HIASAN, IA KOMPENSASI YANG DINYATAKAN.
 *    PRD §10.5 menuntut setiap perubahan presensi mencatat identitas
 *    Pengawas; permintaan lewat tautan ruang tidak membawa `user_id`. Maka
 *    setiap baris mencatat KEDUANYA: `changed_by` (akun, bila ada) dan
 *    `pelaku_label` NOT NULL yang berisi nama pengguna ATAU
 *    "R-01 (tautan ruang)". Yang membuat label ini bukti dan bukan karangan
 *    adalah kredensial ruangnya sendiri dapat diaudit: diterbitkan Admin,
 *    setiap pemakaian menaikkan `use_count`, setiap pembukaan tercatat.
 *    Inilah yang dirender wireframe plate 13 sebagai
 *    "Diverifikasi 07:42 oleh R-01 (Anda)".
 *
 * ---------------------------------------------------------------------
 * Mengapa exam_attempts dan exam_violations dibuat di sini, bukan di Fase 7
 * ---------------------------------------------------------------------
 * Yang MENULIS kedua tabel ini adalah Fase 7 (mesin ujian). Tetapi yang
 * MEMBACA keduanya adalah plate 15 dan 16 - monitoring dan riwayat
 * pelanggaran per siswa - dan keduanya bagian dari Fase 6. Membuat tabelnya
 * sekarang berarti Fase 6 bisa membangun sembilan laman pengawasnya penuh
 * beserta testnya, dan Fase 7 tinggal menambah penulisnya tanpa mengubah
 * apa pun yang sudah teruji.
 *
 * Kolom pelanggaran pada `exams` (`violation_thresholds_json`,
 * `guard_enabled`, `allow_paste_essay`) SENGAJA belum ditambahkan: tidak
 * satu pun punya pembaca di Fase 6, dan kolom tanpa pembaca tidak pernah
 * teruji.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // Presensi
        // ------------------------------------------------------------------

        // Satu baris per PENEMPATAN, bukan per siswa. Perbedaannya penting:
        // siswa yang ditempatkan di dua sesi punya dua baris, dan barisnya
        // hancur bersama penempatannya - karena presensi atas penempatan
        // yang sudah dilepas adalah presensi yang tidak bisa dijelaskan.
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_participant_id');

            // `pending` adalah keadaan yang benar untuk seluruh peserta
            // begitu penempatannya dibuat: belum ada yang memverifikasi.
            // Menjadikannya `tidak_hadir` sebagai default berarti setiap
            // siswa tercatat membolos sampai pengawas menekan tombol.
            $table->string('attendance_status')->default('pending');

            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();

            // Label pelaku, di luar akun - lihat jebakan 4 di atas.
            $table->string('pelaku_label')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'exam_participant_id'], 'kehadiran_unik');
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'attendance_status']);

            $table->foreign(['school_id', 'exam_participant_id'])
                ->references(['school_id', 'id'])->on('exam_participants')->cascadeOnDelete();

            $table->foreign(['school_id', 'verified_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        // Append-only, seperti exam_token_views: barisnya menjawab "siapa
        // mengubah apa, kapan, dan mengapa", dan jawaban itu hilang nilainya
        // kalau barisnya bisa disunting. Karena itu hanya ada `created_at`,
        // tanpa `updated_at` - bukan kelalaian.
        //
        // PRD §10.5: "setiap perubahan presensi mencatat identitas Pengawas,
        // waktu, dan alasan". Koreksi Admin pun menambah baris BARU, tidak
        // pernah menimpa yang lama.
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('attendance_record_id');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('pelaku_label');
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->text('reason')->nullable();

            $table->index(['school_id', 'attendance_record_id']);
            $table->timestamp('created_at')->nullable();

            $table->foreign(['school_id', 'attendance_record_id'])
                ->references(['school_id', 'id'])->on('attendance_records')->cascadeOnDelete();

            $table->foreign(['school_id', 'changed_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        // ------------------------------------------------------------------
        // Insiden ruang (plate 15: "Catat insiden")
        // ------------------------------------------------------------------

        // Berbeda dari exam_violations: insiden adalah kejadian yang DILAPORKAN
        // ORANG dan tidak melekat pada satu attempt - listrik mati, siswa
        // pingsan, ruang lain berisik. Karena itu `exam_participant_id`
        // nullable, dan `supervisor_id` nullable karena pelapornya bisa
        // pemegang tautan ruang yang tidak punya akun.
        Schema::create('exam_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_session_room_id');
            $table->unsignedBigInteger('exam_participant_id')->nullable();
            $table->unsignedBigInteger('supervisor_id')->nullable();
            $table->string('pelaku_label');

            $table->string('incident_type');
            $table->text('description');
            $table->timestamp('occurred_at');

            // `terbuka` / `ditangani`. Insiden yang tidak pernah bisa
            // ditutup adalah insiden yang tidak pernah dibaca dua kali.
            $table->string('status')->default('terbuka');
            $table->timestamps();

            $table->index(['school_id', 'exam_session_room_id', 'status']);
            $table->unique(['school_id', 'id']);

            // RESTRICT - lihat jebakan 2 di atas.
            $table->foreign(['school_id', 'exam_session_room_id'])
                ->references(['school_id', 'id'])->on('exam_session_rooms')->restrictOnDelete();

            $table->foreign(['school_id', 'exam_participant_id'])
                ->references(['school_id', 'id'])->on('exam_participants')->restrictOnDelete();

            $table->foreign(['school_id', 'supervisor_id'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        // ------------------------------------------------------------------
        // Attempt (dibaca Fase 6, ditulis Fase 7)
        // ------------------------------------------------------------------

        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_participant_id');

            // `max_attempts` ada di exams sejak Fase 5; nomor ini yang
            // memakainya, dan unique di bawah yang menegakkannya.
            $table->unsignedTinyInteger('attempt_number')->default(1);

            $table->timestamp('started_at')->nullable();

            // Dua waktu yang berbeda dan tidak boleh disatukan (PRD §9.3):
            // `deadline_at` = started_at + durasi, dan `hard_end_at` sesinya.
            // Yang berlaku adalah yang LEBIH DULU, dan menyimpannya terpisah
            // membuat perbedaan itu tetap bisa dibaca sesudah kejadian.
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('submitted_at')->nullable();

            // Denyut terakhir dari peramban siswa dan kapan server terakhir
            // mendengarnya. Plate 15 memakai selisihnya untuk menandai
            // "terputus"; Fase 6 hanya MEMBACA kolomnya (heartbeat adalah
            // endpoint mesin ujian, milik Fase 7).
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->string('device_type')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // `berjalan` / `dikirim` / `kedaluwarsa` / `dikunci` / `batal`.
            $table->string('status')->default('berjalan');

            // Dua kolom turunan yang SENGAJA disimpan meski bisa dihitung:
            // papan monitoring menghitung keduanya untuk SETIAP peserta di
            // setiap ruang pada setiap penyegaran. Menghitungnya dari
            // exam_violations berarti satu agregat per baris tabel - persis
            // beban yang membuat papan monitoring lambat pada saat ia paling
            // dibutuhkan.
            $table->unsignedInteger('violation_count')->default(0);
            $table->unsignedTinyInteger('violation_worst_severity')->default(0);

            $table->text('lock_reason')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->json('guard_flags_json')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'exam_participant_id', 'attempt_number'], 'attempt_unik');
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'status']);

            $table->foreign(['school_id', 'exam_participant_id'])
                ->references(['school_id', 'id'])->on('exam_participants')->cascadeOnDelete();
        });

        // Append-only. Pelanggaran yang bisa disunting bukan pelanggaran yang
        // bisa dipertanggungjawabkan kepada orang tua siswa - dan itulah satu-
        // satunya alasan tabel ini ada.
        Schema::create('exam_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_attempt_id');

            // Disimpan berlebih di samping attempt_id supaya riwayat
            // pelanggaran PER SISWA (plate 16) tidak perlu menembus attempt
            // untuk setiap barisnya.
            $table->unsignedBigInteger('exam_participant_id');

            $table->string('violation_type');

            // 1..5. Ambang eskalasi ada di exams dan baru dibaca Fase 7.
            $table->unsignedTinyInteger('severity')->default(1);

            $table->timestamp('occurred_at');

            // Berapa lama keadaannya berlangsung - pindah tab 3 detik dan
            // pindah tab 3 menit bukan peristiwa yang sama, dan kolom ini
            // satu-satunya yang membedakannya.
            $table->unsignedInteger('duration_ms')->nullable();

            $table->json('metadata_json')->nullable();

            // Dilaporkan peramban atau ditandai pengawas. Perbedaannya
            // menentukan siapa yang bisa membantahnya.
            $table->boolean('reported_by_client')->default(true);

            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->index(['school_id', 'exam_attempt_id', 'occurred_at'], 'pelanggaran_waktu');
            $table->index(['school_id', 'exam_participant_id']);
            $table->timestamp('created_at')->nullable();

            $table->foreign(['school_id', 'exam_attempt_id'])
                ->references(['school_id', 'id'])->on('exam_attempts')->cascadeOnDelete();

            $table->foreign(['school_id', 'exam_participant_id'])
                ->references(['school_id', 'id'])->on('exam_participants')->cascadeOnDelete();

            $table->foreign(['school_id', 'resolved_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        // ------------------------------------------------------------------
        // Berita acara
        // ------------------------------------------------------------------

        // Satu berita acara per RUANG-SESI - bukan per pengawas, dan bukan
        // per sesi. Itu yang membuat janji blueprint berlaku: "tanda tangan
        // melekat pada peran dan sesi, bukan pada orang, supaya pergantian
        // pengawas tidak memindahkan tanda tangan lama".
        Schema::create('session_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_session_room_id');

            // `draft` / `final`. Draf tersimpan otomatis setiap unggahan dan
            // tanda tangan - yang bisa hilang saat pengawas menutup laman
            // adalah pekerjaan yang tidak diberi tombol simpan.
            $table->string('status')->default('draft');

            $table->text('catatan')->nullable();

            // Dua pernyataan yang ditandai pengawas, disimpan terpisah dari
            // `status` karena keduanya adalah ISINYA dan bukan keadaannya.
            $table->boolean('pernyataan_presensi')->default(false);
            $table->boolean('pernyataan_keluar')->default(false);

            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('dibuat_oleh')->nullable();
            $table->string('pelaku_label')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'exam_session_room_id'], 'laporan_unik');
            $table->unique(['school_id', 'id']);

            // RESTRICT - lihat jebakan 2 di atas.
            $table->foreign(['school_id', 'exam_session_room_id'])
                ->references(['school_id', 'id'])->on('exam_session_rooms')->restrictOnDelete();

            $table->foreign(['school_id', 'dibuat_oleh'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        // Dua sisi: `sebelum` (ruang kosong sebelum ujian dimulai) dan
        // `sesudah` (ruang setelah semua peserta keluar). Unique per sisi
        // supaya mengunggah ulang MENGGANTI, bukan menumpuk - foto ketiga
        // dari ruang yang sama tidak menambah bukti apa pun.
        Schema::create('session_report_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('session_report_id');
            $table->string('sisi');

            // Nama berkas di disk DIACAK; nama asli disimpan terpisah untuk
            // diunduh. Menyimpan nama asli di disk berarti nama berkas orang
            // lain bisa ditebak dari nama berkas kita.
            $table->string('path');
            $table->string('nama_asli');
            $table->string('mime')->nullable();
            $table->unsignedInteger('ukuran')->default(0);

            // Waktu pengambilan vs waktu unggah - dua kolom, dan bedanya yang
            // membuat plate 18 benar: "foto yang dipilih dari galeri setelah
            // ujian tetap tercatat kapan diunggah."
            $table->timestamp('diambil_pada')->nullable();
            $table->timestamp('diunggah_pada');

            $table->timestamps();

            $table->unique(['school_id', 'session_report_id', 'sisi'], 'berkas_laporan_unik');
            $table->unique(['school_id', 'id']);

            $table->foreign(['school_id', 'session_report_id'])
                ->references(['school_id', 'id'])->on('session_reports')->cascadeOnDelete();
        });

        // Berkunci (school_id, session_report_id, peran) - lihat catatan di
        // session_reports. `ditandatangani_oleh` hanyalah KETERANGAN siapa
        // yang menandatangani saat itu; `nama` disimpan pada barisnya supaya
        // tanda tangan tetap terbaca walau akunnya kelak dihapus.
        Schema::create('session_report_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('session_report_id');
            $table->string('peran');
            $table->string('nama');
            $table->string('path');

            $table->timestamp('ditandatangani_pada');
            $table->unsignedBigInteger('ditandatangani_oleh')->nullable();
            $table->string('pelaku_label')->nullable();
            $table->timestamps();

            // Nama eksplisit WAJIB di sini: nama yang dibangkitkan Laravel
            // dari ketiga kolom ini berjumlah 65 karakter - lihat jebakan 1.
            $table->unique(['school_id', 'session_report_id', 'peran'], 'ttd_laporan_unik');
            $table->unique(['school_id', 'id']);

            $table->foreign(['school_id', 'session_report_id'])
                ->references(['school_id', 'id'])->on('session_reports')->cascadeOnDelete();

            $table->foreign(['school_id', 'ditandatangani_oleh'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        // ------------------------------------------------------------------
        // Satu perubahan pada tabel milik Exam
        // ------------------------------------------------------------------

        // Tanpa kolom ini, pembacaan token dari tautan ruang tidak punya
        // identitas sama sekali, dan panel "Riwayat pembukaan" plate 18
        // kehilangan satu-satunya hal yang membuatnya berguna: nama orang
        // yang membuka token, atau setidaknya ruang yang membukanya.
        Schema::table('exam_token_views', function (Blueprint $table) {
            $table->string('pelaku_label')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('exam_token_views', function (Blueprint $table) {
            $table->dropColumn('pelaku_label');
        });

        Schema::dropIfExists('session_report_signatures');
        Schema::dropIfExists('session_report_files');
        Schema::dropIfExists('session_reports');
        Schema::dropIfExists('exam_violations');
        Schema::dropIfExists('exam_attempts');
        Schema::dropIfExists('exam_incidents');
        Schema::dropIfExists('attendance_logs');
        Schema::dropIfExists('attendance_records');
    }
};
