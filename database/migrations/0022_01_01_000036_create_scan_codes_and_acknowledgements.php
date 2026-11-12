<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode pindai presensi, dan pengakuan siswa - dua tabel, satu cerita.
 *
 * PRD §10, wireframe-presensi-qr.html plate A1-B6.
 *
 * ---------------------------------------------------------------------
 * Mengapa dua tabel, dan mengapa TIDAK SATU PUN menyentuh attendance_records
 * ---------------------------------------------------------------------
 * Yang diganti fitur ini adalah PENGETIKANNYA, bukan keputusannya. Sebelum
 * ini pengawas menyusuri 32 kursi dan menekan 32 tombol; sekarang siswa
 * memindai satu kode di layar pengawas. Tetapi presensi tetap keputusan
 * pengawas - §10.5 dan seluruh laporan kehadiran bersandar pada itu.
 *
 * Karena itu baris pindai adalah PENGAKUAN: "saya menyatakan hadir di ruang
 * ini, sekarang". Ia tidak pernah menjadi presensi sampai pengawas
 * mengonfirmasinya lewat `KehadiranService` - jalur tulis yang sudah ada dan
 * tidak berubah satu baris pun.
 *
 * ---------------------------------------------------------------------
 * Kode disimpan sebagai HASH, bukan terenkripsi
 * ---------------------------------------------------------------------
 * Berbeda dari `event_committee_members.code`, yang harus bisa dicetak ke
 * kartu pengawas sehingga disimpan terenkripsi. Kode pindai TIDAK PERNAH
 * perlu dibaca kembali: ia tampil sebagai QR di layar pengawas yang
 * menerbitkannya, berumur 60 detik, lalu mati. Yang tersisa di basis data
 * tidak boleh bisa dikembalikan menjadi kode yang sah.
 *
 * Hash-nya HMAC-SHA256 dengan APP_KEY, sama seperti `exam_tokens.token_hash`
 * - dan alasannya sama: kode inilah kunci pencarian lewat `/hadir/{kode}`,
 * sehingga bcrypt yang bergaram tidak bisa dipakai. Lihat catatan panjang di
 * `TokenSesi` tentang perbedaan itu.
 *
 * ---------------------------------------------------------------------
 * Kode terikat ke RUANG-SESI, bukan ke sesi
 * ---------------------------------------------------------------------
 * Siswa memindai kode yang tampil di layar pengawas RUANGNYA. Kode yang
 * terikat ke sesi saja akan sah di ruang mana pun, sehingga kode R-01 yang
 * difoto dan dikirim ke teman di R-04 tetap diterima - dan presensi ruang
 * kehilangan artinya. Kolomnya karena itu `exam_session_room_id`.
 *
 * ---------------------------------------------------------------------
 * Rotasi menulis baris BARU, tidak menyunting yang lama
 * ---------------------------------------------------------------------
 * Enam puluh detik sekali selama ujian 90 menit berarti ~90 baris per ruang,
 * dan itu memang direncanakan: baris lama dibiarkan mati oleh `expires_at`-nya
 * sendiri. Menyunting satu baris akan menghapus bukti kode mana yang sah
 * ketika seorang siswa ditolak - dan penolakan itulah yang paling perlu bisa
 * dijelaskan ("kode itu sudah 40 detik mati ketika Anda memindainya").
 *
 * ---------------------------------------------------------------------
 * Empat jebakan yang sudah pernah menggigit, dijawab di sini
 * ---------------------------------------------------------------------
 * 1. NAMA INDEKS MENEMBUS 64 KARAKTER - setiap indeks gabungan diberi nama
 *    eksplisit (`pindai_unik`, `pengakuan_unik`), sama seperti migrasi 0006.
 * 2. `SET NULL` MUSTAHIL PADA COMPOSITE FK YANG MEMBAWA school_id NOT NULL -
 *    seluruh FK di sini memakai `restrictOnDelete()`/`cascadeOnDelete()`,
 *    tidak pernah `nullOnDelete()`.
 * 3. `(school_id, id)` unique ditambahkan di setiap tabel supaya composite FK
 *    dari tabel lain punya sasaran yang sah.
 * 4. Kolom `pelaku_label` tidak diperlukan di sini: yang menulis barisnya
 *    adalah SISWA (akunnya diketahui), bukan pemegang tautan ruang.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // Kode pindai
        // ------------------------------------------------------------------

        Schema::create('attendance_scan_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_session_room_id');

            // Enam digit, sama seperti kode pengawas dan token: yang
            // memindainya adalah kamera ponsel, dan kode yang lebih panjang
            // menghasilkan QR lebih padat yang gagal terbaca di ponsel murah.
            //
            // `code_hash` HANYA hash - lihat catatan migrasi.
            $table->string('code_hash', 64);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');

            $table->timestamps();

            // Kunci pencarian `/hadir/{kode}`: satu index, satu kueri.
            $table->unique(['school_id', 'code_hash'], 'pindai_unik');
            $table->unique(['school_id', 'id']);

            // Membaca kode yang MASIH berlaku untuk satu ruang - dipakai
            // laman presensi setiap kali ia disegarkan.
            $table->index(['school_id', 'exam_session_room_id', 'expires_at'], 'pindai_ruang');

            $table->foreign(['school_id', 'exam_session_room_id'])
                ->references(['school_id', 'id'])->on('exam_session_rooms')->cascadeOnDelete();
        });

        // ------------------------------------------------------------------
        // Pengakuan siswa
        // ------------------------------------------------------------------

        // Satu baris per pindai yang BERHASIL. Kode yang ditolak tidak
        // menulis baris - penolakan dijawab dengan kalimat, bukan dengan
        // catatan yang kelak terbaca seperti percobaan kehadiran.
        Schema::create('attendance_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('attendance_scan_code_id');
            $table->unsignedBigInteger('exam_participant_id');

            // Waktu PINDAI, dan itulah yang menentukan hadir/terlambat -
            // lihat wireframe bagian C. Waktu konfirmasi ada di
            // attendance_records.verified_at dan tidak pernah dipakai untuk
            // menurunkan status.
            $table->timestamp('scanned_at');

            // `menunggu` / `dikonfirmasi`. `ditolak` disediakan tetapi tidak
            // pernah ditulis hari ini: penolakan adalah jawaban laman, bukan
            // baris. Ia ada supaya membatalkan pengakuan kelak menjadi
            // suntingan satu nilai, bukan migrasi.
            $table->string('status')->default('menunggu');

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            // Satu pengakuan per penempatan: memindai dua kali tidak menulis
            // dua baris, dan tidak mengembalikan status yang sudah diputuskan
            // pengawas menjadi `pending` - wireframe plate B6, penolakan
            // keempat.
            $table->unique(['school_id', 'exam_participant_id'], 'pengakuan_unik');
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'status']);

            // Nama kuncinya DIBERI SENDIRI, dan itu wajib di sini: nama
            // bawaan Laravel untuk dua kolom ini adalah
            // `attendance_acknowledgements_school_id_attendance_scan_code_id_foreign`
            // - 70 karakter, dan MySQL menolaknya dengan galat 1059. Jebakan
            // yang sama sudah tertulis di catatan migrasi ini dan tetap
            // tergigit: peringatan di komentar tidak menegakkan apa pun,
            // jadi yang menegakkannya adalah nama pendek di bawah.
            $table->foreign(['school_id', 'attendance_scan_code_id'], 'pengakuan_kode')
                ->references(['school_id', 'id'])->on('attendance_scan_codes')->restrictOnDelete();

            $table->foreign(['school_id', 'exam_participant_id'], 'pengakuan_peserta')
                ->references(['school_id', 'id'])->on('exam_participants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_acknowledgements');
        Schema::dropIfExists('attendance_scan_codes');
    }
};
