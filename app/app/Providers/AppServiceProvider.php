<?php

namespace App\Providers;

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
    }
}
