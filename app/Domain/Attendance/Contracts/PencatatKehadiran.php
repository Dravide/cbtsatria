<?php

namespace App\Domain\Attendance\Contracts;

use App\Domain\Exam\Models\ExamParticipant;

/**
 * Pencatat kehadiran: kontrak yang dipanggil modul Exam, dijawab modul
 * Attendance.
 *
 * ---------------------------------------------------------------------
 * Mengapa kontrak, bukan impor langsung
 * ---------------------------------------------------------------------
 * Interaksi antara presensi dan penempatan berjalan dua arah, dan keduanya
 * nyata:
 *
 *   Exam        perlu memastikan setiap penempatan punya baris presensi,
 *               karena pengawas memverifikasi ORANG YANG DUDUK DI KURSI -
 *               dan kursi itu ditentukan modul ujian.
 *   Attendance  perlu membaca penempatan, ruang-sesi, dan sesi, karena
 *               seluruh layarnya adalah layar per ruang.
 *
 * Kalau keduanya saling mengimpor, `DomainBoundariesTest` mencatat lingkaran
 * di peta modul - dan lingkaran di peta modul adalah awal dari monolit
 * tanpa batas, yang justru satu-satunya hal yang blueprint minta dijaga.
 *
 * Penyelesaiannya sama persis dengan ButirKesiapanUjian di Fase 5:
 * arahnya dibuat SATU ARAH. Attendance mendeklarasikan bentuk panggilan
 * yang ia sediakan; Exam yang memanggilnya lewat container. `PenempatanService`
 * tidak pernah menyebut `App\Domain\Attendance\Models\*`.
 *
 * ---------------------------------------------------------------------
 * Mengapa bukan observer Eloquent
 * ---------------------------------------------------------------------
 * Observer pada ExamParticipant tampak lebih murah - satu baris, dan
 * barisnya muncul sendiri. Tiga hal membuatnya salah di sini:
 *
 *   1. ExamParticipant juga disimpan oleh `lepas()` dan `kecualikan()`,
 *      yaitu tepat ketika peserta DIKELUARKAN dari ruangnya. Observer akan
 *      membuat baris presensi untuk penempatan yang tidak lagi punya ruang
 *      - dan presensi tanpa ruang adalah baris yang tidak bisa dijelaskan
 *      kepada siapa pun.
 *   2. `sebar()` menjanjikan di docblock-nya bahwa ia satu-satunya penulis
 *      `exam_session_room_id`. Efek samping tersembunyi di dalam `save()`
 *      memutus janji itu: pembacanya melihat penyimpanan tanpa melihat
 *      pencatatannya.
 *   3. Panggilan service bisa diuji sendirian. Observer hanya bisa diuji
 *      lewat penyimpanan model, yang berarti setiap testnya membawa seluruh
 *      rantai penyimpanan bersamanya.
 *
 * ---------------------------------------------------------------------
 * Idempoten, dan itu bagian dari kontraknya
 * ---------------------------------------------------------------------
 * Menyebar ulang peserta ke ruang yang sama tidak boleh menggandakan baris
 * presensi - dan yang menegakkannya adalah unique `(school_id,
 * exam_participant_id)` di samping implementasi yang memakai `firstOrCreate`.
 * Kontraknya menyatakannya supaya pemanggil tidak perlu memeriksanya
 * sendiri, dan supaya implementasi lain kelak terikat pada janji yang sama.
 */
interface PencatatKehadiran
{
    /**
     * Pastikan ada tepat satu baris presensi untuk penempatan ini.
     *
     * Barisnya dibuat `pending` - bukan `tidak_hadir` - karena `pending`
     * berarti "belum diverifikasi", dan itulah keadaan yang sebenarnya
     * sampai pengawas memanggil nama pemilik kursinya.
     *
     * Aman dipanggil berkali-kali untuk penempatan yang sama.
     */
    public function pastikanUntuk(ExamParticipant $penempatan): void;

    /**
     * Buang baris presensi milik satu penempatan - bila belum diverifikasi.
     *
     * Dipakai saat penempatan dilepas sebelum sesinya berjalan, sehingga
     * daftar kursi tidak memuat siswa yang sudah tidak ada di ruang itu.
     *
     * Syarat "hanya bila masih `pending`" ada di dalam implementasinya, bukan
     * di sini: baris presensi yang sudah diverifikasi adalah catatan tentang
     * apa yang benar-benar terjadi di ruang ujian, dan catatan itu tidak
     * dihapus oleh perubahan jadwal. Pemanggil tidak perlu memeriksanya
     * sendiri - dan itu memang yang dijanjikan kontrak ini.
     */
    public function lupakan(ExamParticipant $penempatan): void;
}
