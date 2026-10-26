<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API (PRD §8, §9, §11)
    |--------------------------------------------------------------------------
    |
    | Webhook credentials drive inbound validation; messaging credentials drive
    | outbound sends. Business rule windows are configurable per deployment.
    |
    */

    'whatsapp' => [
        'enabled' => env('WHATSAPP_ENABLED', false),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),

        // Business rule windows (§9.4, §9.5, §11.1).
        'session_window_hours' => (int) env('WHATSAPP_SESSION_WINDOW_HOURS', 24),
        'followup_window_minutes' => (int) env('WHATSAPP_FOLLOWUP_WINDOW_MINUTES', 60),
        'duplicate_inquiry_minutes' => (int) env('WHATSAPP_DUPLICATE_INQUIRY_MINUTES', 10),
        'unknown_guidance_cooldown_hours' => (int) env('WHATSAPP_UNKNOWN_GUIDANCE_COOLDOWN_HOURS', 24),
    ],

];
