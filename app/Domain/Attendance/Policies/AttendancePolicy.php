<?php

namespace App\Domain\Attendance\Policies;

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamIncident;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Models\SessionReport;
use App\Domain\Attendance\Models\SessionReportFile;
use App\Domain\Attendance\Models\SessionReportSignature;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Siapa yang boleh melakukan apa pada presensi, insiden, dan berita acara.
 *
 * ---------------------------------------------------------------------
 * Satu policy untuk delapan model
 * ---------------------------------------------------------------------
 * Alasan yang sama dengan EventPolicy dan ExamPolicy: aturannya identik, dan
 * aturan identik yang ditulis delapan kali adalah delapan tempat yang bisa
 * berbeda diam-diam.
 *
 * ---------------------------------------------------------------------
 * Policy ini BUKAN penjaga isolasi ruang - KonteksRuang yang menjaganya
 * ---------------------------------------------------------------------
 * Pembagiannya disengaja dan penting untuk dipahami sebelum menambah method
 * di sini:
 *
 *   KonteksRuang  -> "apakah ruangnya ada bagiku". Ruang orang lain TIDAK
 *                    ADA (404), dan itu dijalankan sebelum policy.
 *   Policy ini    -> "bolehkah aku melakukan TINDAKAN ini padanya".
 *
 * Kalau isolasi ruang dipindahkan ke sini, jawabannya menjadi 403 - dan 403
 * membuktikan barisnya ada. Itu tepat kebocoran yang sudah dihindari di
 * seluruh sistem ini lewat pola yang sama.
 *
 * ---------------------------------------------------------------------
 * school_id tidak diperiksa, dan itu bukan kelalaian
 * ---------------------------------------------------------------------
 * SchoolScope sudah memastikan setiap baris yang sampai ke sini berasal dari
 * sekolah pemanggilnya. Menambahkan `$user->school_id === $model->school_id`
 * menghadirkan kembali kebocoran 403: ia membuktikan barisnya ada, padahal
 * jawaban yang benar adalah 404.
 *
 * ---------------------------------------------------------------------
 * Admin TIDAK boleh menandatangani berita acara
 * ---------------------------------------------------------------------
 * Ini satu-satunya method di berkas ini yang memeriksa peran secara negatif,
 * dan alasannya bukan kehati-hatian: berita acara ditandatangani orang yang
 * BENAR-BENAR berada di ruang itu. Tanda tangan yang dibubuhkan dari meja
 * Admin adalah tanda tangan atas kejadian yang tidak disaksikannya - dan
 * justru itu nilai satu-satunya yang dimiliki dokumen ini.
 *
 * Admin tetap boleh MEMBACA, mengunduh, dan mengoreksi presensi. Yang tidak
 * boleh ia lakukan hanyalah mengaku berada di ruang yang tidak ia masuki.
 */
class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Baca: siapa pun yang punya urusan dengan ruangnya.
     *
     * Pola yang sama dengan ExamPolicy::view - didelegasikan ke eventnya,
     * bukan disalin. Menyalinnya berarti Fase 4 (portal Guru) harus
     * memperbaiki dua tempat, dan yang kedua akan terlupakan.
     */
    public function view(User $user, Model $model): bool
    {
        $ruang = $this->ruangDari($model);

        if ($ruang === null) {
            return false;
        }

        return $user->can('view', $ruang->session?->exam?->event);
    }

    /**
     * Mengoreksi presensi.
     *
     * Terbuka bagi Admin - PRD §3.5 memberinya kolom "Presensi: Monitoring
     * atau koreksi" - dan bagi pengawas ruang itu. Setiap koreksi menulis
     * baris `attendance_logs` baru; tidak ada satu pun jalur yang menimpa
     * baris lama, dan itu bukan pilihan yang bisa dimatikan dari sini.
     */
    public function koreksi(User $user, AttendanceRecord $record): bool
    {
        if ($user->role->isAdmin()) {
            return true;
        }

        $ruang = $record->penempatan?->room;

        return $ruang !== null && $this->mengawas($user, $ruang);
    }

    /**
     * Mengubah presensi yang sudah diverifikasi.
     *
     * Dipisahkan dari `koreksi` karena bobotnya berbeda, seperti kelolaToken
     * di ExamPolicy: menandai hadir saat memanggil nama adalah pekerjaan
     * rutin, sementara mengubah catatan yang sudah ada adalah koreksi yang
     * harus beralasan. Yang membedakan keduanya bukan siapa pelakunya,
     * melainkan apa yang sedang diubahnya.
     */
    public function ubahTerverifikasi(User $user, AttendanceRecord $record): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Mencatat insiden ruang.
     *
     * Terbuka bagi pengawas ruang itu dan Admin. Pemegang tautan tidak
     * sampai ke sini sama sekali - ia tidak punya User, dan KonteksRuang
     * yang menggantikan Gate untuknya.
     */
    public function catatInsiden(User $user, ExamSessionRoom $ruang): bool
    {
        return $user->role->isAdmin() || $this->mengawas($user, $ruang);
    }

    /**
     * Menandatangani berita acara - dan Admin TIDAK termasuk.
     *
     * Lihat catatan kelas ini. `$user->role->isAdmin()` sengaja menghasilkan
     * false, bukan true: tanda tangan Admin atas ruang yang tidak ia masuki
     * adalah satu-satunya cara dokumen ini kehilangan artinya.
     */
    public function tandatangani(User $user, SessionReport $laporan): bool
    {
        if ($user->role->isAdmin()) {
            return false;
        }

        $ruang = $laporan->ruangSesi;

        return $ruang !== null && $this->mengawas($user, $ruang);
    }

    /** Mengirim berita acara sebagai final. Syaratnya sama dengan menandatangani. */
    public function kirim(User $user, SessionReport $laporan): bool
    {
        return $this->tandatangani($user, $laporan);
    }

    /**
     * Membuka kembali berita acara yang sudah final.
     *
     * Hanya Admin, dan berbeda dari `kirim`. Pengawas yang bisa membuka
     * kembalinya sendiri berarti laporan yang sudah dibaca Admin bisa
     * berubah sesudahnya - dan laporan yang bisa berubah tanpa jejak bukan
     * laporan.
     */
    public function bukaKembali(User $user, SessionReport $laporan): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Membaca berkas berita acara (foto, tanda tangan).
     *
     * Dipakai rute `/laporan/{berkas}`. Berkasnya ada di disk privat, jadi
     * inilah satu-satunya pintunya - dan karena pemeriksaannya di sini,
     * mencabut tautan ruang mencabut fotonya juga.
     */
    public function bacaBerkas(User $user, SessionReportFile|SessionReportSignature $berkas): bool
    {
        return $this->view($user, $berkas);
    }

    /**
     * Melihat papan monitoring dan riwayat pelanggaran.
     *
     * Fase 6 hanya MEMBACA kedua tabel ini; yang menulisnya adalah Fase 7.
     * Karena itu satu-satunya kewenangan yang didefinisikan di sini adalah
     * membacanya.
     */
    public function lihatMonitoring(User $user, ExamSessionRoom $ruang): bool
    {
        return $user->role->isAdmin() || $this->mengawas($user, $ruang);
    }

    /** Apakah pengguna ditugaskan pada ruang-sesi ini. */
    private function mengawas(User $user, ExamSessionRoom $ruang): bool
    {
        return $ruang->supervisors()
            ->where('supervisor_id', $user->id)
            ->exists();
    }

    /** Ruang-sesi yang menaungi satu baris, apa pun jenis barisnya. */
    private function ruangDari(Model $model): ?ExamSessionRoom
    {
        return match (true) {
            $model instanceof AttendanceRecord => $model->penempatan?->room,
            $model instanceof AttendanceLog => $model->record?->penempatan?->room,
            $model instanceof ExamIncident => $model->ruangSesi,
            $model instanceof ExamAttempt => $model->penempatan?->room,
            $model instanceof ExamViolation => $model->peserta?->room,
            $model instanceof SessionReport => $model->ruangSesi,
            $model instanceof SessionReportFile => $model->laporan?->ruangSesi,
            $model instanceof SessionReportSignature => $model->laporan?->ruangSesi,
            default => null,
        };
    }
}
