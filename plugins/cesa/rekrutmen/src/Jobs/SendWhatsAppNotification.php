<?php

namespace Cesa\Rekrutmen\Jobs;

use Cesa\Rekrutmen\Models\NotificationDelivery;
use Cesa\Rekrutmen\Services\NotificationDeliveryService;
use Cesa\Rekrutmen\Services\WhatsAppGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWhatsAppNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        protected int $accountId,
        protected string $phone,
        protected string $message,
    ) {
        $this->onQueue(config('rekrutmen.notifications.whatsapp.queue', 'whatsapp'));
        $this->onConnection(app(NotificationDeliveryService::class)->queueConnection());
        $this->timeout = app(NotificationDeliveryService::class)->jobTimeout();
    }

    public function handle(WhatsAppGateway $gateway): void
    {
        $delivery = NotificationDelivery::query()->firstOrCreate([
            'request_key' => hash('sha256', 'legacy-approval:'.$this->accountId.':'.$this->phone.':'.$this->message),
        ], [
            'channel'             => 'whatsapp',
            'recipient'           => $this->phone,
            'whatsapp_account_id' => $this->accountId,
            'payload'             => ['text' => $this->message],
            'status'              => NotificationDelivery::STATUS_PENDING,
            'available_at'        => now(),
        ]);

        app(NotificationDeliveryService::class)->execute($delivery);
    }

    public function backoff(): array
    {
        return config('rekrutmen.notifications.whatsapp.backoff', [10, 30, 60]);
    }

    public function tags(): array
    {
        return ['rekrutmen', 'whatsapp', 'request-man-power-approval'];
    }
}
