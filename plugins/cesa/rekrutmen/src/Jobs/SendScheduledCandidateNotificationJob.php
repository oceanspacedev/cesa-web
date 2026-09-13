<?php

namespace Cesa\Rekrutmen\Jobs;

use Cesa\Rekrutmen\Models\ScheduledNotification;
use Cesa\Rekrutmen\Services\NotificationDeliveryService;
use Cesa\Rekrutmen\Services\ScheduledNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendScheduledCandidateNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public function __construct(public int $scheduledNotificationId)
    {
        $service = app(NotificationDeliveryService::class);
        $this->onConnection($service->queueConnection('email'));
        $this->timeout = $service->jobTimeout('email');
        $this->onQueue(config('rekrutmen.notifications.queue', 'notifications'));
    }

    public function handle(): void
    {
        $notification = ScheduledNotification::query()->find($this->scheduledNotificationId);
        if (! $notification) {
            return;
        }

        if ($notification->status === ScheduledNotification::STATUS_PENDING && $notification->scheduled_at->isFuture()) {
            $this->release(max(1, (int) ceil(now()->diffInSeconds($notification->scheduled_at, false))));

            return;
        }

        app(ScheduledNotificationService::class)->executeScheduled($notification);
    }

    public function tags(): array
    {
        return ['rekrutmen', 'scheduled-notification', 'notification:'.$this->scheduledNotificationId];
    }
}
