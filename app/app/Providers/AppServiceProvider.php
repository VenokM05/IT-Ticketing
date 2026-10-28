<?php

namespace App\Providers;

use App\Services\AppSettings;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

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
        // Livewire page components resolve their layout as "layouts::app";
        // point that namespace at the Flux layout components (PRD §6.1 dashboard).
        View::addNamespace('layouts', resource_path('views/components/layouts'));

        // Admin-set project title overrides the env name for every display surface
        // (browser title, sidebar, logo). Guarded so boot survives before the
        // settings table exists.
        if (($title = AppSettings::title()) !== null) {
            config(['app.name' => $title]);
        }
    }
}
