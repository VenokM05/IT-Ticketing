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
     * Resolve a media object ID to its temporary, authenticated download URL and
     * metadata (PRD §8.4 media handling). The returned URL is short-lived and must
     * be fetched with the bearer token via downloadMedia().
     *
     * @return array{url: string, mime_type: ?string, file_size: ?int, filename: ?string}
     */
    public function mediaUrl(string $mediaId): array
    {
        $response = $this->request()->get(
            sprintf('%s/%s/%s', $this->config('base_url'), $this->config('api_version'), $mediaId),
        );

        $data = $response->json() ?? [];

        if ($response->failed() || empty($data['url'])) {
            throw new RuntimeException(
                'WhatsApp media lookup failed: '.($data['error']['message'] ?? $response->status()),
            );
        }

        return [
            'url' => $data['url'],
            'mime_type' => $data['mime_type'] ?? null,
            'file_size' => isset($data['file_size']) ? (int) $data['file_size'] : null,
            'filename' => $data['filename'] ?? null,
        ];
    }

    /**
     * Fetch the raw bytes for a media download URL returned by mediaUrl().
     * Uses a longer timeout than control-plane calls since this transfers files.
     */
    public function downloadMedia(string $url): string
    {
        $response = Http::withToken($this->config('access_token'))
            ->timeout(60)
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException('WhatsApp media download failed: '.$response->status());
        }

        return $response->body();
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
