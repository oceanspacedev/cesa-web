<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class WagHubEngineClient
{
    public function __construct(
        protected ?string $urlOverride = null,
        protected ?string $tokenOverride = null,
    ) {}

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && (! $this->requiresToken() || $this->token() !== '');
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        try {
            $response = $this->http()->timeout(2)->get($this->url('/health'));
            $payload = $response->json();

            if (! is_array($payload)) {
                return ['ok' => false];
            }

            return array_merge($payload, ['ok' => $response->successful() && (bool) ($payload['ok'] ?? false)]);
        } catch (Throwable) {
            return ['ok' => false];
        }
    }

    public function isReady(): bool
    {
        return (bool) ($this->health()['ok'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function startSession(string $sessionId, string $mode = 'qr', ?string $phone = null): array
    {
        return $this->json($this->http()->timeout(25)->post($this->url('/sessions'), array_filter([
            'id'    => $sessionId,
            'mode'  => $mode,
            'phone' => $phone,
        ], fn ($value): bool => $value !== null && $value !== '')));
    }

    /**
     * @return array<string, mixed>
     */
    public function session(string $sessionId): array
    {
        return $this->json($this->http()->get($this->url('/sessions/'.rawurlencode($sessionId))));
    }

    /**
     * @return array<string, mixed>
     */
    public function logout(string $sessionId): array
    {
        return $this->json(
            $this->http()
                ->withBody(json_encode(['logout' => true]), 'application/json')
                ->delete($this->url('/sessions/'.rawurlencode($sessionId)))
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(string $sessionId, string $phone, string $text, string $idempotencyKey): array
    {
        $response = $this->http()->post($this->url('/sessions/'.rawurlencode($sessionId).'/send'), [
            'phone'           => $phone,
            'text'            => $text,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $this->sendResult($response);
    }

    /** @return array<string, mixed>|null */
    public function message(string $sessionId, string $idempotencyKey): ?array
    {
        $response = $this->http()->timeout(3)->get($this->url('/sessions/'.rawurlencode($sessionId).'/messages/'.rawurlencode($idempotencyKey)));

        if ($response->status() === 404) {
            return null;
        }

        return $this->sendResult($response);
    }

    /** @return array<string, mixed> */
    protected function sendResult(Response $response): array
    {
        $payload = $response->json();

        if (is_array($payload) && in_array($payload['status'] ?? null, ['sent', 'unknown', 'failed'], true)) {
            if (($payload['status'] ?? null) !== 'sent' || ($response->successful() && ($payload['ok'] ?? false))) {
                return $payload;
            }
        }

        return [
            'ok'        => false,
            'status'    => 'unknown',
            'retryable' => false,
            'message'   => 'Hasil pengiriman belum dapat dipastikan. Periksa status sebelum mengirim ulang.',
        ];
    }

    public function baseUrl(): string
    {
        return rtrim(trim($this->urlOverride ?? (string) config('wag.engine_url')), '/');
    }

    protected function token(): string
    {
        return trim($this->tokenOverride ?? (string) config('wag.engine_token'));
    }

    protected function requiresToken(): bool
    {
        return true;
    }

    protected function httpTimeout(): int
    {
        return (int) config('wag.timeout', 20);
    }

    protected function url(string $path): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('WAG Hub belum dikonfigurasi. Isi WAG_URL dan WAG_TOKEN.');
        }

        return $this->baseUrl().$path;
    }

    protected function http(): PendingRequest
    {
        $timeout = $this->httpTimeout();

        $request = Http::withoutRedirecting()->connectTimeout(2)->timeout(max(5, $timeout))
            ->acceptJson()
            ->asJson();

        $token = $this->token();

        return $token !== '' ? $request->withToken($token) : $request;
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            $payload = ['message' => 'Respons WAG Hub tidak valid.'];
        }

        if (! $response->successful()) {
            throw new RuntimeException((string) ($payload['message'] ?? 'Engine WhatsApp mengembalikan error.'));
        }

        return $payload;
    }
}
