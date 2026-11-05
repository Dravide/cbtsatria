<?php

namespace App\Domain\Question\Providers;

use App\Domain\Question\Models\EssayRubricCriterion;
use App\Domain\Question\Models\EventQuestionPackage;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionImportBatch;
use App\Domain\Question\Models\QuestionItem;
use App\Domain\Question\Models\QuestionMedia;
use App\Domain\Question\Models\QuestionOption;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use App\Domain\Question\Policies\QuestionPolicy;
use App\Domain\Question\Services\BankSoalService;
use App\Domain\Question\Services\ImporSoalService;
use App\Domain\Question\Services\PaketSoalService;
use App\Domain\Question\Services\PemasanganPaket;
use App\Domain\Question\Services\PenghitungButir;
use App\Domain\Question\Support\PembersihHtml;
use App\Domain\Question\Support\SkemaJenisSoal;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root modul Question.
 *
 * ---------------------------------------------------------------------
 * Satu QuestionPolicy untuk sembilan model
 * ---------------------------------------------------------------------
 * Alasannya sama dengan EventPolicy dan ExamPolicy: aturannya identik
 * ("sekolahnya sama, dan perannya benar"), dan aturan identik yang ditulis
 * sembilan kali adalah sembilan tempat yang bisa berbeda diam-diam.
 *
 * Yang membedakan QuestionPolicy dari dua policy lain itu bukan jumlah
 * modelnya, melainkan bahwa izin TULIS di sini bukan soal peran melainkan soal
 * KEPEMILIKAN: bank soal adalah aset sekolah, sehingga setiap Guru boleh
 * membaca dan memakai soal Guru lain, tetapi hanya penulisnya yang boleh
 * mengubahnya. Itu hidup di method `update()`/`delete()` policy, bukan di
 * pendaftaran di bawah - dan pendaftarannya tetap satu karena policy-nya yang
 * tahu bedanya, bukan provider ini.
 *
 * ---------------------------------------------------------------------
 * PenghitungButir diikat, dan itu yang membuat Event tidak perlu berubah
 * ---------------------------------------------------------------------
 * Gerbang kesiapan event (plate 12) butuh menghitung butir sebuah paket, dan
 * cara termudah adalah memanggil model Question dari dalam EventReadiness -
 * yang melanggar peta modul. Yang dipanggil Event adalah service ini, dan
 * DomainBoundariesTest hanya memeriksa impor `Models\` dan `Http\`, sehingga
 * jalur service ini sah tanpa satu pun entri peta baru.
 *
 * Diikat sebagai singleton dengan alasan yang sama seperti KonflikJadwal:
 * satu kali buka laman kesiapan memanggilnya untuk setiap mapel event, dan
 * satu objek lebih murah daripada lima.
 *
 * ---------------------------------------------------------------------
 * SkemaJenisSoal dan PembersihHtml: singleton, dan bukan karena mahal
 * ---------------------------------------------------------------------
 * Keduanya tanpa keadaan (stateless) - tidak ada properti yang diisi setelah
 * konstruksi. Singleton di sini bukan optimasi melainkan pernyataan: kelas
 * yang tidak menyimpan apa pun antara dua panggilan memang hanya perlu satu
 * contoh, dan menjadikannya singleton sekarang membuat penambahan keadaan
 * nanti terlihat sebagai perubahan perilaku, bukan sebagai kebetulan yang
 * lolos di mesin pengembang dan gagal di produksi.
 */
class QuestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SkemaJenisSoal::class);
        $this->app->singleton(PembersihHtml::class);

        $this->app->singleton(BankSoalService::class);
        $this->app->singleton(PaketSoalService::class);
        $this->app->singleton(PemasanganPaket::class);
        $this->app->singleton(PenghitungButir::class);

        // Tanpa keadaan, seperti SkemaJenisSoal: yang dipegangnya hanyalah
        // pembaca, pembersih, dan dua service lain. Singleton di sini bukan
        // optimasi melainkan pernyataan - kelas yang tidak menyimpan apa pun
        // antara dua panggilan memang hanya perlu satu contoh.
        $this->app->singleton(ImporSoalService::class);
    }

    public function boot(): void
    {
        foreach ([
            Question::class,
            QuestionOption::class,
            QuestionItem::class,
            QuestionMedia::class,
            EssayRubricCriterion::class,
            QuestionPackage::class,
            QuestionPackageItem::class,
            EventQuestionPackage::class,
            QuestionImportBatch::class,
        ] as $model) {
            Gate::policy($model, QuestionPolicy::class);
        }
    }
}
