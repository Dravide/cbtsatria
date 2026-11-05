<?php

namespace App\Domain\Identity\Providers;

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\Policies\ActivityLogPolicy;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\UserPolicy;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Policies\SchoolPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActivityLogger::class);
    }

    public function boot(): void
    {
        // Terdaftar eksplisit, tidak mengandalkan auto-discovery.
        //
        // Penemuan otomatis Laravel memetakan App\Models\X -> App\Policies\XPolicy.
        // Model di sini hidup di App\Domain\*\Models, jadi penemuan itu tidak
        // akan menemukannya. (Atribut #[UsePolicy] di masing-masing model
        // menangani ini untuk modelnya sendiri; pendaftaran di sini adalah
        // jaring pengaman yang membuat pemetaannya terbaca di satu tempat.)
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(School::class, SchoolPolicy::class);
        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);
    }
}
