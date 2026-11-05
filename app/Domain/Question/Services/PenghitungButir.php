<?php

namespace App\Domain\Question\Services;

use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use App\Domain\Tenancy\Scopes\SchoolScope;

/**
 * Menghitung butir paket, untuk dipakai modul LAIN.
 *
 * =====================================================================
 * Mengapa service, bukan model - dan mengapa itu bukan kerapian
 * =====================================================================
 * Gerbang kesiapan event (plate 12) punya butir "Paket soal ditetapkan", dan
 * untuk menjawabnya ia harus tahu berapa butir yang dimiliki paket itu.
 *
 * Cara yang mudah adalah `QuestionPackage::find($id)->butir()->count()` di
 * dalam EventReadiness - dan cara itu melanggar peta modul:
 * `DomainBoundariesTest` mencatat impor `App\Domain\Question\Models\*` dari
 * modul Event sebagai pelanggaran, karena aturan blueprint berbunyi "satu
 * modul hanya boleh mengakses modul lain melalui service resminya, bukan
 * lewat model langsung milik domain lain."
 *
 * Melonggarkan peta untuk satu kueri adalah pilihan yang tersedia, dan
 * ditolak: peta yang punya satu pengecualian berhenti menjelaskan apa pun,
 * karena pengecualian berikutnya selalu bisa dibenarkan dengan alasan yang
 * sama - "hanya satu kueri".
 *
 * ---------------------------------------------------------------------
 * Yang dikembalikan hanya ANGKA, bukan model
 * ---------------------------------------------------------------------
 * Tidak ada satu pun method di sini yang mengembalikan `QuestionPackage`.
 * Itu disengaja: begitu modelnya menyeberang batas, pemanggilnya bisa
 * memanggil relasi, menyimpan, dan menghapusnya - dan seluruh pemisahan
 * modulnya batal tanpa satu baris pun di berkas ini yang berubah.
 *
 * Angka tidak bisa disalahgunakan.
 */
class PenghitungButir
{
    /**
     * Berapa butir dimiliki sebuah paket.
     *
     * Dipanggil dari laman kesiapan event SETIAP kali halaman itu dibuka, dan
     * karena itu kuerinya sengaja satu: `count()` langsung ke tabel, tanpa
     * memuat satu pun model soal. Empat puluh butir yang dimuat untuk sekadar
     * dihitung adalah empat puluh model yang dibangun lalu dibuang.
     */
    public function jumlahButir(?int $packageId): int
    {
        if ($packageId === null) {
            return 0;
        }

        return QuestionPackageItem::query()
            // Sekolahnya tidak diketahui di sini, dan memang tidak perlu:
            // pemanggilnya sudah menyebut id paket yang berasal dari baris
            // event miliknya sendiri. Melewatkan scope di sini BUKAN
            // pelonggaran pemeriksaan - id-nya sudah terikat lebih dulu di
            // sisi pemanggil, dan tidak ada kueri di sini yang menerima id
            // dari permintaan pengguna secara langsung.
            ->withoutGlobalScope(SchoolScope::class)
            ->where('question_package_id', $packageId)
            ->count();
    }

    /**
     * Apakah paket ini ada dan berisi.
     *
     * Dua keadaan yang dibedakan pemanggilnya, karena keduanya punya pesan
     * yang berbeda: "belum dipilih" dan "dipilih tetapi kosong" menuntut
     * tindakan Admin yang berbeda, dan menyatukannya berarti Admin mencari
     * paket yang sudah ia pilih.
     */
    public function berisi(?int $packageId): bool
    {
        return $this->jumlahButir($packageId) > 0;
    }

    /**
     * Berapa total poin sebuah paket, bila butirnya punya override.
     *
     * Tidak dipakai gerbang kesiapan. Ada di sini karena ia pertanyaan yang
     * sama jenisnya - "apa yang bisa diketahui tentang paket tanpa
     * memuatnya" - dan menaruhnya di service lain berarti membuat service
     * kedua yang juga menghitung butir paket.
     */
    public function totalPoin(?int $packageId): float
    {
        if ($packageId === null) {
            return 0.0;
        }

        return (float) QuestionPackageItem::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('question_package_id', $packageId)
            ->get()
            ->sum(fn (QuestionPackageItem $b) => $b->bobotBerlaku());
    }

    /**
     * Nama paket, untuk pesan di laman lain.
     *
     * Satu-satunya kebocoran teks dari modul ini, dan dibatasi pada satu
     * kolom: pemanggilnya butuh menyebut paket mana yang bermasalah, dan
     * pesan "paket belum dipilih" tanpa nama membuat Admin membuka tiga
     * mapel untuk mencari mana yang dimaksud.
     */
    public function namaPaket(?int $packageId): ?string
    {
        if ($packageId === null) {
            return null;
        }

        return QuestionPackage::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->whereKey($packageId)
            ->value('name');
    }
}
