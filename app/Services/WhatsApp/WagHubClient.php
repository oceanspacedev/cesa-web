<?php

namespace App\Services\WhatsApp;

use App\Models\WagIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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
        $this->url = rtrim($url ?? (string) config('wag.url'), '/');
        $this->token = trim($token ?? (string) config('wag.token'));
        $this->engineUrl = rtrim($engineUrl ?? ($url !== null ? $this->url.'/api/v2' : (string) config('wag.engine_url')), '/');
        $this->engineToken = trim($engineToken ?? $token ?? (string) config('wag.engine_token'));
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
     * @param  string  $phone  Nomor WhatsApp tujuan (format lokal 08... atau internasional 628...)
     * @param  string  $text  Isi pesan teks
     * @param  array<string, mixed>  $options  Konfigurasi tambahan: idempotency_key, mode (sync/async), route_key, purpose, client_reference, timeout
     * @return array<string, mixed>
     */
    public function sendMessage(string $phone, string $text, array $options = []): array
    {
        $engine = $this->engine();
        if ($engine->isV2()) {
            if (empty($options['session_id'])) {
                throw new RuntimeException('Pilih akun WhatsApp sebelum menjadwalkan pesan.');
            }

            return $engine->sendText($options['session_id'], $phone, $text, (string) ($options['idempotency_key'] ?? Str::uuid()));
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('WAG Hub URL atau Token belum dikonfigurasi di file .env (WAG_URL, WAG_TOKEN).');
        }

        $idempotencyKey = (string) ($options['idempotency_key'] ?? (string) Str::uuid());
        $mode = (string) ($options['mode'] ?? 'async');
        $purpose = (string) ($options['purpose'] ?? 'notification');
        $routeKey = (string) ($options['route_key'] ?? 'default');
        $clientReference = (string) ($options['client_reference'] ?? config('app.name'));
        $timeout = (int) ($options['timeout'] ?? 10);

        $payload = [
            'recipient' => [
                'type'  => 'phone',
                'value' => $phone,
            ],
            'message' => [
                'type' => 'text',
                'text' => $text,
            ],
            'purpose'          => $purpose,
            'mode'             => $mode,
            'route_key'        => $routeKey,
            'client_reference' => $clientReference,
        ];

        if (isset($options['attachment'])) {
            $payload['message'] = array_merge($payload['message'], [
                'type'       => $options['attachment_type'] ?? 'document',
                'attachment' => $options['attachment'],
            ]);
        }

        $response = Http::withoutRedirecting()->connectTimeout(2)->timeout($timeout)
            ->acceptJson()
            ->withHeaders([
                'Authorization'   => 'Bearer '.$this->token,
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->post($this->url.'/api/v1/messages', $payload);

        return $response->json() ?? ['ok' => $response->successful(), 'status' => $response->status()];
    }

    /**
     * Cek apakah nomor terdaftar di WhatsApp (/api/v1/numbers/check).
     *
     * @return array<string, mixed>
     */
    public function validateNumber(string $phone, int $timeout = 5): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('WAG Hub URL atau Token belum dikonfigurasi di file .env (WAG_URL, WAG_TOKEN).');
        }

        $response = Http::withoutRedirecting()->connectTimeout(2)->timeout($timeout)
            ->acceptJson()
            ->withToken($this->token)
            ->post($this->url.'/api/v1/numbers/check', [
                'phone' => $phone,
            ]);

        return $response->json() ?? ['ok' => false];
    }

    /**
     * Client sesi WhatsApp untuk modul atau aplikasi apa pun.
     */
    public function engine(): WagHubEngineClient
    {
        if (Schema::hasTable('wag_integrations') && ($stored = WagIntegration::query()->find(1)) && $stored->url) {
            return new WagHubEngineClient($stored->url.'/api/v2', $stored->token);
        }

        return new WagHubEngineClient($this->engineUrl, $this->engineToken);
    }
}
