<?php

namespace App\Jobs;

use App\Models\WagEventInbox;
use App\Models\WagMessageRequest;
use App\Services\WhatsApp\WagHubIntegration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessWagEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $eventId) {}

    public function backoff(): array
    {
        return [10, 30, 60, 300];
    }

    public function handle(WagHubIntegration $integration): void
    {
        try {
            DB::transaction(function () use ($integration): void {
                $event = WagEventInbox::query()->lockForUpdate()->findOrFail($this->eventId);
                if ($event->processed_at) {
                    return;
                }
                $payload = $event->payload;
                if ($event->event === 'session.status') {
                    $snapshot = $payload['data']['session'] ?? $payload['data'];
                    $snapshot['id'] ??= $payload['session_id'];
                    $snapshot['revision'] ??= $payload['revision'];
                    $integration->project($snapshot, $event->installation_id);
                } elseif ($event->event === 'message.ack') {
                    $messageId = $payload['data']['message_id'] ?? $payload['data']['id'] ?? null;
                    if ($messageId) {
                        WagMessageRequest::query()->where('hub_message_id', $messageId)->lockForUpdate()->each(function (WagMessageRequest $message) use ($payload): void {
                            $current = $message->result ?? [];
                            $ranks = ['accepted' => 0, 'delivered' => 1, 'read' => 2, 'played' => 3];
                            $receipt = $payload['data']['receipt_status'] ?? $payload['data']['ack'] ?? null;
                            $previous = $current['receipt_status'] ?? $current['acknowledgement']['receipt_status'] ?? $current['acknowledgement']['ack'] ?? null;
                            if (isset($ranks[$receipt]) && $ranks[$receipt] >= ($ranks[$previous] ?? -1)) {
                                $message->update(['result' => array_merge($current, [
                                    'transport_status' => 'accepted', 'status' => 'sent', 'ok' => true,
                                    'receipt_status'   => $receipt === 'accepted' ? $previous : $receipt,
                                    'acknowledgement'  => $payload['data'],
                                ])]);
                            }
                        });
                    }
                }
                event('wag.'.$event->event, [$payload]);
                $event->update(['processed_at' => now(), 'last_error' => null]);
            });
        } catch (Throwable $exception) {
            WagEventInbox::query()->whereKey($this->eventId)->update(['last_error' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
