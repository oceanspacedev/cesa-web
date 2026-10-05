<?php

namespace App\Services\WhatsApp;

use App\Models\WagIntegration;
use Illuminate\Http\Client\Response;
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
        $this->url = static::normalizeHubOrigin($url ?? (string) config('wag.url'));
        $this->token = trim($token ?? (string) config('wag.token'));
        $this->engineUrl = rtrim($engineUrl ?? ($url !== null ? $this->url.'/api/v2' : (string) config('wag.engine_url')), '/');
        $this->engineToken = trim($engineToken ?? $token ?? (string) config('wag.engine_token'));
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->token !== '';
    }

    /**
     * Apakah kredensial Hub tersedia untuk mengirim pesan, termasuk override dari tabel wag_integrations.
     */
    public function isHubConfigured(): bool
    {
        [$url, $token] = $this->hubCredentials();

        return $url !== '' && $token !== '';
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
     * @param  array<string, mixed>  $options  Konfigurasi tambahan: idempotency_key, mode (sync/async), route_key, purpose (otp atau transactional), expires_at, client_reference, timeout, force_hub, attachment_type, attachment
     * @return array<string, mixed>
     */
    public function sendMessage(string $phone, string $text, array $options = []): array
    {
        if (! ($options['force_hub'] ?? false)) {
            $engine = $this->engine();
            if ($engine->isV2()) {
                if (empty($options['session_id'])) {
                    throw new RuntimeException('Pilih akun WhatsApp sebelum menjadwalkan pesan.');
                }

                return $engine->sendText($options['session_id'], $phone, $text, (string) ($options['idempotency_key'] ?? Str::uuid()));
            }
        }

        [$url, $token] = $this->hubCredentials();
        if ($url === '' || $token === '') {
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

        if (filled($options['expires_at'] ?? null)) {
            $payload['expires_at'] = (string) $options['expires_at'];
        }

        if (isset($options['attachment'])) {
            $payload['message'] = array_merge($payload['message'], [
                'type'       => $options['attachment_type'] ?? 'document',
                'attachment' => $options['attachment'],
            ]);
        }

        $response = Http::withoutRedirecting()->connectTimeout(2)->timeout($timeout)
            ->acceptJson()
            ->withHeaders([
                'Authorization'   => 'Bearer '.$token,
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->post($url.'/api/v1/messages', $payload);

        return $this->hubResponse($response);
    }

    public function uploadAttachment(string $contents, string $filename, string $mimeType): string
    {
        [$url, $token] = $this->hubCredentials();
        if ($url === '' || $token === '') {
            throw new RuntimeException('WAG Hub URL atau Token belum dikonfigurasi di file .env (WAG_URL, WAG_TOKEN).');
        }

        if ($contents === '') {
            throw new RuntimeException('Lampiran WhatsApp tidak boleh kosong.');
        }

        $response = Http::withoutRedirecting()->connectTimeout(2)->timeout((int) config('wag.timeout', 20))
            ->acceptJson()
            ->withToken($token)
            ->attach('file', $contents, $filename, ['Content-Type' => $mimeType])
            ->post($url.'/api/v1/attachments');

        $attachmentId = data_get($this->hubResponse($response), 'data.id');
        if (! is_string($attachmentId) || ! Str::isUuid($attachmentId)) {
            throw new RuntimeException('WAG Hub tidak mengembalikan ID lampiran yang valid.');
        }

        return $attachmentId;
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
            return new WagHubEngineClient(static::normalizeHubOrigin((string) $stored->url).'/api/v2', $stored->token);
        }

        return new WagHubEngineClient($this->engineUrl, $this->engineToken);
    }

    /**
     * @return array{string, string}
     */
    protected function hubCredentials(): array
    {
        if (Schema::hasTable('wag_integrations') && ($stored = WagIntegration::query()->find(1)) && filled($stored->url) && filled($stored->token)) {
            return [static::normalizeHubOrigin((string) $stored->url), trim((string) $stored->token)];
        }

        return [$this->url, $this->token];
    }

    public static function normalizeHubOrigin(string $url): string
    {
        $url = rtrim($url, '/');
        $url = (string) preg_replace('#/api/v[12]$#', '', $url);

        return rtrim($url, '/');
    }

    /**
     * @return array<string, mixed>
     */
    protected function hubResponse(Response $response): array
    {
        $payload = $response->json();

        if (! $response->successful()) {
            $message = is_array($payload) ? ($payload['message'] ?? data_get($payload, 'error.message')) : null;

            throw new RuntimeException(is_string($message) && $message !== '' ? $message : 'WAG Hub mengembalikan HTTP '.$response->status().'.', $response->status());
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Respons WAG Hub tidak valid.');
        }

        return $payload;
    }
}
