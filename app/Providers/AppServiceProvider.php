<?php

namespace App\Providers;

use App\Support\ActiveScope;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One active scope per request (current organisation + active location).
        $this->app->singleton(ActiveScope::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
