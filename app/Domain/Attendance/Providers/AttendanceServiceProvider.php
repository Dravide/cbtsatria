<?php

namespace App\Domain\Attendance\Providers;

use App\Domain\Attendance\Contracts\PencatatKehadiran;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamIncident;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Models\SessionReport;
use App\Domain\Attendance\Models\SessionReportFile;
use App\Domain\Attendance\Models\SessionReportSignature;
use App\Domain\Attendance\Policies\AttendancePolicy;
use App\Domain\Attendance\Services\DenyutService;
use App\Domain\Attendance\Services\InsidenService;
use App\Domain\Attendance\Services\JawabanService;
use App\Domain\Attendance\Services\KehadiranService;
use App\Domain\Attendance\Services\KelayakanUjian;
use App\Domain\Attendance\Services\LaporanSesiService;
use App\Domain\Attendance\Services\MesinUjian;
use App\Domain\Attendance\Services\PencatatKehadiranService;
use App\Domain\Attendance\Services\PencatatPelanggaran;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root modul Attendance.
 *
 * ---------------------------------------------------------------------
 * PencatatKehadiran diikat DI SINI, dan itulah yang memutus lingkarannya
 * ---------------------------------------------------------------------
 * Attendance mendeklarasikan kontraknya, Attendance juga yang
 * menyediakan implementasinya, dan yang menyambungkannya ke modul Exam
 * adalah provider - satu-satunya berkas yang memang dikecualikan
 * DomainBoundariesTest dari pemeriksaan impor, karena merangkai modul lewat
 * container adalah tugasnya.
 *
 * Bentuknya sengaja meniru ButirKesiapanUjian dari Fase 5, hanya arahnya
 * terbalik: di sana yang mendeklarasikan adalah pemakainya (Event), di sini
 * yang mendeklarasikan adalah penyedianya (Attendance). Yang menentukan
 * bukan siapa yang menulis interfacenya, melainkan bahwa HANYA SATU arah
 * yang punya impor - `PenempatanService` menyebut `PencatatKehadiran`, dan
 * `PencatatKehadiranService` menyebut `ExamParticipant`. Tidak ada berkas
 * Exam yang menyebut `Attendance\Models`, dan itulah yang dijaga peta modul.
 *
 * Tanpa pengikatan ini, `app(PencatatKehadiran::class)` melempar
 * BindingResolutionException saat Admin menekan Sebar - dan galatnya muncul
 * di laman jadwal, bukan di sini.
 *
 * ---------------------------------------------------------------------
 * Satu AttendancePolicy untuk delapan model
 * ---------------------------------------------------------------------
 * Alasan yang sama dengan EventPolicy dan ExamPolicy: aturannya identik, dan
 * aturan identik yang ditulis delapan kali adalah delapan tempat yang bisa
 * berbeda diam-diam.
 */
class AttendanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton mengikuti provider modul lain: KehadiranService dipanggil
        // berkali-kali dalam satu request (rekap, daftar, dan verifikasi
        // massal semuanya menanyakan ruang yang sama).
        $this->app->singleton(KehadiranService::class);
        $this->app->singleton(InsidenService::class);
        $this->app->singleton(LaporanSesiService::class);

        // Mesin ujian - PRD §11.1-§11.8. Singleton dengan alasan yang sama:
        // satu request siswa menyentuh kelayakan, attempt, dan butirnya, dan
        // ketiganya harus membaca attempt yang sama.
        $this->app->singleton(KelayakanUjian::class);
        $this->app->singleton(MesinUjian::class);

        // Tiga penulis yang dipasang Fase 7.
        //
        // Singleton bukan karena mahal, melainkan karena ketiganya memegang
        // aturan yang sama dan harus dibaca dengan cara yang sama di seluruh
        // request: `PencatatPelanggaran` adalah SATU-SATUNYA penulis
        // exam_violations, dan `JawabanService` satu-satunya penulis
        // student_answers. Dua instance dalam satu request berarti dua
        // kesempatan menghitung ambigu.
        $this->app->singleton(PencatatPelanggaran::class);
        $this->app->singleton(JawabanService::class);
        $this->app->singleton(DenyutService::class);

        // Kontrak Exam dijawab Attendance. Satu arah - lihat catatan kelas ini.
        $this->app->singleton(PencatatKehadiran::class, PencatatKehadiranService::class);
    }

    public function boot(): void
    {
        foreach ([
            AttendanceRecord::class,
            AttendanceLog::class,
            ExamIncident::class,
            ExamAttempt::class,
            ExamViolation::class,
            SessionReport::class,
            SessionReportFile::class,
            SessionReportSignature::class,
        ] as $model) {
            Gate::policy($model, AttendancePolicy::class);
        }
    }
}
