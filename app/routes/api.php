<?php

use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WhatsApp Cloud API webhook (PRD §8)
|--------------------------------------------------------------------------
| Registered under the stateless "api" middleware group (no CSRF), so Meta can
| reach it directly. Path: /api/whatsapp/webhook
*/

Route::get('whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('whatsapp/webhook', [WhatsAppWebhookController::class, 'handle']);
