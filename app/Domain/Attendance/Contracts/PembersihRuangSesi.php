<?php

namespace App\Domain\Attendance\Contracts;

use DomainException;

/**
 * Pembersih ruang-sesi yang dipanggil modul Exam saat sebuah SESI dihapus.
 *
 * ---------------------------------------------------------------------
 * Mengapa kontrak, bukan impor langsung
 * ---------------------------------------------------------------------
 * Sama persis dengan `PencatatKehadiran`, dan arahnya sekali lagi SATU:
 * Attendance mendeklarasikan bentuk panggilannya, Exam yang memanggilnya
 * lewat container, dan provider yang menyambungkan keduanya. `SesiService`
 * tidak pernah menyebut `App\Domain\Attendance\Models\*`.
 *
 * ---------------------------------------------------------------------
 * Mengapa tabelnya tidak sekadar diberi ON DELETE CASCADE
 * ---------------------------------------------------------------------
 * Dua tabel di modul ini menunjuk `exam_session_rooms` dengan RESTRICT, dan
 * keduanya sengaja:
 *
 *   session_reports  berita acara adalah BUKTI - foto ruang, tanda tangan
 *                    pengawas, dua pernyataan. Baris yang hilang diam-diam
 *                    saat sebuah jadwal dibatalkan adalah bukti yang
 *                    hilang.
 *   exam_incidents   kejadian yang dilaporkan orang di ruangan itu.
 *
 * RESTRICT membuat keduanya tidak bisa hilang tanpa keputusan. Yang kurang
 * sampai 2026-11-10 hanyalah pihak yang MENGAMBIL keputusan itu: `hapus()`
 * mengosongkan penempatan lalu langsung menghapus sesinya, sehingga sesi
 * yang berita acaranya sudah dibuka pengawas gagal dengan galat integritas
 * mentah di laman jadwal - 1451, tanpa satu kalimat yang bisa dibaca Admin.
 *
 * ---------------------------------------------------------------------
 * Draf dibuang, bukti memblokir
 * ---------------------------------------------------------------------
 * Draf kosong lahir sendiri setiap kali laman berita acara DIBUKA
 * (`LaporanSesiService::untuk()`), jadi draf tanpa foto dan tanpa tanda
 * tangan bukan bukti apa pun - ia hanya jejak bahwa seseorang pernah
 * membuka lamannya. Yang benar untuk itu adalah dibuang bersama sesinya.
 *
 * Sebaliknya `final` dan insiden adalah catatan tentang apa yang benar-benar
 * TERJADI. Menghapus sesi tidak boleh menghapusnya, karena sesi yang
 * dibatalkan bukan berarti ruangannya tidak pernah kejadian apa-apa.
 */
interface PembersihRuangSesi
{
    /**
     * Siapkan penghapusan seluruh ruang-sesi milik satu sesi.
     *
     * Dipanggil DI DALAM transaksi `SesiService::hapus()`, sebelum sesinya
     * dihapus. Draf dibuang (beserta berkasnya di disk); sesi yang punya
     * berita acara final atau insiden ditolak.
     *
     * @param  array<int, int>  $idRuangSesi
     *
     * @throws DomainException Bila ada bukti yang tidak boleh hilang.
     */
    public function siapkanHapus(array $idRuangSesi): void;
}
