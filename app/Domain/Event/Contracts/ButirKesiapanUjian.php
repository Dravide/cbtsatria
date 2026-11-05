<?php

namespace App\Domain\Event\Contracts;

use App\Domain\Event\Models\SchoolEvent;

/**
 * Butir kesiapan yang hanya bisa dijawab oleh modul ujian.
 *
 * ---------------------------------------------------------------------
 * Mengapa kontrak, bukan impor langsung
 * ---------------------------------------------------------------------
 * Ada lingkaran yang tidak boleh dibiarkan tumbuh, dan lingkaran itu nyata:
 *
 *   Exam   perlu memanggil EventStatusService supaya sesi pertama
 *          memindahkan eventnya ke Berjalan.
 *   Event  perlu membaca sesi, penugasan, dan token supaya gerbang
 *          kesiapan (PRD §6.5) memeriksa yang sebenarnya, bukan yang
 *          dikarang.
 *
 * Kalau keduanya saling mengimpor, `DomainBoundariesTest` mencatat lingkaran
 * di peta modul - dan lingkaran di peta modul adalah awal dari monolit tanpa
 * batas, yang justru satu-satunya hal yang blueprint minta dijaga.
 *
 * Penyelesaiannya: arahnya dibuat SATU arah. Event mendeklarasikan bentuk
 * jawaban yang ia butuhkan; Exam yang menyediakannya. Event tidak pernah
 * menyebut `App\Domain\Exam\*`, sementara Exam menyebut service Event -
 * persis aturan blueprint: "satu modul hanya boleh mengakses modul lain
 * melalui service resminya."
 *
 * ---------------------------------------------------------------------
 * Mengapa empat butir, bukan satu angka
 * ---------------------------------------------------------------------
 * Yang mudah dilakukan adalah mengembalikan boolean "jadwal siap". Yang
 * hilang dari bentuk itu adalah tautan perbaikannya - dan plate 12
 * menampilkan setiap butir gagal BESERTA laman yang harus dibuka untuk
 * memperbaikinya. Bentuk kembaliannya karena itu sama persis dengan butir
 * kesiapan yang lain, supaya tidak ada penerjemahan di antaranya.
 */
interface ButirKesiapanUjian
{
    /**
     * Empat butir: jadwal, pengawas, token, dan kapasitas.
     *
     * Dikembalikan sebagai peta berkunci, bukan daftar berurutan: yang
     * merangkainya adalah EventReadiness, dan urutan tampilan di plate 12
     * adalah urusan halamannya - bukan urusan modul yang menghitungnya.
     *
     * @return array<string, array{kunci: string, label: string, keadaan: string, keterangan: ?string, perbaikan: ?string}>
     */
    public function butir(SchoolEvent $event): array;

    /**
     * Berapa ujian yang sudah dibuat di event ini.
     *
     * Dipakai KPI "Ujian" di plate 12. Nol berarti "belum dibuat", bukan
     * "belum ada fasenya" - perbedaan yang membuat angkanya berarti.
     */
    public function jumlahUjian(SchoolEvent $event): int;
}
