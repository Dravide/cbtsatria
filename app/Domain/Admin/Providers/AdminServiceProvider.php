<?php

namespace App\Domain\Admin\Providers;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Academic\Policies\AcademicPolicy;
use App\Domain\Admin\Services\UserRegistrar;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\Policies\ActivityLogPolicy;
use App\Domain\Facility\Models\Room;
use App\Domain\Facility\Models\RoomEntryLink;
use App\Domain\Facility\Policies\FacilityPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root konsol Admin.
 *
 * Policy didaftarkan eksplisit, tidak mengandalkan penemuan otomatis: Laravel
 * memetakan App\Models\X -> App\Policies\XPolicy, sementara model di sini hidup
 * di App\Domain\*\Models. Atribut #[UsePolicy] pada tiap model sudah menangani
 * ini untuk modelnya sendiri; pendaftaran di sini membuat pemetaannya terbaca
 * di satu tempat.
 *
 * AcademicPolicy dan FacilityPolicy sengaja dipakai bersama oleh beberapa
 * model: aturannya identik, dan aturan identik yang ditulis empat kali adalah
 * empat tempat yang bisa berbeda diam-diam.
 */
class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UserRegistrar::class);
    }

    public function boot(): void
    {
        Gate::policy(AcademicYear::class, AcademicPolicy::class);
        Gate::policy(SchoolClass::class, AcademicPolicy::class);
        Gate::policy(Subject::class, AcademicPolicy::class);
        Gate::policy(TeachingAssignment::class, AcademicPolicy::class);

        Gate::policy(Room::class, FacilityPolicy::class);
        Gate::policy(RoomEntryLink::class, FacilityPolicy::class);

        // Diulang dari IdentityServiceProvider supaya modul Admin tetap utuh
        // bila provider itu dilepas. Mendaftarkan policy dua kali tidak
        // bermasalah - yang terakhir menang, dan keduanya menunjuk kelas
        // yang sama.
        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);
    }
}
