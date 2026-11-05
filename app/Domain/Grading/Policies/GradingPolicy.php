<?php

namespace App\Domain\Grading\Policies;

use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Event\Models\EventTeacherAssignment;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Grading\Models\ExamResult;
use App\Domain\Grading\Models\ReportExport;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;

/**
 * Siapa yang boleh mengoreksi, membaca nilai, dan mengekspor - satu policy
 * untuk empat model Grading.
 *
 * =====================================================================
 * Satu policy, bukan empat, dan alasannya sama dengan AttendancePolicy
 * =====================================================================
 * Keempat model ini menjawab pertanyaan yang SAMA: "boleh orang ini
 * menyentuh nilai ujian X". `ExamResult`, `EssayGrading`, `QuestionAnalysis`,
 * dan `ReportExport` adalah empat bentuk jawaban atas satu pertanyaan, dan
 * menulis empat policy berarti empat tempat yang harus tahu rantai
 * ujian -> event -> penugasan guru. Rantai itu salah di satu tempat berarti
 * Guru Matematika bisa mengoreksi ujian Fisika.
 *
 * =====================================================================
 * Admin TIDAK mengoreksi, Guru TIDAK menerbitkan
 * =====================================================================
 * Pembagian yang tegas, dan keduanya diambil dari blueprint:
 *
 *   Admin   menerbitkan (plate 21), membuka kunci koreksi, mengekspor
 *           (plate 22). Ia TIDAK mengoreksi uraian - yang menilai jawaban
 *           siswa adalah guru yang mengampu mapelnya.
 *   Guru    mengoreksi (plate 06) dan membaca nilai kelasnya (plate 07).
 *           Ia TIDAK menerbitkan.
 *
 * Udara di antara keduanya disengaja: Guru yang bisa menerbitkan sendiri
 * akan menerbitkan sebelum uraiannya selesai dikoreksi, dan Admin yang bisa
 * mengoreksi akan menjadi pengoreksi cadangan yang tidak pernah mengajar.
 *
 * =====================================================================
 * Cakupan Guru: EventTeacherAssignment, bukan TeachingAssignment
 * =====================================================================
 * PRD §6.8: "Guru hanya memperoleh akses ke tugas yang secara eksplisit
 * diberikan kepadanya." Seorang guru bisa mengajar Fisika sepanjang tahun
 * dan tetap tidak ditugaskan di event ini.
 *
 * `TeachingAssignment` tetap dipakai untuk bank soal (Fase 4) - itu soal
 * MILIK GURU, dan soal tidak menempel pada event. Yang dibaca di sini
 * dipersempit ke penugasan event.
 *
 * =====================================================================
 * Cakupan Guru mencakup JUGA mapel yang diampu, dan itu perlu dijelaskan
 * =====================================================================
 * Seeder Fase 4 mengisi `EventTeacherAssignment`, tetapi Admin bisa membuat
 * event baru dan belum sempat menugaskan siapa pun saat Guru pertama kali
 * membuka `/guru/koreksi`. Menolak seluruhnya di keadaan itu berarti
 * halaman kosong yang tidak bisa dijelaskan. Karena itu Guru juga lolos
 * bila ia mengampu mapel ujiannya - sumber kebenaran yang sama dengan
 * bank soal, dan bukan daftar kedua yang bisa berbeda.
 *
 * Yang TIDAK dilonggarkan: Admin tetap tidak mengoreksi, dan Guru tetap
 * hanya melihat ujian dari mapelnya sendiri.
 */
class GradingPolicy
{
    /* ---------------------------------------------------------- Koreksi */

    /**
     * Membuka antrean koreksi uraian.
     *
     * Guru saja. Admin yang membuka `/guru/koreksi` akan menerima 403 dari
     * middleware `guru`? Tidak - middleware meloloskan Admin. Karena itu
     * gerbang ini yang menolaknya, dan penolakan itu harus terjadi SEBELUM
     * daftarnya dirender: Admin yang menerima halaman kosong akan mengira
     * tidak ada yang perlu dikoreksi.
     */
    public function koreksi(User $user): bool
    {
        return $user->role === Role::Teacher;
    }

    /**
     * Mengoreksi satu jawaban uraian.
     *
     * Guru yang mengampu mapel ujian itu. Admin sengaja tidak termasuk -
     * lihat catatan kepala kelas.
     */
    public function koreksiJawaban(User $user, ExamAttempt $attempt): bool
    {
        if ($user->role !== Role::Teacher) {
            return false;
        }

        return $this->mengampuUjian($user, $attempt);
    }

    /* ------------------------------------------------------------ Nilai */

    /**
     * Melihat nilai satu ujian - plate 07.
     *
     * Admin melihat semua (PRD §3.1). Guru melihat ujian dari mapelnya.
     */
    public function lihatNilai(User $user, ExamAttempt $attempt): bool
    {
        if ($user->role->isAdmin()) {
            return true;
        }

        if ($user->role !== Role::Teacher) {
            return false;
        }

        return $this->mengampuUjian($user, $attempt);
    }

    /**
     * Membaca satu baris hasil.
     *
     * Delegasi ke `lihatNilai()`: baris hasil tidak punya arti tanpa
     * attempt-nya, dan aturan yang dipisah di sini akan menjadi aturan
     * kedua yang bisa berbeda dari yang pertama.
     */
    public function view(User $user, ExamResult $hasil): bool
    {
        $attempt = $hasil->pengerjaan;

        return $attempt !== null && $this->lihatNilai($user, $attempt);
    }

    /**
     * Melihat analisis butir - plate 08.
     *
     * Sama dengan izin nilai: analisis butir ditarik dari jawaban, dan
     * melihatnya berarti melihat performa kelas yang sama.
     */
    public function lihatAnalisis(User $user, ExamAttempt $attempt): bool
    {
        return $this->lihatNilai($user, $attempt);
    }

    /* ------------------------------------------------------ Publikasi */

    /**
     * Menerbitkan hasil satu event - plate 21.
     *
     * Admin saja. Ini aksi yang mengubah apa yang dilihat SELURUH siswa
     * satu sekolah, dan Guru yang bisa melakukannya akan menerbitkan
     * sebelum uraiannya selesai.
     */
    public function terbitkan(User $user, SchoolEvent $event): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Membuka kembali kunci koreksi - blueprint keputusan #10.
     *
     * Admin saja, dan alasannya wajib. Guru tidak bisa membuka kuncinya
     * sendiri; kalau bisa, kunci itu tidak pernah mengunci apa pun.
     */
    public function bukaKoreksi(User $user, SchoolEvent $event): bool
    {
        return $user->role->isAdmin();
    }

    /* --------------------------------------------------------- Ekspor */

    /**
     * Meminta ekspor - plate 22.
     *
     * Admin saja. Laporan lintas kelas dan lintas ujian adalah pandangan
     * tingkat sekolah, bukan pandangan Guru yang mengampu satu mapel.
     * Guru punya ekspor sendiri di plate 07 (XLSX/PDF nilai kelasnya).
     */
    public function ekspor(User $user): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Mengunduh berkas ekspor.
     *
     * Hanya pemintanya, atau Admin lain di sekolah yang sama. Pembatasan
     * lintas sekolah sudah dikerjakan SchoolScope sebelum sampai ke sini -
     * berkas sekolah lain berakhir 404 lewat binding implisit.
     *
     * Peminta dibatasi untuk alasan yang berbeda: laporan nilai satu
     * angkatan adalah berkas yang, sekali beredar, tidak bisa ditarik.
     */
    public function unduhEkspor(User $user, ReportExport $ekspor): bool
    {
        if (! $user->role->isAdmin()) {
            return false;
        }

        return $ekspor->requested_by === null
            || (int) $ekspor->requested_by === (int) $user->id;
    }

    /* ------------------------------------------------------------- Pembantu */

    /**
     * Apakah Guru ini mengampu mapel ujian attempt ini.
     *
     * Dua sumber, dan urutannya menentukan beban kueri: penugasan event
     * diperiksa lebih dulu karena ia yang paling sempit dan paling
     * langsung ("Guru ini ditugaskan di event ini untuk mapel ini").
     */
    private function mengampuUjian(User $user, ExamAttempt $attempt): bool
    {
        $ujian = $attempt->penempatan?->exam;

        if ($ujian === null) {
            return false;
        }

        $ditugaskan = EventTeacherAssignment::query()
            ->where('event_id', $ujian->event_id)
            ->where('teacher_id', $user->id)
            ->where('subject_id', $ujian->subject_id)
            ->exists();

        if ($ditugaskan) {
            return true;
        }

        return TeachingAssignment::query()
            ->where('teacher_id', $user->id)
            ->where('subject_id', $ujian->subject_id)
            ->exists();
    }
}
