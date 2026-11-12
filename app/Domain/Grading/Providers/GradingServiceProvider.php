<?php

namespace App\Domain\Grading\Providers;

use App\Domain\Attendance\Contracts\PenghapusHasil;
use App\Domain\Attendance\Contracts\PenilaiPengerjaan;
use App\Domain\Attendance\Contracts\SumberNilai;
use App\Domain\Grading\Models\EssayGrading;
use App\Domain\Grading\Models\ExamResult;
use App\Domain\Grading\Models\QuestionAnalysis;
use App\Domain\Grading\Models\ReportExport;
use App\Domain\Grading\Policies\GradingPolicy;
use App\Domain\Grading\Services\PenghapusHasilService;
use App\Domain\Grading\Services\PenghitungAnalisis;
use App\Domain\Grading\Services\PenghitungHasil;
use App\Domain\Grading\Services\PenilaiOtomatis;
use App\Domain\Grading\Services\PenilaiPengerjaanService;
use App\Domain\Grading\Services\PenilaiUraian;
use App\Domain\Grading\Services\PublikasiHasil;
use App\Domain\Grading\Services\SumberNilaiService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root modul Grading.
 *
 * ---------------------------------------------------------------------
 * PenilaiPengerjaan diikat DI SINI, dan itulah yang memutus lingkarannya
 * ---------------------------------------------------------------------
 * `MesinUjian` (Attendance) menutup attempt dan memanggil kontrak
 * `PenilaiPengerjaan`; implementasinya hidup di Grading karena Grading yang
 * membaca `student_answers` dan `exam_questions`. Yang menyambungkan keduanya
 * adalah berkas ini - satu-satunya tempat yang memang boleh menyebut kedua
 * modul sekaligus.
 *
 * Tanpa pengikatan ini, setiap penutupan attempt melempar
 * BindingResolutionException - dan galatnya muncul di layar siswa, bukan di
 * sini.
 *
 * ---------------------------------------------------------------------
 * Satu GradingPolicy untuk empat model
 * ---------------------------------------------------------------------
 * Alasan yang sama dengan AttendancePolicy dan EventPolicy: keempatnya
 * menjawab satu pertanyaan ("boleh orang ini menyentuh nilai ujian X"), dan
 * aturan yang sama yang ditulis empat kali adalah empat tempat yang bisa
 * berbeda diam-diam.
 *
 * ---------------------------------------------------------------------
 * PenghitungHasil singleton, dan alasannya bukan penghematan
 * ---------------------------------------------------------------------
 * Satu request menyentuh nilai dari beberapa arah: koreksi satu jawaban
 * memanggilnya, daftar nilai membacanya, dan tombol publikasi menanyakan
 * `sisaKoreksi()`. Semuanya harus membaca baris yang SAMA dalam satu
 * request - dua instance berarti dua kesempatan menghitung ulang di tengah
 * pembacaan yang lain.
 */
class GradingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PenilaiOtomatis::class);
        $this->app->singleton(PenghitungHasil::class);
        $this->app->singleton(PenghitungAnalisis::class);
        $this->app->singleton(PenilaiUraian::class);
        $this->app->singleton(PublikasiHasil::class);

        // Kontrak Attendance dijawab Grading. Satu arah - lihat catatan
        // kelas PenilaiPengerjaan.
        $this->app->singleton(PenilaiPengerjaan::class, PenilaiPengerjaanService::class);

        // Dan satu lagi, arah yang sama: laman "Nilai saya" hidup di modul
        // Attendance, dan ia membaca angka yang dihitung di sini.
        $this->app->singleton(SumberNilai::class, SumberNilaiService::class);

        // Ketiga: reset ujian membatalkan attempt, dan baris nilai untuk
        // pengerjaan yang dibatalkan harus hilang. Attendance tidak boleh
        // menyentuh `exam_results` sendiri - lihat PenghapusHasil.
        $this->app->singleton(PenghapusHasil::class, PenghapusHasilService::class);
    }

    public function boot(): void
    {
        foreach ([
            ExamResult::class,
            EssayGrading::class,
            QuestionAnalysis::class,
            ReportExport::class,
        ] as $model) {
            Gate::policy($model, GradingPolicy::class);
        }

        /*
         * Empat kemampuan yang TIDAK BISA diselesaikan lewat Gate::policy.
         *
         * -----------------------------------------------------------------
         * Mengapa mereka butuh baris sendiri
         * -----------------------------------------------------------------
         * Gate mencari method policy dari KELAS argumen pertamanya. Dua
         * kelompok di bawah gagal karena dua sebab yang berbeda:
         *
         *   koreksi, ekspor        tidak punya argumen sama sekali - yang
         *                          ditanyakan "boleh peran ini membuka
         *                          antrean", bukan "boleh orang ini
         *                          menyentuh baris ini". Tanpa argumen,
         *                          Gate tidak punya kelas untuk dicari.
         *
         *   terbitkan, bukaKoreksi argumennya `SchoolEvent`, dan kelas itu
         *                          sudah dipetakan ke `EventPolicy` -
         *                          sementara methodnya hidup di sini,
         *                          karena aturan publikasi adalah aturan
         *                          penilaian, bukan aturan event.
         *
         * Keduanya berakhir di closure kosong yang mengembalikan null, dan
         * null adalah penolakan. Gejalanya membingungkan justru karena
         * tidak menunjuk ke sini: SELURUH laman Fase 8 menjawab 403 bagi
         * Admin yang seharusnya berhak, tanpa satu pun galat di log.
         *
         * -----------------------------------------------------------------
         * Mengapa tidak dipindahkan ke EventPolicy saja
         * -----------------------------------------------------------------
         * `terbitkan` dan `bukaKoreksi` membaca `results_published_at` dan
         * `gradings_locked` - dua kolom yang sengaja dipindahkan ke
         * `school_events` supaya publikasinya satu sumber. Aturannya tetap
         * milik modul Grading; yang kebetulan menyimpannya adalah tabel
         * event. Menaruh methodnya di EventPolicy berarti modul Event harus
         * tahu kapan hasil boleh terbit, dan itu arah yang salah.
         */
        Gate::define('koreksi', [GradingPolicy::class, 'koreksi']);
        Gate::define('ekspor', [GradingPolicy::class, 'ekspor']);
        Gate::define('terbitkan', [GradingPolicy::class, 'terbitkan']);
        Gate::define('bukaKoreksi', [GradingPolicy::class, 'bukaKoreksi']);
    }
}
