<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemasangan paket soal ke mapel sebuah event - wireframe-admin plate 15,
 * blueprint `:312`.
 *
 * ---------------------------------------------------------------------
 * Dua tabel untuk satu fakta, dan pembagiannya disengaja
 * ---------------------------------------------------------------------
 * Fakta yang disimpan di sini adalah "mapel Matematika di UAS Ganjil memakai
 * paket MTK-X". Ia disimpan DUA kali, dengan dua pertanyaan berbeda:
 *
 *   event_subjects.question_package_id  -> "paket mana yang berlaku SEKARANG"
 *   event_question_packages             -> "siapa memasang apa, KAPAN"
 *
 * Yang pertama menjawab satu kueri di gerbang kesiapan, yang dijalankan
 * setiap kali Admin membuka laman event. Yang kedua menjawab pertanyaan yang
 * tidak bisa dijawab kolom tunggal sama sekali - dan blueprint menuntutnya
 * dengan kalimatnya sendiri: "mengubah paket setelah jadwal tersimpan harus
 * lewat satu pintu, dan pintu itu mencatat siapa yang mengubahnya serta
 * kapan."
 *
 * Kolom tanpa riwayat berarti pertanyaan "paket ini dipasang kemarin atau
 * bulan lalu, dan oleh siapa" hanya bisa dijawab dari log aktivitas, yang
 * tidak mengenal urutan pemasangan per mapel. Riwayat tanpa kolom berarti
 * setiap pembacaan paket aktif harus memindai tabel riwayat dan memilih
 * baris terakhir - kueri yang benar sampai ada dua baris dengan assigned_at
 * yang sama, dan itu terjadi setiap kali dua pemasangan dicatat dalam satu
 * detik.
 *
 * ---------------------------------------------------------------------
 * Kolomnya nullable, dan null berarti sesuatu yang pasti
 * ---------------------------------------------------------------------
 * NULL berarti "mapel ini belum punya paket", bukan "boleh tanpa paket".
 * Yang menegakkannya adalah gerbang kesiapan event, dan itu memang
 * satu-satunya penegaknya - sama seperti exams.question_package_id sejak
 * Fase 5. Docblock EventSubject sudah menyatakan hal yang sama sebelum
 * kolom ini ada, dan kalimatnya dibiarkan apa adanya karena masih benar.
 *
 * Kolom ini TIDAK menyentuh `exams`. Ujian punya paketnya sendiri
 * (question_package_id, sudah ada sejak Fase 5), dan pemasangan di sini
 * adalah tingkat yang lebih tinggi: ia menentukan paket MANA yang menjadi
 * bawaan mapel itu di event itu, sehingga Admin yang membuat ujian tidak
 * perlu memilih dari 40 paket setiap kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_subjects', function (Blueprint $table) {
            $table->unsignedBigInteger('question_package_id')->nullable()->after('subject_id');

            // restrict, bukan cascade: paket yang masih terpasang ke sebuah
            // event tidak boleh dihapus diam-diam. Guru harus melepasnya
            // dulu, dan langkah itu terlihat di riwayat.
            //
            // set null mustahil di sini - composite FK membawa school_id
            // NOT NULL, dan MySQL menolak SET NULL yang akan mengosongkan
            // kolom itu juga. Alasan yang sama sudah tertulis di migrasi
            // exam_tokens dan attendance.
            $table->foreign(['school_id', 'question_package_id'], 'event_mapel_paket_foreign')
                ->references(['school_id', 'id'])->on('question_packages')->restrictOnDelete();
        });

        /*
        |------------------------------------------------------------------
        | event_question_packages - riwayat pemasangan, append-only
        |------------------------------------------------------------------
        | Tidak ada `updated_at`, dan itu bukan kelalaian: baris ini menjawab
        | "apa yang terjadi", dan jawaban itu hilang nilainya kalau barisnya
        | bisa diubah. Pola yang sama dipakai exam_violations dan
        | attendance_logs sejak Fase 6.
        |
        | Tidak ada unique di sini, dan itu disengaja: memasang paket yang
        | sama dua kali setelah sempat dilepas adalah dua peristiwa yang
        | berbeda, dan keduanya berhak punya barisnya sendiri.
        */
        Schema::create('event_question_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('event_subject_id');
            $table->unsignedBigInteger('question_package_id');
            $table->unsignedBigInteger('assigned_by');

            // Paket sebelumnya, bila ada. Null pada pemasangan pertama, dan
            // null juga pada pelepasan - sehingga satu tabel menjawab
            // "dipasang" dan "dilepas" tanpa kolom jenis tersendiri.
            $table->unsignedBigInteger('replaced_package_id')->nullable();

            $table->timestamp('assigned_at')->nullable();
            $table->string('catatan', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['school_id', 'event_id'], 'pemasangan_event');
            $table->index(['school_id', 'event_subject_id', 'assigned_at'], 'pemasangan_mapel');

            $table->foreign(['school_id', 'event_id'])
                ->references(['school_id', 'id'])->on('school_events')->cascadeOnDelete();

            $table->foreign(['school_id', 'event_subject_id'])
                ->references(['school_id', 'id'])->on('event_subjects')->cascadeOnDelete();

            $table->foreign(['school_id', 'question_package_id'])
                ->references(['school_id', 'id'])->on('question_packages')->restrictOnDelete();

            // Yang memasang selalu akun Admin - tidak ada jalur magic link
            // menuju laman ini. Nama eksplisit pada FK-nya karena yang
            // dibangkitkan Laravel dari kolom ini panjangnya melewati batas
            // 64 karakter MySQL.
            $table->foreign(['school_id', 'assigned_by'], 'pemasangan_oleh_foreign')
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_question_packages');

        Schema::table('event_subjects', function (Blueprint $table) {
            $table->dropForeign('event_mapel_paket_foreign');
            $table->dropColumn('question_package_id');
        });
    }
};
