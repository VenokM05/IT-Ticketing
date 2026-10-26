<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over the WhatsApp Cloud API send endpoint (PRD §6.3, §8.5, §11).
 * Returns the WhatsApp message ID on success so the caller can persist it for
 * later delivery-status reconciliation.
 */
class WhatsAppCloudApi
{
    public function sendText(string $to, string $body): string
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ]);
    }

    /**
     * @param  array<int, array{name?: string, text?: string}>  $parameters
     */
    public function sendTemplate(string $to, string $name, string $language, array $parameters = []): string
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $name,
                'language' => ['code' => $language],
                'components' => $parameters === [] ? [] : [[
                    'type' => 'body',
                    'parameters' => array_values($parameters),
                ]],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(array $payload): string
    {
        $response = $this->request()->post(
            sprintf('%s/%s/%s/messages', $this->config('base_url'), $this->config('api_version'), $this->config('phone_number_id')),
            $payload,
        );

        $data = $response->json() ?? [];

        if ($response->failed()) {
            throw new RuntimeException(
                'WhatsApp send failed: '.($data['error']['message'] ?? $response->status()),
            );
        }

        return $data['messages'][0]['id'] ?? throw new RuntimeException('WhatsApp send returned no message id.');
    }

    protected function request(): PendingRequest
    {
        return Http::withToken($this->config('access_token'))
            ->acceptJson()
            ->timeout(15);
    }

    protected function config(string $key): mixed
    {
        return config('services.whatsapp.'.$key);
    }
}
