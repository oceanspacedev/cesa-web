<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWagEvent;
use App\Models\WagEventInbox;
use App\Models\WagIntegration;
use App\Services\WhatsApp\WagHubIntegration;
use Cesa\Rekrutmen\Services\WhatsAppEngineClient;
use Illuminate\Console\Command;
use Throwable;

class ReconcileWagHub extends Command
{
    protected $signature = 'wag:reconcile';

    protected $description = 'Refresh authoritative WhatsApp projections and recover durable events.';

    public function handle(WhatsAppEngineClient $client, WagHubIntegration $integration): int
    {
        if (! $client->isV2() || ! $client->isConfigured()) {
            return self::SUCCESS;
        }
        try {
            if (! WagIntegration::current()->verified_at) {
                $integration->configure(preg_replace('#/api/v2$#', '', $client->baseUrl()), (string) config('wag.token'));
            }
            $integration->refresh($client);
            $integration->replay($client);
        } catch (Throwable $exception) {
            report($exception);
            $this->warn('WAG Hub unavailable. Last observed session states are retained.');
        }
        WagEventInbox::query()->whereNull('processed_at')->orderBy('id')->limit(500)->each(fn (WagEventInbox $event) => ProcessWagEvent::dispatch($event->id));

        return self::SUCCESS;
    }
}
