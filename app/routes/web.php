<?php

use App\Http\Controllers\LogoController;
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

// Brand logos, streamed from the private disk. Public so they render on guest
// shells (login, register, intake); a missing logo simply 404s.
Route::get('branding/logo', [LogoController::class, 'branding'])->name('branding.logo');
Route::get('companies/{company}/logo', [LogoController::class, 'company'])->name('companies.logo');

// Tokenized self-service lookup (Phase 10): the magic link a public requester is
// handed at intake. The opaque token is the only credential; throttled to blunt
// link guessing. An unknown/expired token 404s.
Volt::route('ticket/{token}', 'portal.ticket')
    ->middleware('throttle:60,1')
    ->name('portal.ticket');

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

    // Incident broadcast to every requester with a live ticket — administrator only.
    Volt::route('broadcast', 'broadcast.index')->name('broadcast');

    // Configuration / classification audit trail — administrator only.
    Volt::route('audit', 'audit.index')->name('audit');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
    // WhatsApp API credentials — administrator only (enforced in the page).
    Volt::route('settings/whatsapp', 'settings.whatsapp')->name('settings.whatsapp');

    // Canned responses / macros — administrator only (enforced in the page).
    Volt::route('settings/canned-responses', 'settings.canned-responses')->name('settings.canned-responses');

    // DB-managed classification lookups — administrator only (enforced in the page).
    Volt::route('settings/categories', 'settings.categories')->name('settings.categories');
    Volt::route('settings/priorities', 'settings.priorities')->name('settings.priorities');

    // Knowledge base self-help articles — administrator only (enforced in the page).
    Volt::route('settings/knowledge-base', 'settings.knowledge-base')->name('settings.knowledge-base');

    // Project title + brand logo (global branding) — administrator only.
    Volt::route('settings/branding', 'settings.branding')->name('settings.branding');
});

require __DIR__.'/auth.php';
