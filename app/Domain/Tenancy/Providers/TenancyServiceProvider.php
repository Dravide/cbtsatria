<?php

namespace App\Domain\Tenancy\Providers;

use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped(), bukan singleton(): resolver menyimpan hasil resolusi
        // untuk satu permintaan, dan di antara permintaan ia harus mulai
        // bersih. Di bawah Octane singleton akan bocor antar permintaan.
        $this->app->scoped(SchoolResolver::class);
    }

    public function boot(): void
    {
        //
    }
}
