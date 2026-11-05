<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manajemen ujian - PRD §16.6, wireframe plate 16-18.
 *
 * ---------------------------------------------------------------------
 * Tiga constraint yang PRD sebut sebagai aturan tetapi tidak pernah
 * mendeklarasikannya
 * ---------------------------------------------------------------------
 * Memetakan §16.6 (daftar kolom) terhadap §9.4/§9.5/§9.6 (daftar aturan)
 * menemukan tiga aturan yang tidak punya penopang di skema:
 *
 *   §9.4  "benturan penggunaan ruang"      -> unique(exam_session_id, event_room_id)
 *   §9.5  "satu penempatan aktif per ujian" -> unique(exam_id, student_id)
 *   §9.6  "benturan penugasan"             -> unique(exam_session_room_id, supervisor_id)
 *
 * Tanpa ketiganya aturan itu hanya hidup di kepala orang. Yang paling
 * kentara yang pertama: ruang yang sama bisa masuk dua kali ke satu sesi,
 * dan tidak ada satu pun lapisan yang menolaknya.
 *
 * ---------------------------------------------------------------------
 * Nama indeks ditulis eksplisit karena batas 64 karakter MySQL
 * ---------------------------------------------------------------------
 * Nama yang dibangkitkan Laravel dari tiga kolom panjang -
 * `exam_session_rooms_school_id_exam_session_id_event_room_id_unique` -
 * menembus batas dan gagal dengan "1059 Identifier name ... is too long".
 * Kesalahan yang sama sudah dua kali terjadi di fase sebelumnya
 * (teaching_assignments, event_teacher_assignments).
 *
 * ---------------------------------------------------------------------
 * supervision_assignments: kolom waktu nullable, dan itu disengaja
 * ---------------------------------------------------------------------
 * §9.6 menuntut "setiap penugasan mempunyai waktu mulai dan selesai",
 * sementara §16.6 tidak memberinya kolom sama sekali. Keduanya dipenuhi
 * dengan pola yang sama seperti event_rooms.capacity_override: kolomnya ADA,
 * tetapi nullable, dan null berarti "ikuti jendela sesinya".
 *
 * Satu sumber kebenaran tetap ada di sesi. Membuatnya NOT NULL berarti dua
 * tempat yang menyimpan waktu yang sama, dan saat keduanya berbeda - karena
 * sesi digeser dan penugasannya lupa ikut digeser - tidak ada yang tahu mana
 * yang benar. Kolom ini hanya diisi bila pengawas benar-benar bergantian di
 * tengah sesi.
 *
 * ---------------------------------------------------------------------
 * exam_tokens: satu token per sesi (keputusan PRD §24 #6)
 * ---------------------------------------------------------------------
 * PRD §9.7: "Rekomendasi awal adalah satu token bersama untuk satu sesi."
 * Karena itu tidak ada kolom cakupan ruang di sini. Perluasan ke token per
 * ruang kelak adalah satu kolom nullable yang menunjuk exam_session_rooms -
 * bukan pembongkaran.
 *
 * `token_hash` TIDAK memakai Hash::make. Alasan lengkapnya di ExamToken,
 * tetapi intinya: bcrypt bergaram, sehingga `where('token_hash', $token)`
 * tidak akan pernah cocok, dan yang tersisa hanyalah memindai setiap token
 * aktif satu per satu. Token adalah KUNCI PENCARIAN di sini, berbeda dari
 * room_entry_links.code_hash yang hanya diverifikasi setelah barisnya
 * ditemukan lewat slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('event_activity_id')->nullable();
            $table->unsignedBigInteger('subject_id');

            // Nullable karena bank soal adalah Fase 4. Kolomnya ada sejak
            // sekarang supaya Fase 5 (sesi, ruang, pengawas, token) tidak
            // perlu menyentuh tabelnya lagi - bukan supaya ujian boleh
            // dijalankan tanpa soal. Readiness gate yang menuntutnya sebelum
            // event ditandai Siap.
            $table->unsignedBigInteger('question_package_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->string('code');
            $table->string('title');
            $table->string('exam_type')->nullable();
            $table->text('instructions')->nullable();

            $table->unsignedInteger('duration_minutes')->default(60);
            $table->unsignedTinyInteger('max_attempts')->default(1);

            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_options')->default(false);
            $table->boolean('allow_back_navigation')->default(true);

            // Kebijakan keterlambatan (PRD §24 #7, keputusan sesi ini):
            // batasnya adalah jendela sesi, persetujuannya adalah tindakan
            // pengawas saat presensi. Kolom ini karena itu hanya saklar
            // boleh/tidak, bukan alur persetujuan.
            $table->boolean('allow_late_entry')->default(false);
            $table->timestamp('late_entry_deadline')->nullable();

            // Publikasi hasil (PRD §9.2, §24 #9). Ditaruh per ujian, bukan
            // per event: pilihan yang lebih sempit, dan perluasan ke tingkat
            // event kelak bisa menimpanya tanpa membongkar kolomnya.
            $table->boolean('publish_results')->default(false);

            $table->string('status')->default('draft');
            $table->timestamps();

            $table->unique(['school_id', 'event_id', 'code']);
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'event_id', 'status']);

            $table->foreign(['school_id', 'event_id'])
                ->references(['school_id', 'id'])->on('school_events')->cascadeOnDelete();

            $table->foreign(['school_id', 'event_activity_id'])
                ->references(['school_id', 'id'])->on('event_activities')->cascadeOnDelete();

            $table->foreign(['school_id', 'subject_id'])
                ->references(['school_id', 'id'])->on('subjects')->restrictOnDelete();

            $table->foreign(['school_id', 'created_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('exam_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_id');
            $table->unsignedBigInteger('class_id');
            $table->timestamps();

            $table->unique(['school_id', 'exam_id', 'class_id']);
            $table->unique(['school_id', 'id']);

            $table->foreign(['school_id', 'exam_id'])
                ->references(['school_id', 'id'])->on('exams')->cascadeOnDelete();

            $table->foreign(['school_id', 'class_id'])
                ->references(['school_id', 'id'])->on('classes')->cascadeOnDelete();
        });

        Schema::create('exam_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_id');

            $table->unsignedTinyInteger('session_number');

            // Tiga waktu, dan ketiganya menjawab pertanyaan berbeda
            // (PRD §9.3): kapan akses dibuka, kapan orang terakhir boleh
            // MULAI, dan kapan ujian benar-benar berhenti. Yang kedua itulah
            // yang membedakan "terlambat masih boleh masuk" dari "sudah
            // lewat, tidak ada gunanya mulai".
            $table->timestamp('access_opens_at');
            $table->timestamp('access_closes_at');
            $table->timestamp('hard_end_at');

            // Durasi boleh berbeda dari durasi ujian: satu sesi bisa
            // dipendekkan karena ruangnya dipakai kegiatan lain sesudahnya.
            $table->unsignedInteger('duration_minutes')->nullable();

            $table->string('status')->default('draft');
            $table->timestamps();

            $table->unique(['school_id', 'exam_id', 'session_number'], 'sesi_nomor_unik');
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'status']);

            $table->foreign(['school_id', 'exam_id'])
                ->references(['school_id', 'id'])->on('exams')->cascadeOnDelete();
        });

        Schema::create('exam_session_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_session_id');
            $table->unsignedBigInteger('event_room_id');

            // Sama seperti event_rooms.capacity_override: null berarti
            // "pakai kapasitas alokasi event", bukan "kapasitas nol".
            $table->unsignedInteger('capacity_override')->nullable();
            $table->timestamps();

            // Nama pendek, dan bukan pilihan gaya - lihat catatan di atas.
            $table->unique(['school_id', 'exam_session_id', 'event_room_id'], 'sesi_ruang_unik');
            $table->unique(['school_id', 'id']);

            $table->foreign(['school_id', 'exam_session_id'])
                ->references(['school_id', 'id'])->on('exam_sessions')->cascadeOnDelete();

            $table->foreign(['school_id', 'event_room_id'])
                ->references(['school_id', 'id'])->on('event_rooms')->cascadeOnDelete();
        });

        Schema::create('exam_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_id');

            // Penempatan menunjuk PESERTA EVENT, bukan siswa langsung.
            // Perbedaan itu yang menjaga satu sumber kebenaran: peserta event
            // sudah tersaring kelas, status, dan sekolahnya sekali; ujian
            // memakainya, bukan menyaring ulang dengan aturannya sendiri.
            $table->unsignedBigInteger('event_participant_id');
            $table->unsignedBigInteger('student_id');

            // Nullable: peserta bisa terdaftar di ujian sebelum sesinya
            // dibentuk, dan sebelum ia ditempatkan ke ruang. Yang tidak boleh
            // adalah dua penempatan aktif untuk satu ujian - itu dijaga
            // unique di bawah.
            $table->unsignedBigInteger('exam_session_id')->nullable();
            $table->unsignedBigInteger('exam_session_room_id')->nullable();

            $table->timestamp('assigned_at')->nullable();
            $table->string('status')->default('terdaftar');
            $table->timestamps();

            $table->unique(['school_id', 'exam_id', 'student_id'], 'ujian_peserta_unik');
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'exam_session_id']);

            $table->foreign(['school_id', 'exam_id'])
                ->references(['school_id', 'id'])->on('exams')->cascadeOnDelete();

            $table->foreign(['school_id', 'event_participant_id'])
                ->references(['school_id', 'id'])->on('event_participants')->cascadeOnDelete();

            $table->foreign(['school_id', 'student_id'])
                ->references(['school_id', 'id'])->on('users')->cascadeOnDelete();

            // RESTRICT, bukan SET NULL - dan bukan pilihan gaya.
            //
            // Kedua relasi ini memang INGIN di-null-kan saat sesinya bubar,
            // tetapi keduanya bagian composite FK yang membawa school_id
            // NOT NULL. MySQL harus men-null-kan school_id juga dan
            // menolaknya, sehingga migrasinya gagal dengan 1005. Kesalahan
            // yang sama sudah terjadi di Fase 2 dan dicatat di sana.
            //
            // Konsekuensinya jujur dan disengaja: sesi yang masih punya
            // peserta tidak bisa dihapus langsung. SesiService yang
            // mengosongkan penempatannya lebih dulu, di dalam transaksi -
            // dan itu memang urutan yang benar.
            $table->foreign(['school_id', 'exam_session_id'])
                ->references(['school_id', 'id'])->on('exam_sessions')->restrictOnDelete();

            $table->foreign(['school_id', 'exam_session_room_id'])
                ->references(['school_id', 'id'])->on('exam_session_rooms')->restrictOnDelete();
        });

        Schema::create('supervision_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_session_room_id');
            $table->unsignedBigInteger('supervisor_id');
            $table->unsignedBigInteger('assigned_by')->nullable();

            // Null berarti "ikuti jendela sesi" - lihat catatan di atas.
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();

            $table->string('status')->default('aktif');
            $table->timestamps();

            $table->unique(
                ['school_id', 'exam_session_room_id', 'supervisor_id'],
                'penugasan_unik',
            );
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'supervisor_id']);

            $table->foreign(['school_id', 'exam_session_room_id'])
                ->references(['school_id', 'id'])->on('exam_session_rooms')->cascadeOnDelete();

            $table->foreign(['school_id', 'supervisor_id'])
                ->references(['school_id', 'id'])->on('users')->cascadeOnDelete();

            $table->foreign(['school_id', 'assigned_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('exam_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_session_id');

            // HMAC-SHA256, deterministik - lihat ExamToken untuk alasannya.
            $table->string('token_hash', 64)->unique();

            // Salinan terenkripsi untuk ditampilkan kembali kepada Admin dan
            // Pengawas yang berwenang (PRD §9.7). Tanpa ini, token yang sudah
            // terbit tidak akan pernah bisa dibaca lagi oleh siapa pun, dan
            // satu-satunya jalan keluar adalah mencabutnya lalu menerbitkan
            // yang baru - tepat pada saat ujian sedang berjalan.
            $table->text('token_encrypted')->nullable();

            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'exam_session_id']);

            $table->foreign(['school_id', 'exam_session_id'])
                ->references(['school_id', 'id'])->on('exam_sessions')->cascadeOnDelete();

            $table->foreign(['school_id', 'created_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('exam_token_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_token_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('room_id')->nullable();

            // Append-only, seperti attendance_logs: baris ini menjawab
            // "siapa yang pernah melihat token ini", dan jawaban itu hilang
            // nilainya kalau barisnya bisa diubah.
            $table->timestamp('viewed_at')->nullable();
            $table->string('jenis')->default('dibuka');

            $table->index(['school_id', 'exam_token_id']);
            $table->timestamp('created_at')->nullable();

            $table->foreign(['school_id', 'exam_token_id'])
                ->references(['school_id', 'id'])->on('exam_tokens')->cascadeOnDelete();

            $table->foreign(['school_id', 'user_id'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();

            $table->foreign(['school_id', 'room_id'])
                ->references(['school_id', 'id'])->on('rooms')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_token_views');
        Schema::dropIfExists('exam_tokens');
        Schema::dropIfExists('supervision_assignments');
        Schema::dropIfExists('exam_participants');
        Schema::dropIfExists('exam_session_rooms');
        Schema::dropIfExists('exam_sessions');
        Schema::dropIfExists('exam_classes');
        Schema::dropIfExists('exams');
    }
};
