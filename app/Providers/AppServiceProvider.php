<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Wargaming\AccountProgress;
use App\Services\Wargaming\TechTree;
use App\Services\Wargaming\TechTreeLines;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Singletons for the request, not for performance alone.
         *
         * Three boards over the tech tree are built on every render of the
         * grinding page, and each injects these. Without binding, every one
         * gets its own TechTree — so WotVehicle::all() ran three times per
         * request — and its own unmemoised line list on top of that.
         */
        $this->app->singleton(TechTree::class);
        $this->app->singleton(TechTreeLines::class);
        $this->app->singleton(AccountProgress::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
