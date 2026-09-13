<?php

namespace Cesa\Rekrutmen\Jobs;

use Cesa\Rekrutmen\Models\NotificationDelivery;
use Cesa\Rekrutmen\Services\NotificationDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendNotificationDeliveryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public int $deliveryId, public string $channel = 'whatsapp')
    {
        $service = app(NotificationDeliveryService::class);
        $this->onConnection($service->queueConnection($channel));
        $this->timeout = $service->jobTimeout($channel);
    }

    public function handle(NotificationDeliveryService $service): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        if ($delivery->status === NotificationDelivery::STATUS_PENDING && $delivery->available_at->isFuture()) {
            $this->release(max(1, (int) ceil(now()->diffInSeconds($delivery->available_at, false))));

            return;
        }

        $service->execute($delivery);
    }

    public function tags(): array
    {
        return ['rekrutmen', 'notification-delivery', 'delivery:'.$this->deliveryId];
    }
}
