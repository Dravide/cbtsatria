<?php

namespace App\Domain\Exam\Providers;

use App\Domain\Event\Contracts\ButirKesiapanUjian;
use App\Domain\Event\Contracts\ButirSoalPerMapel;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamClass;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\ExamToken;
use App\Domain\Exam\Models\ExamTokenView;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Exam\Policies\ExamPolicy;
use App\Domain\Exam\Services\ButirSoalEvent;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\KesiapanUjian;
use App\Domain\Exam\Services\KonflikJadwal;
use App\Domain\Exam\Services\NomorKursi;
use App\Domain\Exam\Services\PapanKursi;
use App\Domain\Exam\Services\PenempatanKocok;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Exam\Services\PengawasService;
use App\Domain\Exam\Services\PetaKursiEvent;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Exam\Services\TokenSesi;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root modul Exam.
 *
 * ---------------------------------------------------------------------
 * ButirKesiapanUjian diikat DI SINI, dan itulah yang memutus lingkarannya
 * ---------------------------------------------------------------------
 * Event mendeklarasikan kontraknya, Exam menyediakan implementasinya, dan
 * yang menyambungkan keduanya adalah provider - satu-satunya berkas yang
 * memang dikecualikan DomainBoundariesTest dari pemeriksaan impor, karena
 * merangkai modul lewat container adalah tugasnya.
 *
 * Tanpa pengikatan ini, `app(ButirKesiapanUjian::class)` melempar galat saat
 * EventReadiness memeriksa event - dan galatnya muncul di laman ringkasan
 * event, bukan di sini.
 *
 * ---------------------------------------------------------------------
 * Satu ExamPolicy untuk delapan model
 * ---------------------------------------------------------------------
 * Alasan yang sama dengan EventPolicy: aturannya identik, dan aturan identik
 * yang ditulis delapan kali adalah delapan tempat yang bisa berbeda diam-diam.
 */
class ExamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton mengikuti EventServiceProvider: KonflikJadwal dipanggil
        // berkali-kali dalam satu request (panel validasi, penandaan baris,
        // dan readiness gate semuanya bertanya tentang event yang sama), dan
        // satu objek lebih murah daripada tiga.
        $this->app->singleton(ExamRegistrar::class);
        $this->app->singleton(SesiService::class);
        $this->app->singleton(KonflikJadwal::class);
        $this->app->singleton(PenempatanService::class);
        $this->app->singleton(PengawasService::class);
        $this->app->singleton(TokenSesi::class);

        // `scoped`, BUKAN singleton: kelas ini menyimpan hasil kocokan benih
        // di memori supaya satu laman tidak menghitung ulang peta event yang
        // sama tiga kali - dan simpanan itu harus mati di akhir permintaan.
        // Singleton akan menyajikannya ke permintaan BERIKUTNYA, sehingga
        // laman bisa menggambar kocokan lama sesudah Admin menekan Kocok
        // ulang. `NomorKursi` dan `PapanKursi` menyelesaikannya lewat
        // `app()` di dalam method; mengikatnya di sini membuat seluruh laman
        // yang memakainya berbagi satu simpanan.
        $this->app->scoped(PetaKursiEvent::class);
        $this->app->scoped(PenempatanKocok::class);
        $this->app->scoped(NomorKursi::class);
        $this->app->scoped(PapanKursi::class);

        // Kontrak Event diikat ke implementasi Exam. Satu arah - lihat
        // catatan kelas ini.
        $this->app->singleton(ButirKesiapanUjian::class, KesiapanUjian::class);

        // Kontrak kedua, ditambahkan 2026-11-14 - alasan yang sama persis:
        // laman soal per mapel di konsol Admin membaca `exam_questions` dan
        // `questions`, dan keduanya bukan milik modul Event.
        $this->app->singleton(ButirSoalPerMapel::class, ButirSoalEvent::class);
    }

    public function boot(): void
    {
        foreach ([
            Exam::class,
            ExamClass::class,
            ExamSession::class,
            ExamSessionRoom::class,
            ExamParticipant::class,
            SupervisionAssignment::class,
            ExamToken::class,
            ExamTokenView::class,
        ] as $model) {
            Gate::policy($model, ExamPolicy::class);
        }

        $this->sambungkanRelasiUjian();
    }

    /**
     * `SchoolEvent::exams()` dirajut dari sini, bukan dideklarasikan di Event.
     *
     * ---------------------------------------------------------------------
     * Mengapa bukan satu method di SchoolEvent
     * ---------------------------------------------------------------------
     * Method `exams()` di SchoolEvent berarti satu baris:
     *
     *     return $this->hasMany(App\Domain\Exam\Models\Exam::class);
     *
     * dan baris itu menambahkan `Event -> Exam` ke peta modul - tepat arah
     * yang DomainBoundariesTest catat sebagai lingkaran terlarang. Lingkaran
     * itu bukan kerapian: gerbang kesiapan sudah menerima jawabannya lewat
     * ButirKesiapanUjian, sehingga Event tidak pernah perlu tahu bahwa ujian
     * itu ada.
     *
     * resolveRelationUsing menaruh peraknya di sini - satu-satunya berkas yang
     * memang dikecualikan dari pemeriksaan impor, karena merangkai modul lewat
     * container adalah tugasnya.
     *
     * ---------------------------------------------------------------------
     * Yang menjadi rusak tanpa ini, dan tampak tidak berhubungan
     * ---------------------------------------------------------------------
     * Tanpa rajut ini, `$event->exams()` melempar BadMethodCallException -
     * dan galatnya muncul di laman RINGKASAN EVENT (plate 12), karena
     * EventReadiness memanggil KesiapanUjian, yang memanggilnya. Gejalanya
     * adalah "laman ringkasan event rusak setelah Fase 5", bukan "relasi ujian
     * hilang", dan itu jarak yang membuatnya mahal dicari.
     */
    private function sambungkanRelasiUjian(): void
    {
        SchoolEvent::resolveRelationUsing('exams', function (SchoolEvent $event) {
            return $event->hasMany(Exam::class, 'event_id');
        });
    }
}
