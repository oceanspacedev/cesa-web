<?php

namespace App\Services\WhatsApp;

use Cesa\Rekrutmen\Services\WhatsAppEngineClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client terpadu untuk WAG Hub WhatsApp microservice.
 *
 * Mengakomodasi:
 * 1. Hub API: Notifikasi sistem, broadcast, validasi nomor, dan routing/fallback provider.
 * 2. Engine API: Sesi WhatsApp Web / Baileys per departemen/user (QR code, pairing code, interactive send).
 */
class WagHubClient
{
    protected string $url;
    protected string $token;
    protected string $engineUrl;
    protected string $engineToken;

    public function __construct(
        ?string $url = null,
        ?string $token = null,
        ?string $engineUrl = null,
        ?string $engineToken = null,
    ) {
        $this->url = rtrim($url ?: (string) env('WAG_URL', 'https://waghub.mekayastudio.com'), '/');
        $this->token = trim($token ?: (string) env('WAG_TOKEN'));
        $this->engineUrl = rtrim($engineUrl ?: (string) env('WAG_ENGINE_URL', $this->url . '/api/v1/engine'), '/');
        $this->engineToken = trim($engineToken ?: (string) env('WAG_ENGINE_TOKEN'));
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->token !== '';
    }

    public function isEngineConfigured(): bool
    {
        return $this->engineUrl !== '' && $this->engineToken !== '';
    }

    public function url(): string
    {
        return $this->url;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function engineUrl(): string
    {
        return $this->engineUrl;
    }

    public function engineToken(): string
    {
        return $this->engineToken;
    }

    /**
     * Kirim pesan notifikasi melalui Hub API (/api/v1/messages) dengan idempotency key dan fallback routing.
     *
     * @param string $phone Nomor WhatsApp tujuan (format lokal 08... atau internasional 628...)
     * @param string $text Isi pesan teks
     * @param array<string, mixed> $options Konfigurasi tambahan: idempotency_key, mode (sync/async), route_key, purpose, client_reference, timeout
     * @return array<string, mixed>
     */
    public function sendMessage(string $phone, string $text, array $options = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('WAG Hub URL atau Token belum dikonfigurasi di file .env (WAG_URL, WAG_TOKEN).');
        }

        $idempotencyKey = (string) ($options['idempotency_key'] ?? (string) Str::uuid());
        $mode = (string) ($options['mode'] ?? 'async');
        $purpose = (string) ($options['purpose'] ?? 'notification');
        $routeKey = (string) ($options['route_key'] ?? 'default');
        $clientReference = (string) ($options['client_reference'] ?? 'cesa-web');
        $timeout = (int) ($options['timeout'] ?? 10);

        $payload = [
            'recipient' => [
                'type' => 'phone',
                'value' => $phone,
            ],
            'message' => [
                'type' => 'text',
                'text' => $text,
            ],
            'purpose' => $purpose,
            'mode' => $mode,
            'route_key' => $routeKey,
            'client_reference' => $clientReference,
        ];

        if (isset($options['attachment'])) {
            $payload['message'] = array_merge($payload['message'], [
                'type' => $options['attachment_type'] ?? 'document',
                'attachment' => $options['attachment'],
            ]);
        }

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->post($this->url . '/api/v1/messages', $payload);

        return $response->json() ?? ['ok' => $response->successful(), 'status' => $response->status()];
    }

    /**
     * Cek apakah nomor terdaftar di WhatsApp (/api/v1/numbers/check).
     *
     * @param string $phone
     * @param int $timeout
     * @return array<string, mixed>
     */
    public function validateNumber(string $phone, int $timeout = 5): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('WAG Hub URL atau Token belum dikonfigurasi di file .env (WAG_URL, WAG_TOKEN).');
        }

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->withToken($this->token)
            ->post($this->url . '/api/v1/numbers/check', [
                'phone' => $phone,
            ]);

        return $response->json() ?? ['ok' => false];
    }

    /**
     * Akses instance WhatsAppEngineClient untuk lifecycle sesi QR / pairing code Rekrutmen/HR.
     */
    public function engine(): WhatsAppEngineClient
    {
        return app(WhatsAppEngineClient::class);
    }
}
