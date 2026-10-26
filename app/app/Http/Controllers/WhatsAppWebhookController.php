<?php

namespace App\Http\Controllers;

use App\Services\WebhookEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * WhatsApp Cloud API webhook endpoint (PRD §8.1, §8.2). Stateless: verification
 * handshake plus signature-validated inbound event ingestion.
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(private readonly WebhookEventRecorder $recorder)
    {
    }

    /**
     * Meta subscription verification (§8.1). Returns the challenge only when the
     * configured verify token matches.
     */
    public function verify(Request $request): Response
    {
        // Laravel converts "." to "_" in request input keys, so Meta's hub.*
        // query params arrive here as hub_mode / hub_verify_token / hub_challenge.
        $query = $request->query();
        $expected = (string) config('services.whatsapp.verify_token');
        $token = (string) ($query['hub_verify_token'] ?? '');

        if (($query['hub_mode'] ?? null) === 'subscribe'
            && $expected !== ''
            && hash_equals($expected, $token)) {
            return response((string) ($query['hub_challenge'] ?? ''));
        }

        abort(403, 'Webhook verification failed.');
    }

    /**
     * Inbound events (§8.2). Validate the signature against the raw body before
     * any parsing, persist idempotently, then acknowledge quickly.
     */
    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        if (! $this->hasValidSignature($raw, (string) $request->header('X-Hub-Signature-256', ''))) {
            abort(401, 'Invalid webhook signature.');
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload) || ($payload['object'] ?? null) !== 'whatsapp_business_account') {
            abort(422, 'Unexpected webhook payload structure.');
        }

        $this->recorder->record($payload);

        return response()->json(['status' => 'received']);
    }

    private function hasValidSignature(string $raw, string $signature): bool
    {
        $secret = (string) config('services.whatsapp.app_secret');

        if ($secret === '') {
            // No secret configured — reject rather than accept unverified traffic.
            return false;
        }

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);

        return hash_equals($expected, $signature);
    }
}
