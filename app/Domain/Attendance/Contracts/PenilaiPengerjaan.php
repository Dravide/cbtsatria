<?php

namespace App\Domain\Attendance\Contracts;

use App\Domain\Attendance\Models\ExamAttempt;

/**
 * Penilaian satu pengerjaan yang baru ditutup - kontrak yang dipanggil modul
 * Attendance, dijawab modul Grading.
 *
 * ---------------------------------------------------------------------
 * Mengapa kontrak, bukan impor langsung
 * ---------------------------------------------------------------------
 * `MesinUjian` adalah tempat attempt BERHENTI: `kumpulkan()` menutup attempt
 * yang dikirim siswa, `tutupBilaKedaluwarsa()` menutup yang kehabisan waktu.
 * Sesudah itu nilainya harus dihitung - dan yang menghitungnya milik modul
 * Grading (`PenilaiOtomatis` + `PenghitungHasil`).
 *
 * Kalau `MesinUjian` mengimpor `Grading\Services\PenilaiOtomatis`, Attendance
 * menunjuk Grading, sementara Grading sudah menunjuk Attendance untuk membaca
 * `student_answers` dan `exam_attempts`. Lingkarannya nyata, dan
 * `DomainBoundariesTest` mencatatnya lewat peta modul.
 *
 * Penyelesaiannya sama persis dengan `PencatatKehadiran` dari Fase 6, hanya
 * arahnya terbalik: di sana Exam yang memanggil Attendance. Di sini yang
 * mendeklarasikan kontrak adalah PEMAKAINYA (Attendance), yang menyediakan
 * implementasinya adalah PENYEDIANYA (Grading), dan yang menyambungkan
 * keduanya adalah provider. Hanya satu arah yang punya impor.
 *
 * ---------------------------------------------------------------------
 * Mengapa dipanggil dari mesin ujian, bukan dari controller siswa
 * ---------------------------------------------------------------------
 * Attempt tertutup dari TIGA pintu yang berbeda: tombol Kumpulkan, tutup
 * otomatis karena deadline, dan penutupan oleh pengawas. Menaruh penilaian di
 * controller tombol Kumpulkan berarti dua pintu lain menutup attempt tanpa
 * pernah menilainya - dan `exam_results` kosong untuk siswa yang kehabisan
 * waktu, tepat pada keadaan yang paling sering terjadi di ujian sungguhan.
 *
 * Karena itu tempatnya di dalam `kumpulkan()` dan `tutupBilaKedaluwarsa()`
 * sendiri: siapa pun yang menutup attempt, nilainya ikut dihitung.
 *
 * ---------------------------------------------------------------------
 * Penilaian TIDAK boleh menggagalkan penutupan attempt
 * ---------------------------------------------------------------------
 * Implementasinya menangkap kegagalannya sendiri dan melaporkannya lewat
 * `report()`. Attempt yang gagal ditutup karena satu butir berskor aneh
 * adalah jawaban siswa yang hilang - jauh lebih buruk daripada nilai yang
 * belum terhitung, yang masih bisa dihitung ulang kapan saja.
 */
interface PenilaiPengerjaan
{
    /**
     * Nilai satu attempt yang baru saja ditutup.
     *
     * Idempotent: memanggilnya dua kali menghasilkan nilai yang sama, dan
     * itu yang membuat pemanggilan ganda pada dua pintu penutupan tidak
     * merusak apa pun.
     */
    public function nilai(ExamAttempt $attempt): void;
}
