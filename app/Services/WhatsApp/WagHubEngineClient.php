<?php

namespace App\Services\WhatsApp;

use App\Models\WagIntegration;
use App\Models\WagMessageRequest;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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
        if (! $this->isConfigured()) {
            return ['ok' => false];
        }
        try {
            $healthUrl = $this->isV2() ? preg_replace('#/api/v2$#', '', $this->baseUrl()).'/health/ready' : $this->url('/health');
            $response = $this->http()->timeout(2)->get($healthUrl);
            $payload = $response->json();

            if (! is_array($payload)) {
                return ['ok' => false];
            }

            return array_merge($payload, ['ok' => $response->successful() && (bool) ($payload['ok'] ?? $payload['ready'] ?? $payload['data']['ready'] ?? false)]);
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
        if ($this->isV2()) {
            return $mode === 'pairing'
                ? $this->pairingCode($sessionId, (string) $phone)
                : $this->lifecycle($sessionId, 'start');
        }

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
        if ($this->isV2()) {
            return $this->lifecycle($sessionId, 'logout');
        }

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
        if ($this->isV2()) {
            return $this->sendMessage($sessionId, ['to' => $phone, 'text' => $text, 'type' => 'text'], $idempotencyKey);
        }

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
        if ($this->isV2()) {
            $request = WagMessageRequest::query()->where('session_id', $sessionId)->where('request_key', $idempotencyKey)->first();
            if (! $request) {
                return null;
            }
            if (! $request->hub_message_id) {
                return $this->sendMessage($sessionId, $request->payload, $idempotencyKey);
            }
            $result = $this->messageResult($this->json($this->http()->get($this->url('/messages/'.rawurlencode($request->hub_message_id)))));
            $request->update(['result' => $result]);

            return $result;
        }

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

    public function isV2(): bool
    {
        return str_ends_with($this->baseUrl(), '/api/v2');
    }

    protected function storedIntegration(): ?WagIntegration
    {
        return Schema::hasTable('wag_integrations') ? WagIntegration::query()->find(1) : null;
    }

    public function integration(): array
    {
        return $this->json($this->http()->get($this->url('/integration')));
    }

    public function capacity(int $limit): array
    {
        return $this->json($this->http()->patch($this->url('/integration'), ['configured_limit' => $limit]));
    }

    public function registerWebhook(string $url, string $secret): array
    {
        return $this->json($this->http()->put($this->url('/integration/webhook'), compact('url', 'secret')));
    }

    public function sessions(): array
    {
        return $this->json($this->http()->get($this->url('/sessions')));
    }

    public function createSession(string $key, string $name, string $mode = 'qr', ?string $phone = null, ?string $externalReference = null): array
    {
        return $this->json($this->http()->withHeader('Idempotency-Key', $key)->post($this->url('/sessions'), array_filter([
            'name' => $name, 'start' => true, 'auth_method' => $mode, 'phone' => $phone, 'external_reference' => $externalReference,
        ], fn ($value): bool => $value !== null)));
    }

    public function lifecycle(string $id, string $action): array
    {
        $path = '/sessions/'.rawurlencode($id);
        $response = $action === 'delete'
            ? $this->http()->delete($this->url($path))
            : $this->http()->post($this->url($path.'/'.$action));

        return $this->json($response);
    }

    public function rename(string $id, string $name): array
    {
        return $this->json($this->http()->patch($this->url('/sessions/'.rawurlencode($id)), ['name' => $name]));
    }

    public function qr(string $id): array
    {
        return $this->json($this->http()->get($this->url('/sessions/'.rawurlencode($id).'/auth/qr')));
    }

    public function pairingCode(string $id, string $phone): array
    {
        return $this->json($this->http()->post($this->url('/sessions/'.rawurlencode($id).'/auth/pairing-code'), ['phone' => $phone]));
    }

    public function operation(string $id): array
    {
        return $this->json($this->http()->get($this->url('/operations/'.rawurlencode($id))));
    }

    public function events(?string $after): array
    {
        $response = $this->http()->get($this->url('/events'), array_filter(['after' => $after]));
        $this->json($response);

        $payload = $response->json();
        if (isset($payload['data']['events'])) {
            return ['data' => $payload['data']['events'], 'meta' => ['next_cursor' => $payload['data']['next_cursor'] ?? $after, 'has_more' => $payload['data']['has_more'] ?? false]];
        }

        return $payload;
    }

    public function uploadMedia(string $path, string $type = 'document', ?string $filename = null): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Media file cannot be opened.');
        }
        try {
            return $this->json($this->http()->attach('file', $stream, $filename ?: basename($path))->post($this->url('/media'), ['type' => $type]));
        } finally {
            fclose($stream);
        }
    }

    public function media(string $id): Response
    {
        $response = $this->http()->get($this->url('/media/'.rawurlencode($id)));
        if (! $response->successful()) {
            $this->json($response);
        }

        return $response;
    }

    public function sendMessage(string $sessionId, array $payload, string $key): array
    {
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $request = WagMessageRequest::query()->firstOrCreate(['session_id' => $sessionId, 'request_key' => $key], [
            'payload' => $payload, 'payload_hash' => $hash,
        ]);
        if (! hash_equals($request->payload_hash, $hash)) {
            throw new RuntimeException('Idempotency key already used for another message.', 409);
        }
        if ($request->hub_message_id) {
            return $this->message($sessionId, $key);
        }
        $message = $this->json($this->http()->withHeader('Idempotency-Key', $key)->post($this->url('/sessions/'.rawurlencode($sessionId).'/messages'), $payload));
        $result = $this->messageResult($message);
        $request->update(['hub_message_id' => $message['id'] ?? null, 'result' => $result]);

        return $result;
    }

    protected function messageResult(array $message): array
    {
        $transport = $message['transport_status'] ?? 'outcome_unknown';
        $status = match ($transport) {
            'accepted' => 'sent',
            'failed', 'expired' => 'failed',
            default => 'unknown',
        };

        return array_merge($message, [
            'ok'         => $status === 'sent', 'status' => $status, 'retryable' => false,
            'message_id' => $message['id'] ?? null,
            'message'    => $message['last_error'] ?? ($status === 'unknown' ? 'Pesan diterima atau sedang diperiksa oleh hub. Jangan kirim ulang.' : null),
        ]);
    }

    public function baseUrl(): string
    {
        return rtrim(trim($this->urlOverride ?? ($this->storedIntegration()?->url ? $this->storedIntegration()->url.'/api/v2' : (string) config('wag.engine_url'))), '/');
    }

    protected function token(): string
    {
        return trim($this->tokenOverride ?? $this->storedIntegration()?->token ?? (string) config('wag.engine_token'));
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
            ->acceptJson();

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
            throw new RuntimeException((string) ($payload['message'] ?? $payload['error']['message'] ?? 'Engine WhatsApp mengembalikan error.'), $response->status());
        }

        return $this->isV2() ? ($payload['data'] ?? $payload) : $payload;
    }
}
