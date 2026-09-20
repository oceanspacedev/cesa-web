<?php

namespace App\Services\WhatsApp;

use App\Models\WagEventInbox;
use App\Models\WagIntegration;
use Cesa\Rekrutmen\Enums\WhatsAppAccountStatus;
use Cesa\Rekrutmen\Models\WhatsAppAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class WagHubIntegration
{
    public function configure(string $url, ?string $token): array
    {
        WagIntegration::current();
        DB::transaction(function (): void {
            $stored = WagIntegration::query()->lockForUpdate()->findOrFail(1);
            if (! $stored->webhook_secret) {
                $stored->update(['webhook_secret' => Str::random(64)]);
            }
        });
        $snapshot = DB::transaction(function () use ($url, $token): array {
            $stored = WagIntegration::query()->lockForUpdate()->findOrFail(1);
            $url = rtrim($url, '/');
            $token = filled($token) ? $token : $stored->token;
            $client = new WagHubEngineClient($url.'/api/v2', $token);
            $snapshot = $client->integration();
            if ($stored->installation_id && $stored->installation_id !== (string) $snapshot['id']) {
                throw new RuntimeException('Hub installation differs from the linked account pool. Migrate existing accounts before changing installations.', 409);
            }
            $client->registerWebhook(route('wag.webhook'), $stored->webhook_secret);
            $stored->fill(['installation_id' => $snapshot['id'], 'url' => $url, 'token' => $token, 'snapshot' => $snapshot, 'verified_at' => now()])->save();

            return $snapshot;
        });
        $this->refresh(new WagHubEngineClient);

        return $snapshot;
    }

    public function refresh(WagHubEngineClient $client): array
    {
        $snapshot = $client->integration();
        $integration = WagIntegration::current();
        if ($integration->installation_id && $integration->installation_id !== (string) $snapshot['id']) {
            throw new RuntimeException('Hub installation mismatch.', 409);
        }
        $integration->update(['installation_id' => $snapshot['id'], 'snapshot' => $snapshot, 'verified_at' => now()]);
        $candidates = WhatsAppAccount::query()->where('hub_installation_id', (string) $snapshot['id'])->whereNotNull('hub_session_id')->get()->keyBy('hub_session_id');
        $sessions = $client->sessions();
        foreach ($sessions as $session) {
            $this->project($session, (string) $snapshot['id']);
        }
        $ids = array_column($sessions, 'id');
        foreach ($candidates as $id => $candidate) {
            if (in_array($id, $ids, true)) {
                continue;
            }
            try {
                $this->project($client->session($id), (string) $snapshot['id']);
            } catch (RuntimeException $exception) {
                if ($exception->getCode() !== 404) {
                    throw $exception;
                }
                DB::transaction(function () use ($candidate): void {
                    WagIntegration::query()->lockForUpdate()->findOrFail(1);
                    $current = WhatsAppAccount::query()->lockForUpdate()->find($candidate->id);
                    if ($current && (int) $current->hub_revision === (int) $candidate->hub_revision && $current->hub_session_id === $candidate->hub_session_id) {
                        $this->removeProjection($current);
                    }
                });
            }
        }

        return $snapshot;
    }

    public function project(array $snapshot, ?string $installationId = null, ?WhatsAppAccount $existing = null): ?WhatsAppAccount
    {
        if (! isset($snapshot['id'], $snapshot['status'], $snapshot['revision'])) {
            return null;
        }
        $installationId ??= WagIntegration::current()->installation_id;

        return DB::transaction(function () use ($snapshot, $installationId, $existing): ?WhatsAppAccount {
            $integration = WagIntegration::query()->lockForUpdate()->find(1);
            $account = $existing ? WhatsAppAccount::query()->lockForUpdate()->find($existing->id) : WhatsAppAccount::withTrashed()->where('hub_session_id', $snapshot['id'])->lockForUpdate()->first();
            if ($account?->trashed()) {
                return null;
            }
            if (! $account && filled($snapshot['external_reference'] ?? $snapshot['external_id'] ?? null) && preg_match('/^rekrutmen-([0-9]+)$/', ($snapshot['external_reference'] ?? $snapshot['external_id'] ?? null), $match)) {
                $account = WhatsAppAccount::query()->whereNull('hub_session_id')->lockForUpdate()->find($match[1]);
            }
            $account ??= new WhatsAppAccount(['name' => $snapshot['name'] ?? 'WhatsApp', 'is_active' => true, 'is_default' => false]);
            if ($account->hub_session_id && $account->hub_session_id !== $snapshot['id']) {
                throw new RuntimeException('Account already references another hub session.', 409);
            }
            if ((int) $snapshot['revision'] < (int) $account->hub_revision) {
                return $account;
            }
            $previousObservation = $account->hub_snapshot['observed_at'] ?? null;
            if ((int) $snapshot['revision'] === (int) $account->hub_revision && $previousObservation
                && (! isset($snapshot['observed_at']) || Carbon::parse($snapshot['observed_at'])->lt(Carbon::parse($previousObservation)))
                && ! ($snapshot['deleted'] ?? false)) {
                return $account;
            }
            if ($snapshot['deleted'] ?? false) {
                $account->forceFill(['hub_session_id' => $snapshot['id'], 'hub_installation_id' => $installationId, 'hub_revision' => $snapshot['revision'], 'hub_snapshot' => $snapshot, 'hub_status' => 'STOPPED', 'desired_state' => 'STOPPED'])->save();
                $this->removeProjection($account);

                return null;
            }
            $status = match ($snapshot['status']) {
                'WORKING'      => WhatsAppAccountStatus::Connected,
                'STARTING'     => WhatsAppAccountStatus::Connecting,
                'SCAN_QR_CODE' => ($snapshot['auth_method'] ?? 'qr') === 'pairing' ? WhatsAppAccountStatus::Pairing : WhatsAppAccountStatus::Qr,
                'STOPPED'      => WhatsAppAccountStatus::Disconnected,
                default        => WhatsAppAccountStatus::Unknown,
            };
            $pending = $snapshot['pending_operation'] ?? null;
            $account->forceFill([
                'name'                 => $snapshot['name'] ?? $account->name,
                'hub_session_id'       => $snapshot['id'], 'hub_installation_id' => $installationId,
                'hub_revision'         => $snapshot['revision'], 'hub_status' => $snapshot['status'],
                'hub_observed_at'      => $snapshot['observed_at'] ?? null, 'hub_stale' => $snapshot['stale'] ?? true,
                'engine_available'     => $snapshot['engine_available'] ?? false, 'hub_snapshot' => $snapshot,
                'desired_state'        => $snapshot['desired_state'] ?? null, 'phone_number' => $snapshot['phone_number'] ?? null,
                'pending_operation_id' => $pending['id'] ?? null, 'pending_operation_action' => $pending['action'] ?? null,
                'status'               => $status, 'last_checked_at' => now(), 'last_error' => is_array($snapshot['last_error'] ?? null) ? json_encode($snapshot['last_error']) : ($snapshot['last_error'] ?? null),
            ]);
            if ($status === WhatsAppAccountStatus::Connected && ! $account->hub_stale && $account->engine_available
                && $account->desired_state === 'RUNNING' && ! in_array($pending['action'] ?? null, ['stop', 'logout', 'delete'], true)
                && ! $integration?->default_selection_required && ! WhatsAppAccount::query()->where('is_default', true)->exists()) {
                $account->is_default = true;
            }
            $account->save();

            return $account;
        });
    }

    public function removeProjection(WhatsAppAccount $account): void
    {
        if ($account->is_default) {
            WagIntegration::current()->update(['default_selection_required' => true]);
        }
        $account->forceFill(['is_default' => false, 'is_active' => false])->save();
        $account->delete();
    }

    public function ingest(array $event): WagEventInbox
    {
        $integration = WagIntegration::current();
        if ((string) ($event['installation_id'] ?? '') !== (string) $integration->installation_id) {
            throw new RuntimeException('Webhook installation mismatch.', 403);
        }

        return WagEventInbox::query()->firstOrCreate(['event_id' => $event['id']], [
            'event' => $event['event'], 'installation_id' => $event['installation_id'], 'payload' => $event,
        ]);
    }

    public function replay(WagHubEngineClient $client): void
    {
        $integration = WagIntegration::current();
        for ($page = 0; $page < 10; $page++) {
            try {
                $events = $client->events($integration->event_cursor);
            } catch (RuntimeException $exception) {
                if ($exception->getCode() !== 410 || $integration->event_cursor === null) {
                    throw $exception;
                }
                Log::warning('WAG event cursor expired; recovering retained events after the authoritative session refresh.', ['installation_id' => $integration->installation_id]);
                $integration->update(['event_cursor' => null]);
                $events = $client->events(null);
            }
            DB::transaction(function () use ($events, $integration): void {
                foreach ($events['data'] ?? [] as $event) {
                    $this->ingest($event);
                }
                $integration->update(['event_cursor' => $events['meta']['next_cursor'] ?? $integration->event_cursor]);
            });
            if (! ($events['meta']['has_more'] ?? (count($events['data'] ?? []) >= 100))) {
                break;
            }
        }
    }
}
