<?php

namespace Cesa\Rekrutmen\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

class WhatsAppThrottleService
{
    public function getDispatchDelaySeconds(?int $accountId = null): int
    {
        return $this->reserve($accountId, true);
    }

    public function acquireSendSlot(int $accountId): int
    {
        return $this->reserve($accountId, false);
    }

    protected function reserve(?int $accountId, bool $reserveFuture): int
    {
        $config = config('rekrutmen.notifications.whatsapp.throttle', []);
        $minimum = max(0, (int) ($config['min_interval_seconds'] ?? 2));
        $maximum = max($minimum, (int) ($config['max_interval_seconds'] ?? $minimum));

        if (! ($config['enabled'] ?? true) || $minimum === 0) {
            return 0;
        }

        $key = 'rekrutmen:whatsapp:throttle:'.($config['key'] ?? 'global').':'.($accountId ?? 'global');

        try {
            return (int) Cache::lock($key.':lock', 10)->block(1, function () use ($key, $minimum, $maximum, $reserveFuture): int {
                $current = now()->timestamp;
                $next = (int) Cache::get($key, 0);
                $delay = max(0, $next - $current);

                if ($delay === 0 || $reserveFuture) {
                    Cache::put($key, max($next, $current) + random_int($minimum, $maximum), 21600);
                }

                return $delay;
            });
        } catch (Throwable) {
            return max(3, $minimum);
        }
    }
}
