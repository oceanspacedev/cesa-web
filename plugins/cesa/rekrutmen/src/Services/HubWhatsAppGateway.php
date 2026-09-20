<?php

namespace Cesa\Rekrutmen\Services;

use App\Models\WagIntegration;
use App\Services\WhatsApp\WagHubIntegration;
use Cesa\Rekrutmen\Models\WhatsAppAccount;
use RuntimeException;
use Throwable;

class HubWhatsAppGateway
{
    public function __construct(protected WhatsAppEngineClient $client, protected WagHubIntegration $integration) {}

    public function connect(WhatsAppAccount $account, string $mode, ?string $phone): array
    {
        try {
            if (! $account->hub_session_id) {
                $response = $this->client->createSession($account->connection_request_key ?: 'cesa-account-'.$account->id, $account->name, $mode, $phone, 'rekrutmen-'.$account->id);
            } elseif ($mode === 'qr' && $account->desired_state === 'RUNNING' && in_array($account->hub_status, ['STARTING', 'SCAN_QR_CODE', 'WORKING'], true)) {
                return ['success' => true, 'message' => 'Melanjutkan koneksi yang sudah dibuat.', 'data' => $this->session($account)];
            } else {
                $response = $this->client->startSession($account->hub_session_id, $mode, $phone);
            }
            $this->observe($account, $response);

            return ['success' => true, 'message' => 'Tautkan WhatsApp dari HP Anda.', 'data' => $this->session($account->fresh())];
        } catch (Throwable $exception) {
            return ['success' => false, 'message' => $exception->getMessage(), 'data' => $this->payload($account, false)];
        }
    }

    public function session(WhatsAppAccount $account): array
    {
        if (! $account->hub_session_id) {
            return $this->payload($account, false);
        }
        try {
            if ($account->pending_operation_id) {
                $operation = $this->client->operation($account->pending_operation_id);
                if ($account->pending_operation_action === 'delete' && ($operation['status'] ?? '') === 'completed') {
                    $this->integration->removeProjection($account);

                    return ['id' => $account->id, 'deleted' => true];
                }
            }
            $snapshot = $this->client->session($account->hub_session_id);
            $this->integration->project($snapshot, $account->hub_installation_id, $account);
            $account->refresh();
            $payload = $this->payload($account, true);
            if ($account->hub_status === 'SCAN_QR_CODE' && ! $payload['stale']) {
                try {
                    $challenge = $this->client->qr($account->hub_session_id);
                    if (! isset($challenge['expires_at']) || now()->lt($challenge['expires_at'])) {
                        $payload['qr'] = $challenge['qr'] ?? null;
                        $payload['pairing_code'] = $challenge['pairing_code'] ?? $challenge['code'] ?? null;
                        $payload['challenge_expires_at'] = $challenge['expires_at'] ?? null;
                    }
                } catch (Throwable) {
                    // A challenge can expire between the authoritative snapshot and this read.
                }
            }

            return $payload;
        } catch (Throwable $exception) {
            return array_merge($this->payload($account, false), ['engine_error' => 'Hub tidak dapat dijangkau. Status terakhir dipertahankan.']);
        }
    }

    public function lifecycle(WhatsAppAccount $account, string $action): array
    {
        try {
            if (! $account->hub_session_id) {
                if ($action === 'delete') {
                    $this->integration->removeProjection($account);

                    return ['success' => true, 'message' => 'Akun lokal dihapus.', 'data' => ['id' => $account->id, 'deleted' => true]];
                }
                throw new RuntimeException('Tautkan akun ini ke hub terlebih dahulu.');
            }
            $response = $this->client->lifecycle($account->hub_session_id, $action);
            $this->observe($account, $response);
            if (in_array($action, ['logout', 'delete'], true) && $account->is_default) {
                WagIntegration::current()->update(['default_selection_required' => true]);
                $account->forceFill(['is_default' => false])->save();
            }

            return ['success' => true, 'message' => 'Permintaan diterima. Menunggu konfirmasi hub.', 'data' => $this->payload($account->fresh(), true)];
        } catch (Throwable $exception) {
            return ['success' => false, 'message' => 'Perubahan belum terkonfirmasi: '.$exception->getMessage(), 'data' => $this->payload($account, false)];
        }
    }

    public function rename(WhatsAppAccount $account, string $name): void
    {
        if ($account->hub_session_id) {
            $this->integration->project($this->client->rename($account->hub_session_id, $name), $account->hub_installation_id, $account);
        } else {
            $account->update(['name' => $name]);
        }
    }

    protected function observe(WhatsAppAccount $account, array $response): void
    {
        $snapshot = $response['session'] ?? $response;
        $snapshot['pending_operation'] ??= $response['operation'] ?? null;
        $this->integration->project($snapshot, null, $account);
        $account->refresh();
    }

    public function payload(WhatsAppAccount $account, bool $reachable): array
    {
        $payload = $account->toApiArray();
        $stale = ! $reachable || ($payload['stale'] ?? true);
        $ready = $reachable && $account->engine_available && ! $stale;

        return array_merge($payload, [
            'stale'          => $stale, 'status' => $stale ? 'unknown' : $payload['status'],
            'engine_ready'   => $reachable, 'engine_error' => $account->last_error,
            'delivery_ready' => $ready && $account->hub_status === 'WORKING' && $account->desired_state === 'RUNNING' && ! $account->pending_operation_id && $account->is_active,
            'qr'             => null, 'pairing_code' => null,
        ]);
    }
}
