<?php

namespace Cesa\Rekrutmen\Services;

use Cesa\Rekrutmen\Enums\WhatsAppAccountStatus;
use Cesa\Rekrutmen\Models\WhatsAppAccount;
use Cesa\Rekrutmen\Models\WhatsAppSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class WhatsAppGateway
{
    public function __construct(
        protected WhatsAppEngineClient $engine,
        protected WhatsAppEngineProcess $process,
    ) {}

    /**
     * @param  array{purpose?: string, mode?: string, client_reference?: string, idempotency_key?: string}  $options
     * @return array{success: bool, message: string, phone?: string, data?: mixed, account_id?: int|null, session_id?: string}
     */
    public function sendText(?WhatsAppAccount $account, string $phone, string $message, array $options = []): array
    {
        if (! $this->isEnabled()) {
            return $this->failedSend('Pengiriman WhatsApp rekrutmen sedang nonaktif.');
        }

        $account = $account?->fresh();
        if (! $account || ! $account->is_active) {
            return $this->failedSend('Akun WhatsApp yang dipilih sudah dihapus atau tidak aktif.');
        }

        $phone = $this->formatPhone($phone);
        if (! $phone || trim($message) === '') {
            return $this->failedSend('Nomor tujuan atau pesan WhatsApp tidak valid.');
        }

        if (! $this->ensureEngine()) {
            return $this->failedSend('Engine WhatsApp belum tersedia. Pengiriman menunggu akun yang dipilih.', true);
        }

        $key = (string) ($options['idempotency_key'] ?? Str::uuid());
        try {
            $payload = $this->engine->sendText($account->sessionId(), $phone, $message, $key);
        } catch (Throwable $e) {
            $payload = $this->messageResult($account, $key) ?? [
                'status'    => 'unknown',
                'retryable' => false,
                'message'   => 'Hasil pengiriman belum pasti. Perlu diperiksa sebelum mengirim ulang.',
            ];

            Log::warning('Recruitment WhatsApp send requires reconciliation.', [
                'account_id'  => $account->id,
                'request_key' => $key,
                'error'       => $e->getMessage(),
            ]);
        }

        $status = (string) ($payload['status'] ?? 'unknown');
        $success = $status === 'sent';
        if ($success) {
            $account->markConnected($account->phone_number);
        } else {
            $account->forceFill([
                'last_error'      => $payload['message'] ?? 'Hasil pengiriman belum pasti.',
                'last_checked_at' => now(),
            ])->save();
        }

        return [
            'success'         => $success,
            'status'          => $status,
            'retryable'       => $status === 'failed' && (bool) ($payload['retryable'] ?? false),
            'message'         => $success ? 'Pesan WhatsApp berhasil dikirim ke '.$phone : ($payload['message'] ?? 'Pengiriman perlu diperiksa.'),
            'phone'           => $phone,
            'data'            => $payload,
            'account_id'      => $account->id,
            'session_id'      => $account->sessionId(),
            'idempotency_key' => $key,
        ];
    }

    /** @return array<string, mixed>|null */
    public function messageResult(WhatsAppAccount $account, string $key): ?array
    {
        try {
            return $this->engine->message($account->sessionId(), $key);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{success: bool, status: string, retryable: bool, message: string} */
    protected function failedSend(string $message, bool $retryable = false): array
    {
        return ['success' => false, 'status' => 'failed', 'retryable' => $retryable, 'message' => $message];
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public function connect(WhatsAppAccount $account, string $mode = 'qr', ?string $phone = null): array
    {
        if (! $this->ensureEngine()) {
            return [
                'success' => false,
                'message' => $this->engine->unavailableMessage(),
            ];
        }

        try {
            $session = $this->engine->startSession($account->sessionId(), $mode, $mode === 'pairing' ? $phone : null);
            $account->forceFill(['is_active' => true])->save();

            $this->syncAccount($account, $session);

            return [
                'success' => true,
                'message' => 'Scan QR WhatsApp atau masukkan kode pairing di HP.',
                'data'    => $this->sessionPayload($account, $session, true),
            ];
        } catch (Throwable $e) {
            $account->markDisconnected($e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function session(WhatsAppAccount $account, ?bool $engineReady = null): array
    {
        $engineReady ??= $this->engine->isReady();
        if (! $engineReady) {
            return $this->sessionPayload($account, [
                'status' => 'unknown',
                'error'  => 'Engine WhatsApp belum tersedia.',
            ], false);
        }

        try {
            $session = $this->engine->session($account->sessionId());
            $this->syncAccount($account, $session);

            return $this->sessionPayload($account, $session, true);
        } catch (Throwable $e) {
            return $this->sessionPayload($account, [
                'status' => 'unknown',
                'error'  => $e->getMessage(),
            ], false);
        }
    }

    /** @return array<string, mixed> */
    public function disconnect(WhatsAppAccount $account): array
    {
        $account->forceFill(['is_active' => false])->save();
        $account->markDisconnected('Nomor diputuskan dari CESA.');

        try {
            if (! $this->engine->isReady()) {
                return $this->failedSend('Pengiriman akun dinonaktifkan. Engine belum tersedia untuk menghapus sesi; coba putuskan lagi setelah engine pulih.');
            }

            $result = $this->engine->logout($account->sessionId());

            return [
                'success' => true,
                'message' => $result['message'] ?? 'Nomor WhatsApp diputuskan.',
            ];
        } catch (Throwable $e) {
            return $this->failedSend('Pengiriman akun dinonaktifkan. Pemutusan sesi belum terkonfirmasi: '.$e->getMessage());
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function testAccount(WhatsAppAccount $account, ?string $recipient = null): array
    {
        $phone = $this->formatPhone($recipient ?: (string) $account->phone_number);

        if (! $phone) {
            return [
                'success' => false,
                'message' => 'Nomor tujuan tes tidak valid.',
            ];
        }

        $result = $this->sendText($account, $phone, 'CESA Rekrutmen: tes koneksi WhatsApp berhasil.');

        return [
            'success' => $result['success'],
            'message' => $result['success']
                ? 'Koneksi berhasil. Pesan tes terkirim ke '.$phone.'.'
                : ($result['message'] ?? 'Koneksi WhatsApp gagal.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveSettings(array $payload): WhatsAppSetting
    {
        $setting = WhatsAppSetting::query()->first() ?? new WhatsAppSetting;
        $setting->fill($payload);
        $setting->save();

        return $setting->fresh() ?? $setting;
    }

    public function isEnabled(): bool
    {
        $stored = WhatsAppSetting::query()->first();

        if ($stored) {
            return (bool) $stored->enabled;
        }

        return (bool) config('rekrutmen.notifications.whatsapp.enabled', true);
    }

    public function engineReady(): bool
    {
        return $this->engine->isReady();
    }

    public function formatPhone(?string $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        $trimmed = trim($phone);

        if ($trimmed === '') {
            return null;
        }

        if (! preg_match('/^\+?[0-9\s().-]+$/', $trimmed)) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $trimmed);
        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return preg_match('/^[1-9][0-9]{7,14}$/', $digits) ? $digits : null;
    }

    protected function ensureEngine(): bool
    {
        try {
            return $this->process->ensureRunning();
        } catch (Throwable $e) {
            Log::warning('Failed to auto-start rekrutmen WhatsApp engine.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $session
     */
    protected function syncAccount(WhatsAppAccount $account, array $session): void
    {
        $status = $this->mapStatus($session['status'] ?? null);
        $phone = $this->formatPhone(isset($session['phone']) ? (string) $session['phone'] : null);
        $error = isset($session['error']) ? (string) $session['error'] : null;

        $account->forceFill([
            'status'          => $status,
            'phone_number'    => $phone ?: $account->phone_number,
            'last_checked_at' => now(),
            'last_error'      => $status === WhatsAppAccountStatus::Connected ? null : $error,
        ])->save();
    }

    protected function mapStatus(mixed $status): WhatsAppAccountStatus
    {
        return match ($status) {
            'qr'          => WhatsAppAccountStatus::Qr,
            'pairing'     => WhatsAppAccountStatus::Pairing,
            'connecting'  => WhatsAppAccountStatus::Connecting,
            'connected'   => WhatsAppAccountStatus::Connected,
            'disconnected'=> WhatsAppAccountStatus::Disconnected,
            default       => WhatsAppAccountStatus::Unknown,
        };
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    protected function sessionPayload(WhatsAppAccount $account, array $session, bool $engineReady): array
    {
        $status = $this->mapStatus($session['status'] ?? null)->value;

        return array_merge($account->toApiArray(), [
            'status'         => $status,
            'qr'             => $status === 'qr' ? ($session['qr'] ?? null) : null,
            'pairing_code'   => $status === 'pairing' ? ($session['pairing_code'] ?? null) : null,
            'engine_ready'   => $engineReady,
            'delivery_ready' => $engineReady && $account->is_active && $status === 'connected' && $this->isEnabled(),
            'engine_error'   => $session['error'] ?? $account->last_error,
        ]);
    }
}
