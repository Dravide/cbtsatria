<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Papan pengumuman event - ditambahkan 2026-11-14.
 *
 * ---------------------------------------------------------------------
 * Mengapa tabel sendiri, bukan kolom di `school_events`
 * ---------------------------------------------------------------------
 * Sampai sekarang satu-satunya "pengumuman" yang ada adalah
 * `results_announced_at`: satu timestamp yang menandai bahwa hasil sudah
 * diumumkan. Ia penanda, bukan pesan - dan yang dibutuhkan Admin pada
 * pagi hari ujian adalah kalimat ("sesi 2 mundur 15 menit, ruang R-03
 * pindah ke lab"), yang jumlahnya tidak satu dan umurnya tidak sama.
 *
 * Kolom di `school_events` hanya bisa memuat satu pesan terakhir, dan
 * pesan yang tertimpa adalah pesan yang tidak pernah dibaca siswa yang
 * datang belakangan.
 *
 * ---------------------------------------------------------------------
 * `expires_at` nullable, dan null berarti "sampai dihapus"
 * ---------------------------------------------------------------------
 * Pengumuman operasional ("listrik ruang 4 padam") berhenti berlaku
 * dengan sendirinya, sementara pengumuman yang mengikat sepanjang event
 * ("membawa kartu peserta") tidak. Yang membedakan keduanya adalah waktu,
 * bukan jenis - karena itu satu kolom waktu, bukan satu kolom status yang
 * harus disunting Admin satu per satu.
 *
 * Tanpa kolom ini, pilihan yang tersisa hanya dua-duanya buruk: pesan
 * kedaluwarsa yang tetap menempel di beranda sepanjang semester, atau
 * Admin yang harus ingat menghapusnya.
 *
 * ---------------------------------------------------------------------
 * Cascade ke event, RESTRICT ke pembuatnya
 * ---------------------------------------------------------------------
 * Pengumuman tidak bermakna di luar eventnya, jadi ia hilang bersama
 * event - sama seperti seluruh komponen event lain (lihat `destroy()`
 * di EventController: event ber-pengumuman tetap boleh dihapus).
 *
 * `created_by` mengikuti pola `school_events.created_by`: komposit
 * `(school_id, id)` ke `users` dengan RESTRICT, karena MySQL harus
 * men-null-kan `school_id` yang NOT NULL bila memakai SET NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('event_id');

            $table->string('title', 120);
            $table->text('body');

            // `info` | `penting`. Hanya dua, dan itu disengaja: tiga tingkat
            // membuat Admin menghabiskan waktu memilih nada alih-alih
            // menulis isinya, dan siswa berhenti membaca chip begitu semuanya
            // berwarna.
            $table->string('nada', 12)->default('info');

            // Null = berlaku sampai dihapus - lihat catatan di atas.
            $table->timestamp('expires_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'id']);
            // Kueri beranda siswa: seluruh pengumuman satu event, terbaru
            // dulu. Indeksnya menyebut event_id lebih dulu supaya pemakaian
            // yang sebenarnya - "apa yang berlaku di event saya" - yang
            // dilayani, bukan pencarian lintas event yang tidak dipakai
            // siapa pun.
            $table->index(['school_id', 'event_id', 'created_at'], 'pengumuman_event');

            $table->foreign(['school_id', 'event_id'])
                ->references(['school_id', 'id'])->on('school_events')->cascadeOnDelete();

            $table->foreign(['school_id', 'created_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_announcements');
    }
};
