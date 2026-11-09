<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konfigurasi penyedia AI per sekolah - laman admin, tab AI.
 *
 * =====================================================================
 * Mengapa tabel sendiri, bukan kunci di `schools.settings`
 * =====================================================================
 * `SchoolSettingController::update()` menulis kolom itu secara UTUH:
 *
 *     $sekolah->forceFill(['settings' => $data])->save();
 *
 * `$data` adalah delapan kunci tervalidasi milik form Pengaturan. Artinya
 * setiap kali Admin menekan Simpan di laman itu, seluruh isi `settings`
 * diganti - dan konfigurasi AI yang tinggal di dalamnya akan HILANG diam-diam,
 * tanpa galat, tanpa baris log. Gejalanya baru muncul berhari-hari kemudian:
 * Guru menekan Generate di bank soal dan menerima "AI belum diatur", padahal
 * ia sendiri yang mengaturnya.
 *
 * =====================================================================
 * Mengapa di Tenancy, bukan di Admin atau Question
 * =====================================================================
 * Yang MENULIS adalah Admin (tab AI di laman Pengaturan); yang MEMBACA adalah
 * Question (saat Guru menekan Generate). `DomainBoundariesTest` melarang
 * keduanya saling menunjuk, dan daftar impornya tidak punya satu pun baris
 * yang membolehkannya. Tenancy ada di daftar kedua modul, sehingga ia
 * satu-satunya rumah yang sah - persis alasan `PengaturanBawaan` dipindahkan
 * dari `Admin\Http` ke `Tenancy\Support` di Fase 3.
 *
 * =====================================================================
 * Satu baris per sekolah, dan `aktif` bawaannya FALSE
 * =====================================================================
 * `unique(school_id)` bukan kerapian: dua baris berarti dua tempat yang sama
 * sama dipercaya, dan yang menang adalah yang kebetulan dibaca lebih dulu.
 *
 * Bawaan `aktif = false` adalah penjaga uang: sekolah yang belum pernah
 * membuka tab ini tidak boleh punya satu jalur pun yang mengirim permintaan
 * ke penyedia berbayar. Menyalakannya adalah tindakan sadar, dan layarnya
 * menyebut akibatnya.
 *
 * =====================================================================
 * `api_key_encrypted` bertipe TEXT
 * =====================================================================
 * `Crypt::encryptString` menghasilkan payload base64 dari JSON terenkripsi -
 * sekitar 250 karakter untuk kunci 50 karakter, dan kunci OpenAI sendiri
 * sudah 51+. `string(255)` akan terpotong tanpa galat pada MySQL longgar dan
 * menghasilkan kunci yang tidak bisa didekripsi. Presedennya
 * `exam_tokens.token_encrypted`, yang juga TEXT.
 *
 * `diperbarui_oleh` memakai RESTRICT dengan alasan yang sama seperti
 * `user_exports`: composite FK yang membawa `school_id` NOT NULL ditolak
 * MySQL bila ON DELETE SET NULL, dan `User` memakai SoftDeletes sehingga
 * FK-nya tidak pernah dievaluasi saat akun "dihapus".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaturan_ai', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();

            // Alamat dasar penyedia, TANPA `/chat/completions` di ujungnya:
            // path itu milik bentuk API OpenAI-compatible, dan menuliskannya
            // dua kali berarti satu tempat lagi yang bisa lupa.
            $table->string('base_url', 255)->nullable();

            // Nama model, mis. `gpt-4o-mini`. Sengaja bebas, bukan enum:
            // daftar model penyedia berubah lebih cepat daripada migrasi.
            $table->string('model', 120)->nullable();

            $table->text('api_key_encrypted')->nullable();

            // Default FALSE - lihat catatan kepala berkas.
            $table->boolean('aktif')->default(false);

            // Batas panjang jawaban. 1500 cukup untuk satu soal uraian
            // beserta rubriknya; terlalu kecil memotong JSON di tengah dan
            // hasilnya galat urai yang membingungkan.
            $table->unsignedSmallInteger('batas_token')->default(1500);

            $table->unsignedBigInteger('diperbarui_oleh')->nullable();
            $table->timestamps();

            // Untuk route binding / kueri berpasangan sekolah+id.
            $table->foreign(['school_id', 'diperbarui_oleh'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan_ai');
    }
};
