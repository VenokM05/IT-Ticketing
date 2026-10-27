<?php

use App\Http\Controllers\ReportExportController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// Public, no-login ticket submission (the "submit-ticket" web form). Throttled to
// blunt spam on an open endpoint; the page also rate-limits and honeypots per submit.
Volt::route('submit-ticket', 'public.submit-ticket')
    ->middleware('throttle:20,1')
    ->name('public.submit-ticket');

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('dashboard', 'dashboard')->name('dashboard');
    Volt::route('notifications', 'notifications')->name('notifications');

    Volt::route('tickets', 'tickets.index')->name('tickets');
    // Manual submission on behalf of an employee (admin-only via TicketPolicy::create).
    Volt::route('tickets/create', 'tickets.create')->name('tickets.create');
    Volt::route('tickets/{ticket}', 'tickets.show')
        ->name('tickets.show');

    // Employee Directory — administrator only (§10.7); enforced by EmployeePolicy.
    Volt::route('employees', 'employees.index')->name('employees');
    Volt::route('employees/{employee}', 'employees.show')
        ->name('employees.show');

    // Number-relink approvals — administrator only (§9.3, §10.7).
    Volt::route('relinks', 'relinks.index')->name('relinks');

    // Companies — multi-tenant tagging, administrator only.
    Volt::route('companies', 'companies.index')->name('companies');

    // Staff / technician routing (home company, skills, on-call) — administrator only.
    Volt::route('staff', 'staff.index')->name('staff');

    // Operational reporting — administrator only (Phase 6); gated in the page/controller.
    Volt::route('reports', 'reports.index')->name('reports');
    Route::get('reports/export', ReportExportController::class)->name('reports.export');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
    // WhatsApp API credentials — administrator only (enforced in the page).
    Volt::route('settings/whatsapp', 'settings.whatsapp')->name('settings.whatsapp');
});

require __DIR__.'/auth.php';
