<?php

namespace Cesa\Rekrutmen\Jobs;

use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Services\AiScreeningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Webkul\Security\Models\User;

class QueueCandidateCvScreeningBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 75;

    /** @param list<int> $applicationIds */
    public function __construct(
        public array $applicationIds,
        public int $actorId,
        public bool $force = false,
        public string $requestedAt = '',
    ) {
        if ($this->requestedAt === '') {
            $this->requestedAt = now()->startOfSecond()->toDateTimeString();
        }
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 45, 120];
    }

    public function handle(AiScreeningService $service): void
    {
        $actor = User::query()->find($this->actorId);
        if (! $actor || ! $actor->is_active || ! $actor->can('update_rekrutmen_job::application')) {
            return;
        }

        $requestedAt = Carbon::parse($this->requestedAt)->startOfSecond();
        (new JobApplication)->getConnection()->transaction(function () use ($actor, $requestedAt, $service): void {
            $applications = JobApplication::query()->with(['creator', 'jobPosting'])
                ->whereIn('id', $this->applicationIds)->orderBy('id')->lockForUpdate()->get();
            foreach ($applications as $application) {
                if (! Gate::forUser($actor)->allows('update', $application)) {
                    continue;
                }

                if ($application->ai_screening_requested_at?->greaterThanOrEqualTo($requestedAt)
                    && in_array($application->ai_screening_status, ['queued', 'processing', 'completed', 'needs_review', 'failed'], true)) {
                    continue;
                }

                $service->queue($application, force: $this->force);
            }
        });
    }
}
