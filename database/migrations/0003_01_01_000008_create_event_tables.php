<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event management - PRD §16.4 dan wireframe plate 10-15.
 *
 * ---------------------------------------------------------------------
 * Mengapa violation_thresholds disimpan sebagai JSON, bukan kolom tunggal
 * ---------------------------------------------------------------------
 * Blueprint memuat dua bentuk untuk hal yang sama: `exams.violation_thresholds`
 * (baris 538) dan `violation_thresholds_json` (baris 592). Yang dipilih di
 * sini adalah bentuk JSON, dan itu bukan pilihan gaya - ia ditentukan
 * wireframe plate 11, yang menampilkan TIGA ambang berbeda di satu form:
 *
 *   Peringatkan / Tandai pengawas / Kunci ujian
 *
 * Tiga nilai yang selalu muncul bersama, selalu dibaca bersama, dan tidak
 * pernah di-query sendiri-sendiri. Tiga kolom terpisah berarti tiga kolom
 * yang bisa lupa diisi salah satu; satu kolom JSON berarti keadaannya selalu
 * lengkap atau tidak ada sama sekali. Kontradiksi blueprint karena itu
 * selesai di sini, bukan ditunda ke Fase 7.
 *
 * ---------------------------------------------------------------------
 * Bawaannya SATU angka di Pengaturan sekolah, bukan tiga
 * ---------------------------------------------------------------------
 * Wireframe plate 23 menampilkan satu field saja: "Ambang pelanggaran
 * bawaan = 5". Tetapi plate 11 menampilkan tiga. Keduanya benar, dan cara
 * membacanya begini: yang disimpan sekolah adalah toleransi UMUM, dan event
 * baru memakai angka itu untuk KETIGA ambangnya, lalu Admin mempersempit
 * masing-masing bila jenis ujiannya menuntut. Ujian harian dan ujian akhir
 * memang tidak seharusnya memakai toleransi yang sama - dan justru itu alasan
 * ambangnya hidup di event, bukan di pengaturan global.
 *
 * ---------------------------------------------------------------------
 * Istilah status: "berjalan", bukan "aktif"
 * ---------------------------------------------------------------------
 * Wireframe plate 10 menandaskannya. "Aktif" sudah dipakai tahun ajaran dan
 * semester; memakai kata yang sama untuk status event membuat "event aktif"
 * ambigu di percakapan sehari-hari, dan ambiguitas itu berakhir pada Admin
 * yang membuka tahun ajaran keliru. Status event memakai kosakatanya sendiri:
 * draft, persiapan, siap, berjalan, selesai, arsip, batal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('academic_year_id');

            // Nullable: event boleh tidak terikat semester tertentu (mis.
            // try out lintas semester). Membuatnya wajib berarti memaksa
            // Admin memilih semester yang tidak relevan hanya supaya formnya
            // bisa disimpan.
            $table->unsignedBigInteger('semester_id')->nullable();

            $table->string('code');
            $table->string('name');
            $table->string('event_type')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();

            // Status awal selalu draft. Event yang lahir langsung "siap"
            // berarti kesiapan tidak pernah diperiksa.
            $table->string('status')->default('draft');

            // Lihat catatan di atas: tiga ambang dalam satu kolom.
            $table->json('violation_thresholds')->nullable();

            // Kunci layar penuh adalah kebijakan yang berbeda dari ambang:
            // ia bukan "berapa kali boleh", melainkan "boleh keluar atau
            // tidak". Karena itu kolomnya sendiri, bukan anggota JSON.
            $table->boolean('enforce_fullscreen')->default(true);

            // Nullable dengan SET NULL implisit: pembuat event bisa saja
            // dinonaktifkan atau dihapus, dan event yang sudah berjalan tidak
            // boleh ikut hilang karenanya. Berbeda dari wali kelas, di sini
            // "tidak diketahui lagi siapa pembuatnya" adalah keadaan yang
            // bisa diterima - sedangkan kelas tanpa wali yang terhapus diam
            // diam justru keadaan yang tidak boleh terjadi.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'code']);
            // Sasaran composite FK untuk seluruh tabel anak event.
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'status']);

            $table->foreign(['school_id', 'academic_year_id'])
                ->references(['school_id', 'id'])->on('academic_years')->restrictOnDelete();

            $table->foreign(['school_id', 'semester_id'])
                ->references(['school_id', 'id'])->on('semesters')->restrictOnDelete();

            // RESTRICT, bukan SET NULL: alasan yang sama dengan
            // classes.homeroom_teacher_id - MySQL harus men-null-kan
            // school_id yang NOT NULL, dan menolaknya. Di sini RESTRICT juga
            // semantik yang benar: akun yang pernah membuat event tidak boleh
            // dihapus keras selama eventnya ada.
            $table->foreign(['school_id', 'created_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('event_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('event_id');
            $table->string('code');
            $table->string('name');
            $table->string('activity_type')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();

            $table->unique(['school_id', 'event_id', 'code']);
            $table->unique(['school_id', 'id']);

            $table->foreign(['school_id', 'event_id'])
                ->references(['school_id', 'id'])->on('school_events')->cascadeOnDelete();
        });

        Schema::create('event_committee_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('event_id');

            // Nullable: panitia boleh orang tanpa akun - mis. staf TU yang
            // hanya membantu di hari-H. PRD §6.6 menulis "Pengguna terkait
            // jika anggota memiliki akun", dan kata "jika" itu yang dibaca
            // di sini.
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('name');
            $table->string('position');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // Cakupan - kolom yang membatasi apa yang boleh dilihat dan
            // diubah panitia ini (wireframe plate 13).
            //
            // 'event' = seluruh event; 'rooms' = ruang tertentu saja, yang
            // daftarnya ada di event_committee_rooms. Kolom ini yang membuat
            // "pengawas R-01" bukan sekadar jabatan, melainkan batas akses
            // yang bisa ditunjuk.
            $table->string('scope')->default('event');
            $table->string('status')->default('aktif');
            $table->timestamps();

            $table->unique(['school_id', 'event_id', 'name'], 'committee_unik');
            $table->unique(['school_id', 'id']);

            $table->foreign(['school_id', 'event_id'])
                ->references(['school_id', 'id'])->on('school_events')->cascadeOnDelete();

            $table->foreign(['school_id', 'user_id'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('event_committee_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('committee_member_id');
            $table->unsignedBigInteger('room_id');

            $table->unique(['school_id', 'committee_member_id', 'room_id'], 'committee_room_unik');

            $table->foreign(['school_id', 'committee_member_id'])
                ->references(['school_id', 'id'])->on('event_committee_members')->cascadeOnDelete();

            $table->foreign(['school_id', 'room_id'])
                ->references(['school_id', 'id'])->on('rooms')->cascadeOnDelete();
        });

        Schema::create('event_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('class_id');
            $table->timestamps();

            $table->unique(['school_id', 'event_id', 'class_id']);
            $table->unique(['school_id', 'id']);

            $table->foreign(['school_id', 'event_id'])
                ->references(['school_id', 'id'])->on('school_events')->cascadeOnDelete();

            $table->foreign(['school_id', 'class_id'])
                ->references(['school_id', 'id'])->on('classes')->cascadeOnDelete();
        });

        Schema::create('event_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('subject_id');
            $table->timestamps();

            $table->unique(['school_id', 'event_id', 'subject_id']);
            $table->unique(['school_id', 'id']);

            $table->foreign(['school_id', 'event_id'])
                ->references(['school_id', 'id'])->on('school_events')->cascadeOnDelete();

            $table->foreign(['school_id', 'subject_id'])
                ->references(['school_id', 'id'])->on('subjects')->cascadeOnDelete();
        });

        Schema::create('event_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('class_id')->nullable();

            // "kelas" = ditarik dari keanggotaan kelas; "manual" = ditambahkan
            // satu per satu. Wireframe plate 14 menampilkan kolom Sumber, dan
            // perbedaannya bukan hiasan: peserta manual adalah pengecualian
            // yang sengaja dibuat Admin, dan saat daftar kelas ditarik ulang,
            // pengecualian itu tidak boleh ikut terhapus.
            $table->string('source')->default('kelas');

            $table->string('status')->default('terdaftar');
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'event_id', 'student_id']);
            $table->index(['school_id', 'event_id', 'status']);
            $table->unique(['school_id', 'id']);

            $table->foreign(['school_id', 'event_id'])
                ->references(['school_id', 'id'])->on('school_events')->cascadeOnDelete();

            $table->foreign(['school_id', 'student_id'])
                ->references(['school_id', 'id'])->on('users')->cascadeOnDelete();

            $table->foreign(['school_id', 'class_id'])
                ->references(['school_id', 'id'])->on('classes')->restrictOnDelete();
        });

        Schema::create('event_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('room_id');

            // Alokasi ruang ke event boleh memakai kapasitas berbeda dari
            // kapasitas master - mis. meja ujian berjarak dua kursi. Nullable
            // berarti "pakai kapasitas master", bukan "kapasitas nol".
            $table->unsignedInteger('capacity_override')->nullable();
            $table->string('status')->default('aktif');
            $table->timestamps();

            $table->unique(['school_id', 'event_id', 'room_id']);
            $table->unique(['school_id', 'id']);

            $table->foreign(['school_id', 'event_id'])
                ->references(['school_id', 'id'])->on('school_events')->cascadeOnDelete();

            $table->foreign(['school_id', 'room_id'])
                ->references(['school_id', 'id'])->on('rooms')->cascadeOnDelete();
        });

        Schema::create('event_teacher_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->timestamps();

            // Nama indeks eksplisit: nama yang dibangkitkan Laravel dari enam
            // kolom menembus batas 64 karakter MySQL dan gagal dengan
            // "Identifier name ... is too long". Kesalahan yang sama sudah
            // sekali terjadi di teaching_assignments.
            $table->unique(
                ['school_id', 'event_id', 'teacher_id', 'subject_id', 'class_id'],
                'event_teaching_unik',
            );

            $table->foreign(['school_id', 'event_id'])
                ->references(['school_id', 'id'])->on('school_events')->cascadeOnDelete();

            $table->foreign(['school_id', 'teacher_id'])
                ->references(['school_id', 'id'])->on('users')->cascadeOnDelete();

            $table->foreign(['school_id', 'subject_id'])
                ->references(['school_id', 'id'])->on('subjects')->cascadeOnDelete();

            $table->foreign(['school_id', 'class_id'])
                ->references(['school_id', 'id'])->on('classes')->cascadeOnDelete();

            $table->foreign(['school_id', 'activity_id'])
                ->references(['school_id', 'id'])->on('event_activities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_teacher_assignments');
        Schema::dropIfExists('event_rooms');
        Schema::dropIfExists('event_participants');
        Schema::dropIfExists('event_subjects');
        Schema::dropIfExists('event_classes');
        Schema::dropIfExists('event_committee_rooms');
        Schema::dropIfExists('event_committee_members');
        Schema::dropIfExists('event_activities');
        Schema::dropIfExists('school_events');
    }
};
