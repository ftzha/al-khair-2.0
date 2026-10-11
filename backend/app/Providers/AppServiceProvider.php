<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Generate absolute URLs using the public Laravel address.
        // Docker's internal "web" hostname must not appear in email links.
        URL::forceRootUrl(config('app.url'));
    }
}
