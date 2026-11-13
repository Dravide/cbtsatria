<?php

namespace App\Domain\Event\Providers;

use App\Domain\Event\Models\EventActivity;
use App\Domain\Event\Models\EventAnnouncement;
use App\Domain\Event\Models\EventClass;
use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Event\Models\EventTeacherAssignment;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Policies\EventPolicy;
use App\Domain\Event\Services\EventParticipantService;
use App\Domain\Event\Services\EventReadiness;
use App\Domain\Event\Services\EventRegistrar;
use App\Domain\Event\Services\EventStatusService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root modul Event.
 *
 * Policy didaftarkan eksplisit, tidak mengandalkan penemuan otomatis: Laravel
 * memetakan App\Models\X -> App\Policies\XPolicy, sementara model di sini
 * hidup di App\Domain\Event\Models.
 *
 * Satu EventPolicy untuk tujuh model, dengan alasan yang sama seperti
 * AcademicPolicy: aturannya identik untuk semuanya, dan aturan identik yang
 * ditulis tujuh kali adalah tujuh tempat yang bisa berbeda diam-diam. Yang
 * membedakan akses bukan modelnya, melainkan peran pengguna dan - untuk
 * non-admin - apakah ia ditugaskan ke event itu.
 */
class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton: EventReadiness tidak menyimpan keadaan, tetapi
        // EventStatusService menerimanya sebagai dependensi dan keduanya
        // dipanggil berkali-kali dalam satu request (langkahTersedia
        // menghitungnya sekali per tujuan). Singleton membuatnya satu objek,
        // bukan nol karena diselesaikan dua kali.
        $this->app->singleton(EventReadiness::class);
        $this->app->singleton(EventStatusService::class);
        $this->app->singleton(EventRegistrar::class);
        $this->app->singleton(EventParticipantService::class);
    }

    public function boot(): void
    {
        // Semua model event memakai policy yang sama. Yang membedakan bukan
        // modelnya, melainkan perannya - dan penugasan kepanitiaan yang
        // diperiksa di dalam policy itu sendiri.
        foreach ([
            SchoolEvent::class,
            EventActivity::class,
            EventAnnouncement::class,
            EventCommitteeMember::class,
            EventClass::class,
            EventSubject::class,
            EventParticipant::class,
            EventRoom::class,
            EventTeacherAssignment::class,
        ] as $model) {
            Gate::policy($model, EventPolicy::class);
        }
    }
}
