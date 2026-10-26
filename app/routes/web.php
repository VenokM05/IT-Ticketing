<?php

use App\Http\Controllers\ReportExportController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('dashboard', 'dashboard')->name('dashboard');
    Volt::route('notifications', 'notifications')->name('notifications');

    Volt::route('tickets', 'tickets.index')->name('tickets');
    Volt::route('tickets/{ticket}', 'tickets.show')
        ->name('tickets.show');

    // Employee Directory — administrator only (§10.7); enforced by EmployeePolicy.
    Volt::route('employees', 'employees.index')->name('employees');
    Volt::route('employees/{employee}', 'employees.show')
        ->name('employees.show');

    // Number-relink approvals — administrator only (§9.3, §10.7).
    Volt::route('relinks', 'relinks.index')->name('relinks');

    // Operational reporting — administrator only (Phase 6); gated in the page/controller.
    Volt::route('reports', 'reports.index')->name('reports');
    Route::get('reports/export', ReportExportController::class)->name('reports.export');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
