<?php

namespace Cesa\Rekrutmen\Jobs;

use Cesa\Rekrutmen\Services\AiScreeningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ScreenCandidateCvJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 90;

    public bool $failOnTimeout = true;

    public function __construct(public int $applicationId, public string $token, public bool $force = false) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 45, 120];
    }

    /** @return list<WithoutOverlapping|RateLimited> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('ai-screening:'.$this->applicationId))->shared()->releaseAfter(30)->expireAfter(80),
            new RateLimited('rekrutmen-ai'),
        ];
    }

    public function handle(AiScreeningService $service): void
    {
        $service->process($this->applicationId, $this->token, $this->force);
    }

    public function failed(?Throwable $exception): void
    {
        app(AiScreeningService::class)->fail($this->applicationId, $this->token);
    }
}
