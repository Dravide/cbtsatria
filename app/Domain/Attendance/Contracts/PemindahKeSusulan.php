<?php

namespace App\Domain\Attendance\Contracts;

use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;

/**
 * Pemindah peserta ke sesi susulan, dijawab modul Attendance.
 *
 * ---------------------------------------------------------------------
 * Mengapa ini kontrak, bukan method di PenempatanService
 * ---------------------------------------------------------------------
 * Memindahkan seorang peserta dari sesi utama ke sesi susulan menyentuh dua
 * hal yang berbeda pemiliknya:
 *
 *   `exam_participants.exam_session_id`  milik modul Exam.
 *   `attendance_records` + `attendance_logs` + `exam_attempts`  milik
 *   modul Attendance.
 *
 * Langkah kedua itulah yang membuatnya tidak bisa dikerjakan `PenempatanService`
 * sendiri. `lupakan()` yang dipakai saat melepas penempatan MENOLAK menghapus
 * baris yang sudah diverifikasi - dan justru itu yang terjadi pada siswa yang
 * ditandai `tidak_hadir` di sesi utama. Yang benar adalah koreksi
 * administratif (PRD §10.7): tulis baris log baru `tidak_hadir -> pending`,
 * lalu kosongkan verifikasinya, sehingga pengawas susulan menerima baris
 * `pending` yang bersih - dengan jejak siapa yang mengoreksi dan mengapa.
 *
 * Arahnya SATU, seperti `PencatatKehadiran`: Attendance mendeklarasikan
 * bentuknya, Exam memanggilnya lewat container. Dengan begitu Exam tidak
 * pernah menyebut model Attendance, dan `DomainBoundariesTest` tetap hijau.
 */
interface PemindahKeSusulan
{
    /**
     * Kandidat peserta susulan: siswa ujian ini yang presensinya di sesi
     * UTAMA (bukan susulan) sudah diverifikasi `tidak_hadir` atau `izin`.
     *
     * Hanya dua status itu, bukan `pending`: siswa yang belum diverifikasi
     * belum tentu tidak hadir, dan memindahkannya berarti menebak. Siswa yang
     * sudah dipindah ke sesi susulan tidak lagi muncul - `exam_session_id`-nya
     * sudah menunjuk sesi susulan, bukan sesi utama.
     *
     * Yang membaca `exam_participants` + `attendance_records` adalah Attendance,
     * karena itu kueri ini tinggal di sini.
     *
     * @return array<int, array{peserta_id: int, nis: ?string, nama: string, kelas: ?string, status: string, status_label: string}>
     */
    public function kandidat(Exam $ujian): array;

    /**
     * Pindahkan peserta ke sesi susulan, beserta reset presensinya.
     *
     * Pemanggil (modul Exam) sudah memastikan sesi tujuan benar-benar sesi
     * susulan pada ujian yang sama, dan sudah memindahkan `exam_session_id` +
     * `exam_session_room_id`. Yang dikerjakan implementasi ini adalah presensi
     * dan penjaga attempt:
     *
     *   - peserta yang sudah punya attempt (mengerjakan) DITOLAK, karena
     *     memindahkannya berarti mengubah fakta yang sudah terjadi;
     *   - baris presensi yang ada dikoreksi ke `pending` lewat log; bila belum
     *     ada baris, tidak ada yang perlu dikerjakan.
     *
     * @param  string  $alasan  Dicatat di `attendance_logs.reason` - wajib
     *                          berisi, karena ini mengubah catatan yang sudah
     *                          diverifikasi.
     */
    public function pindahkan(ExamParticipant $penempatan, ExamSession $tujuan, string $alasan): void;
}
