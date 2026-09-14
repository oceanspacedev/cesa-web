<?php

namespace Cesa\Rekrutmen\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class WhatsAppEngineClient
{
    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        try {
            $response = $this->http()->timeout(2)->get($this->url('/health'));

            if (! $response->successful()) {
                return ['ok' => false];
            }

            return is_array($response->json()) ? $response->json() : ['ok' => false];
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
        return $this->json($this->http()->get($this->url('/sessions/'.$sessionId)));
    }

    /**
     * @return array<string, mixed>
     */
    public function logout(string $sessionId): array
    {
        return $this->json(
            $this->http()
                ->withBody(json_encode(['logout' => true]), 'application/json')
                ->delete($this->url('/sessions/'.$sessionId))
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(string $sessionId, string $phone, string $text, string $idempotencyKey): array
    {
        $response = $this->http()->post($this->url('/sessions/'.$sessionId.'/send'), [
            'phone'           => $phone,
            'text'            => $text,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $this->sendResult($response);
    }

    /** @return array<string, mixed>|null */
    public function message(string $sessionId, string $idempotencyKey): ?array
    {
        $response = $this->http()->timeout(3)->get($this->url('/sessions/'.$sessionId.'/messages/'.rawurlencode($idempotencyKey)));

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
        return rtrim((string) config('rekrutmen.notifications.whatsapp.engine_url', 'http://127.0.0.1:3318'), '/');
    }

    protected function url(string $path): string
    {
        return $this->baseUrl().$path;
    }

    protected function http(): PendingRequest
    {
        $timeout = (int) config('rekrutmen.notifications.whatsapp.http_timeout', 20);

        return Http::connectTimeout(2)->timeout(max(5, $timeout))
            ->acceptJson()
            ->asJson();
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            $payload = ['message' => $response->body()];
        }

        if (! $response->successful()) {
            throw new RuntimeException((string) ($payload['message'] ?? 'Engine WhatsApp mengembalikan error.'));
        }

        return $payload;
    }
}
