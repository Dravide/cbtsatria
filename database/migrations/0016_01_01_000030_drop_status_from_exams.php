<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `exams.status` dijatuhkan - blueprint §14, PRD §9.2 vs §9.3.
 *
 * ---------------------------------------------------------------------
 * Mengapa dijatuhkan, bukan dibiarkan sebagai kolom yang tidak dibaca
 * ---------------------------------------------------------------------
 * Kolomnya lahir di Fase 5 dengan `default('draft')`, diisi `'draft'` sekali
 * oleh `ExamRegistrar::buat()`, dan tidak pernah ditulis lagi oleh kode mana
 * pun selama enam fase. Pembacanya satu: satu chip di `Admin/Event/Jadwal.tsx`.
 * Tidak ada gerbang kesiapan, tidak ada kueri, tidak ada policy.
 *
 * Akibatnya terlihat di layar, dan itulah yang melaporkannya: chip itu selalu
 * berbunyi "draft", sehingga Admin menyimpulkan ujiannya belum tertinjau -
 * padahal tidak ada satu pun tindakan di seluruh aplikasi yang bisa
 * mengubahnya. Chip yang tidak bisa berubah lebih buruk daripada tidak ada
 * chip: ia menyatakan sesuatu yang tidak benar.
 *
 * Alasan yang sama dengan `publish_results` di migrasi 0008_01_01_000019:
 * kolom yang masih ada akan terisi lagi suatu hari, oleh kode yang membaca
 * katalog tabelnya alih-alih berkas migrasinya.
 *
 * ---------------------------------------------------------------------
 * Yang menjawab "status ujian" adalah SESINYA
 * ---------------------------------------------------------------------
 * PRD §9.2 - daftar field ujian - sengaja TIDAK mencantumkan status,
 * sementara §9.3 mencantumkannya untuk sesi. Asimetri itu bukan kelalaian
 * penulisan: ujian adalah wadah, dan yang punya jendela waktu yang bisa
 * dibuka dan ditutup adalah sesinya. `StatusSesi` benar-benar bergerak
 * (Draft -> Siap -> Berjalan -> Selesai) dan benar-benar dibaca
 * (`SesiService::semuaTuntas`, `KesiapanUjian::token`).
 *
 * ---------------------------------------------------------------------
 * `down()` mengembalikannya KOSONG, dan itu memang benar di sini
 * ---------------------------------------------------------------------
 * Berbeda dari `publish_results` - yang `down()`-nya menyalin balik nilai
 * dari eventnya, karena nilainya pernah berubah - nilai kolom ini tidak
 * pernah berisi apa pun selain `'draft'` sejak Fase 5. Membatalkan migrasi
 * karena itu tidak kehilangan satu fakta pun, dan `default('draft')` sudah
 * memulihkan keadaan yang sebenarnya. Menyalin balik dari suatu tempat justru
 * akan MENGARANG nilai yang tidak pernah disimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            // Index dijatuhkan LEBIH DULU, dan urutannya wajib: ia memuat
            // `status` sebagai kolom ketiga, sehingga MySQL menolak
            // menjatuhkan kolomnya selama index itu masih ada.
            $table->dropIndex(['school_id', 'event_id', 'status']);
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->string('status')->default('draft')->after('late_entry_deadline');
            $table->index(['school_id', 'event_id', 'status']);
        });
    }
};
