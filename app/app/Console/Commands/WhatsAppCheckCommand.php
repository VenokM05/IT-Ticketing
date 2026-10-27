<?php

namespace App\Console\Commands;

use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppSettings;
use Illuminate\Console\Command;

/**
 * Readiness report for the WhatsApp integration: proves every stage (outbound
 * send, webhook verification, media handling, templates) has what it needs
 * before flipping WHATSAPP_ENABLED on.
 */
class WhatsAppCheckCommand extends Command
{
    protected $signature = 'whatsapp:check';

    protected $description = 'Verify the WhatsApp integration is fully configured and wired';

    public function handle(): int
    {
        $checks = [
            'Outbound enabled (WHATSAPP_ENABLED)' => (bool) config('services.whatsapp.enabled'),
            'API key (access token)' => $this->credentialPresent('access_token'),
            'Phone number ID' => (string) config('services.whatsapp.phone_number_id') !== '',
            'Webhook app secret (signature validation)' => $this->credentialPresent('app_secret'),
            'Webhook verify token (subscription handshake)' => $this->credentialPresent('verify_token'),
            'Webhook routes registered' => $this->webhookRoutesRegistered(),
            'Approved templates available' => WhatsAppTemplate::query()->where('approval_status', 'approved')->exists(),
        ];

        foreach ($checks as $label => $ok) {
            $this->line(sprintf('  %s %s', $ok ? '<fg=green>✔</>' : '<fg=red>✘</>', $label));
        }

        $missing = array_keys(array_filter($checks, fn (bool $v) => ! $v));

        if ($missing === []) {
            $this->info('WhatsApp integration is fully configured.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d item(s) missing — outbound/messaging will not work until resolved.', count($missing)));

        return self::FAILURE;
    }

    private function credentialPresent(string $key): bool
    {
        return (string) WhatsAppSettings::credential($key) !== '';
    }

    private function webhookRoutesRegistered(): bool
    {
        // GET (handshake) and POST (ingestion) share the URI but are separate routes.
        $methods = [];
        foreach (app('router')->getRoutes() as $route) {
            if ($route->uri() === 'api/whatsapp/webhook') {
                $methods = array_merge($methods, $route->methods());
            }
        }

        return in_array('GET', $methods, true) && in_array('POST', $methods, true);
    }
}
