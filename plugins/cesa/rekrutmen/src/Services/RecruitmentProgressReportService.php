<?php

namespace Cesa\Rekrutmen\Services;

use Cesa\Rekrutmen\Enums\ActivityEntryResult;
use Cesa\Rekrutmen\Enums\JobApplicationStatus;
use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Cesa\Rekrutmen\Models\JobApplicationHistory;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RequestManPower;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecruitmentProgressReportService
{
    /**
     * @param  array{
     *     date_from?: ?string,
     *     date_to?: ?string,
     *     job_posting_id?: ?int,
     *     stage_id?: ?int,
     *     stage_name?: ?string,
     *     company_id?: ?int,
     *     allowed_posting_ids?: int[]
     * }  $filters
     * @return array{
     *     posting_ids: int[],
     *     postings: Collection<int, JobPosting>,
     *     summary: array<string, int>,
     *     activities: Collection<int, array<string, mixed>>,
     *     timeline: Collection<int, array<string, mixed>>,
     *     positions: Collection<int, array<string, mixed>>,
     *     overview: Collection<int, array<string, mixed>>,
     *     hr_kpis: Collection<int, array<string, mixed>>
     * }
     */
    public function build(array $filters): array
    {
        $snapshotDate = $this->resolveSnapshotDate($filters);
        $postingIds = $this->resolvePostingIds($filters, $snapshotDate);
        $postings = $this->loadPostings($postingIds);
        $statsByPosting = $this->loadApplicationStats($postingIds, $snapshotDate);
        $decisionCounts = $this->loadDecisionCounts($postingIds, $filters);
        $hiredCandidatesByPosting = $this->loadCurrentHiredCandidates($postingIds, $snapshotDate);
        $stageCountsByPosting = $this->loadCurrentStageCounts($postingIds, $snapshotDate);
        $historyEntries = $this->loadHistoryEntries($filters, $postingIds);
        $entriesByGroup = $historyEntries->groupBy('activity_group_id');
        $groupedActivities = $historyEntries
            ->filter(fn (JobApplicationHistory $history): bool => filled($history->activity_group_id))
            ->unique('activity_group_id')
            ->values();

        $groupMetrics = $this->buildGroupMetrics($entriesByGroup);
        $passedCountsByPostingStage = $this->buildPassedCountsByPostingStage($historyEntries);
        $formattedActivities = $this->formatActivities($groupedActivities, $entriesByGroup, $groupMetrics);
        $activityDigest = $this->buildActivityDigest($formattedActivities);
        $positions = $this->buildPositions(
            $postings,
            $statsByPosting,
            $stageCountsByPosting,
            $passedCountsByPostingStage,
            $activityDigest,
            $hiredCandidatesByPosting,
            $snapshotDate,
        );
        $hrKpis = $this->buildHrKpis($positions, $filters);

        return [
            'posting_ids' => $postingIds,
            'postings'    => $postings,
            'summary'     => $this->buildSummary($postingIds, $statsByPosting, $activityDigest, $decisionCounts, $positions, $hrKpis),
            'activities'  => $formattedActivities,
            'timeline'    => $this->buildTimeline($activityDigest),
            'positions'   => $positions,
            'overview'    => $this->buildOverview($positions),
            'hr_kpis'     => $hrKpis,
        ];
    }

    /**
     * @param  array{job_posting_id?: ?int, company_id?: ?int, allowed_posting_ids?: int[]}  $filters
     * @return int[]
     */
    protected function resolvePostingIds(array $filters, Carbon $snapshotDate): array
    {
        return JobPosting::query()
            ->when($filters['job_posting_id'] ?? null, fn (Builder $query, int $jobPostingId) => $query->whereKey($jobPostingId))
            ->when(
                array_key_exists('allowed_posting_ids', $filters),
                fn (Builder $query): Builder => $query->whereKey($filters['allowed_posting_ids'])
            )
            ->where(function (Builder $query) use ($snapshotDate): void {
                $query->whereHas(
                    'requestManPowers',
                    fn (Builder $requestManPowerQuery) => $this->applyActiveRequestScope($requestManPowerQuery, $snapshotDate)
                )->orWhereHas(
                    'requestManPower',
                    fn (Builder $requestManPowerQuery) => $this->applyActiveRequestScope($requestManPowerQuery, $snapshotDate)
                );
            })
            ->when(
                $filters['company_id'] ?? null,
                fn (Builder $query, int $companyId) => $query->where(function (Builder $query) use ($companyId, $snapshotDate): void {
                    $query->whereHas(
                        'requestManPowers',
                        fn (Builder $requestManPowerQuery) => $this->applyActiveRequestScope($requestManPowerQuery, $snapshotDate)
                            ->where('company_id', $companyId)
                    )->orWhereHas(
                        'requestManPower',
                        fn (Builder $requestManPowerQuery) => $this->applyActiveRequestScope($requestManPowerQuery, $snapshotDate)
                            ->where('company_id', $companyId)
                    );
                })
            )
            ->pluck('id')
            ->all();
    }

    /**
     * @param  int[]  $postingIds
     * @return Collection<int, JobPosting>
     */
    protected function loadPostings(array $postingIds): Collection
    {
        if ($postingIds === []) {
            return collect();
        }

        return JobPosting::query()
            ->with(['requestManPower.company', 'requestManPowers.company', 'rekrutmenPipeline.stages'])
            ->whereIn('id', $postingIds)
            ->orderBy('title')
            ->get();
    }

    protected function applyActiveRequestScope(Builder $query, ?Carbon $snapshotDate = null): Builder
    {
        return $query
            ->whereNull((new RequestManPower)->qualifyColumn('deleted_at'))
            ->whereIn('status', [
                RequestManPowerStatus::PENDING->value,
                RequestManPowerStatus::APPROVED->value,
                RequestManPowerStatus::HOLD->value,
            ])
            ->when(
                $snapshotDate,
                fn (Builder $query, Carbon $date): Builder => $query
                    ->whereNotNull((new RequestManPower)->qualifyColumn('tanggal_pengajuan'))
                    ->whereDate((new RequestManPower)->qualifyColumn('tanggal_pengajuan'), '<=', $date->toDateString())
            );
    }

    /**
     * @param  int[]  $postingIds
     * @return Collection<int, object{job_posting_id:int,total:int,in_progress:int,hired:int,rejected:int}>
     */
    protected function loadApplicationStats(array $postingIds, Carbon $snapshotDate): Collection
    {
        if ($postingIds === []) {
            return collect();
        }

        return collect(DB::table('rekrutmen_job_applications')
            ->whereIn('job_posting_id', $postingIds)
            ->whereNull('deleted_at')
            ->whereDate('created_at', '<=', $snapshotDate->toDateString())
            ->selectRaw('
                job_posting_id,
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as hired,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected
            ', [
                JobApplicationStatus::IN_PROGRESS->value,
                JobApplicationStatus::HIRED->value,
                JobApplicationStatus::REJECTED->value,
            ])
            ->groupBy('job_posting_id')
            ->get())
            ->keyBy('job_posting_id');
    }

    /**
     * @param  int[]  $postingIds
     * @param  array{
     *     date_from?: ?string,
     *     date_to?: ?string
     * }  $filters
     * @return array{hired:int,rejected:int}
     */
    protected function loadDecisionCounts(array $postingIds, array $filters): array
    {
        if ($postingIds === []) {
            return [
                'hired'    => 0,
                'rejected' => 0,
            ];
        }

        $baseQuery = JobApplicationHistory::query()
            ->where(function (Builder $query): void {
                $query->whereIn('status', [
                    JobApplicationStatus::HIRED->value,
                    JobApplicationStatus::REJECTED->value,
                ])->orWhere('result', ActivityEntryResult::FAILED->value);
            })
            ->whereIn('job_application_id', function ($query) use ($postingIds): void {
                $query->select('id')
                    ->from('rekrutmen_job_applications')
                    ->whereIn('job_posting_id', $postingIds)
                    ->whereNull('deleted_at');
            });

        $this->applyDecisionDateFilters($baseQuery, $filters);

        return [
            'hired' => (clone $baseQuery)
                ->where('status', JobApplicationStatus::HIRED->value)
                ->whereIn('job_application_id', function ($query): void {
                    $query->select('id')
                        ->from('rekrutmen_job_applications')
                        ->where('status', JobApplicationStatus::HIRED->value)
                        ->whereNull('deleted_at');
                })
                ->distinct()
                ->count('job_application_id'),
            'rejected' => (clone $baseQuery)
                ->where(function (Builder $query): void {
                    $query->where('status', JobApplicationStatus::REJECTED->value)
                        ->orWhere('result', ActivityEntryResult::FAILED->value);
                })
                ->distinct()
                ->count('job_application_id'),
        ];
    }

    /**
     * @param  int[]  $postingIds
     * @return Collection<int, Collection<int, array{id:int,job_posting_id:int,full_name:string,hired_at:?string,hired_at_label:string,performed_by_id:?int,performed_by:?string,notes:?string}>>
     */
    protected function loadCurrentHiredCandidates(array $postingIds, Carbon $snapshotDate): Collection
    {
        if ($postingIds === []) {
            return collect();
        }

        $query = JobApplicationHistory::query()
            ->where('status', JobApplicationStatus::HIRED->value)
            ->whereIn('job_application_id', function ($query) use ($postingIds): void {
                $query->select('id')
                    ->from('rekrutmen_job_applications')
                    ->whereIn('job_posting_id', $postingIds)
                    ->where('status', JobApplicationStatus::HIRED->value)
                    ->whereNull('deleted_at');
            })
            ->with(['jobApplication', 'performer'])
            ->orderByDesc('activity_date')
            ->orderByDesc('created_at');

        $this->applyHistorySnapshotFilter($query, $snapshotDate);

        return $query
            ->get()
            ->unique('job_application_id')
            ->filter(fn (JobApplicationHistory $history): bool => filled($history->jobApplication?->job_posting_id))
            ->map(function (JobApplicationHistory $history): array {
                $eventDate = $this->historyEventDate($history);

                return [
                    'id'              => (int) $history->job_application_id,
                    'job_posting_id'  => (int) $history->jobApplication->job_posting_id,
                    'full_name'       => $history->jobApplication->full_name ?? '-',
                    'hired_at'        => $eventDate?->toDateString(),
                    'hired_at_label'  => $this->formatDate($eventDate),
                    'performed_by_id' => is_numeric($history->performed_by)
                        ? (int) $history->performed_by
                        : null,
                    'performed_by'   => $history->performer?->name,
                    'notes'          => $history->notes,
                ];
            })
            ->groupBy('job_posting_id');
    }

    /**
     * @param  int[]  $postingIds
     * @return array<int, array<int, int>>
     */
    protected function loadCurrentStageCounts(array $postingIds, Carbon $snapshotDate): array
    {
        if ($postingIds === []) {
            return [];
        }

        return DB::table('rekrutmen_job_applications')
            ->whereIn('job_posting_id', $postingIds)
            ->whereNull('deleted_at')
            ->whereDate('created_at', '<=', $snapshotDate->toDateString())
            ->where('status', JobApplicationStatus::IN_PROGRESS->value)
            ->selectRaw('job_posting_id, current_stage_id, COUNT(*) as aggregate_count')
            ->groupBy('job_posting_id', 'current_stage_id')
            ->get()
            ->reduce(function (array $carry, object $row): array {
                $carry[(int) $row->job_posting_id][(int) $row->current_stage_id] = (int) $row->aggregate_count;

                return $carry;
            }, []);
    }

    /**
     * @param  array{
     *     date_from?: ?string,
     *     date_to?: ?string,
     *     stage_id?: ?int,
     *     stage_name?: ?string
     * }  $filters
     * @param  int[]  $postingIds
     * @return Collection<int, JobApplicationHistory>
     */
    protected function loadHistoryEntries(array $filters, array $postingIds): Collection
    {
        if ($postingIds === []) {
            return collect();
        }

        return JobApplicationHistory::query()
            ->whereNotNull('activity_group_id')
            ->whereIn('job_application_id', function ($query) use ($postingIds): void {
                $query->select('id')
                    ->from('rekrutmen_job_applications')
                    ->whereIn('job_posting_id', $postingIds)
                    ->whereNull('deleted_at');
            })
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $dateFrom) => $query->whereDate('activity_date', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $dateTo) => $query->whereDate('activity_date', '<=', $dateTo))
            ->when($filters['stage_id'] ?? null, fn (Builder $query, int $stageId) => $this->applyStageIdFilter($query, $stageId))
            ->when(
                blank($filters['stage_id'] ?? null) ? ($filters['stage_name'] ?? null) : null,
                fn (Builder $query, string $stageName) => $this->applyStageNameFilter($query, $stageName)
            )
            ->with(['fromStage', 'jobApplication.jobPosting', 'toStage', 'performer'])
            ->orderByDesc('activity_date')
            ->orderByDesc('created_at')
            ->get();
    }

    protected function applyStageIdFilter(Builder $query, int $stageId): Builder
    {
        return $query->where(function (Builder $query) use ($stageId): void {
            $query->where('from_stage_id', $stageId)
                ->orWhere(function (Builder $query) use ($stageId): void {
                    $query->whereNull('from_stage_id')
                        ->where('to_stage_id', $stageId);
                });
        });
    }

    protected function applyStageNameFilter(Builder $query, string $stageName): Builder
    {
        return $query->where(function (Builder $query) use ($stageName): void {
            $query->whereHas(
                'fromStage',
                fn (Builder $stageQuery) => $stageQuery->where('name', $stageName)
            )->orWhere(function (Builder $query) use ($stageName): void {
                $query->whereNull('from_stage_id')
                    ->whereHas(
                        'toStage',
                        fn (Builder $stageQuery) => $stageQuery->where('name', $stageName)
                    );
            });
        });
    }

    /**
     * @param  Collection<int, Collection<int, JobApplicationHistory>>  $entriesByGroup
     * @return array<string, array{total:int,passed:int,failed:int,pending:int,summary:string}>
     */
    protected function buildGroupMetrics(Collection $entriesByGroup): array
    {
        return $entriesByGroup->mapWithKeys(function (Collection $entries, string $groupId): array {
            $total = $entries->count();
            $passed = $entries->where('result', 'passed')->count();
            $failed = $entries->where('result', 'failed')->count();
            $pending = $entries->where('result', 'pending')->count();

            return [
                $groupId => [
                    'total'   => $total,
                    'passed'  => $passed,
                    'failed'  => $failed,
                    'pending' => $pending,
                    'summary' => self::activitySummaryText($total, $passed, $failed, $pending),
                ],
            ];
        })->all();
    }

    /**
     * @param  Builder<JobApplicationHistory>  $query
     * @param  array{
     *     date_from?: ?string,
     *     date_to?: ?string
     * }  $filters
     */
    protected function applyDecisionDateFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['date_from'] ?? null, function (Builder $builder, string $dateFrom): Builder {
                return $builder->where(function (Builder $query) use ($dateFrom): void {
                    $query->whereDate('activity_date', '>=', $dateFrom)
                        ->orWhere(function (Builder $query) use ($dateFrom): void {
                            $query->whereNull('activity_date')
                                ->whereDate('created_at', '>=', $dateFrom);
                        });
                });
            })
            ->when($filters['date_to'] ?? null, function (Builder $builder, string $dateTo): Builder {
                return $builder->where(function (Builder $query) use ($dateTo): void {
                    $query->whereDate('activity_date', '<=', $dateTo)
                        ->orWhere(function (Builder $query) use ($dateTo): void {
                            $query->whereNull('activity_date')
                                ->whereDate('created_at', '<=', $dateTo);
                        });
                });
            });
    }

    /**
     * @param  Builder<JobApplicationHistory>  $query
     */
    protected function applyHistorySnapshotFilter(Builder $query, Carbon $snapshotDate): void
    {
        $query->where(function (Builder $query) use ($snapshotDate): void {
            $query->whereDate('activity_date', '<=', $snapshotDate->toDateString())
                ->orWhere(function (Builder $query) use ($snapshotDate): void {
                    $query->whereNull('activity_date')
                        ->whereDate('created_at', '<=', $snapshotDate->toDateString());
                });
        });
    }

    /**
     * @param  Collection<int, JobApplicationHistory>  $historyEntries
     * @return array<int, array<int, int>>
     */
    protected function buildPassedCountsByPostingStage(Collection $historyEntries): array
    {
        return $historyEntries
            ->filter(fn (JobApplicationHistory $history): bool => $history->result?->value === 'passed'
                && (filled($history->from_stage_id) || filled($history->to_stage_id))
                && filled($history->jobApplication?->job_posting_id))
            ->reduce(function (array $carry, JobApplicationHistory $history): array {
                $postingId = (int) $history->jobApplication->job_posting_id;
                $stageId = (int) ($history->from_stage_id ?? $history->to_stage_id);

                $carry[$postingId][$stageId] = ($carry[$postingId][$stageId] ?? 0) + 1;

                return $carry;
            }, []);
    }

    /**
     * @param  Collection<int, JobApplicationHistory>  $groupedActivities
     * @param  Collection<int, Collection<int, JobApplicationHistory>>  $entriesByGroup
     * @param  array<string, array{total:int,passed:int,failed:int,pending:int,summary:string}>  $groupMetrics
     * @return Collection<int, array<string, mixed>>
     */
    protected function formatActivities(Collection $groupedActivities, Collection $entriesByGroup, array $groupMetrics): Collection
    {
        return $groupedActivities->map(function (JobApplicationHistory $history) use ($entriesByGroup, $groupMetrics): array {
            $groupId = (string) $history->activity_group_id;
            $entries = $entriesByGroup->get($groupId, collect())->values();
            $metrics = $groupMetrics[$groupId] ?? [
                'total'   => 0,
                'passed'  => 0,
                'failed'  => 0,
                'pending' => 0,
                'summary' => self::activitySummaryText(0, 0, 0, 0),
            ];

            return [
                'group_id'         => $groupId,
                'activity_date'    => $history->activity_date,
                'activity_type'    => $history->activityKey(),
                'activity_label'   => $history->activityLabel(),
                'activity_color'   => $history->activityColor(),
                'activity_title'   => $history->activity_title,
                'to_stage'         => $history->activityStage(),
                'performer'        => $history->performer,
                'job_posting'      => $history->jobApplication?->jobPosting,
                'job_posting_id'   => $history->jobApplication?->job_posting_id,
                'total_candidates' => $metrics['total'],
                'passed_count'     => $metrics['passed'],
                'failed_count'     => $metrics['failed'],
                'pending_count'    => $metrics['pending'],
                'summary'          => $metrics['summary'],
                'entries'          => $entries,
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $formattedActivities
     * @return Collection<int, array<string, mixed>>
     */
    protected function buildActivityDigest(Collection $formattedActivities): Collection
    {
        return $formattedActivities
            ->groupBy(fn (array $activity): string => implode('|', [
                $activity['activity_date']?->format('Y-m-d') ?? '',
                (string) ($activity['job_posting_id'] ?? ''),
                (string) ($activity['activity_type'] ?? ''),
                (string) ($activity['to_stage']?->id ?? ''),
            ]))
            ->map(function (Collection $activities): array {
                $representative = $activities->first();
                $entries = $activities
                    ->flatMap(fn (array $activity): Collection => collect($activity['entries'] ?? []))
                    ->values();
                $passed = $this->countEntriesByResult($entries, ActivityEntryResult::PASSED);
                $failed = $this->countEntriesByResult($entries, ActivityEntryResult::FAILED);
                $pending = $this->countEntriesByResult($entries, ActivityEntryResult::PENDING);
                $performers = $activities
                    ->pluck('performer')
                    ->filter()
                    ->unique('id')
                    ->values();

                return [
                    ...$representative,
                    'source_group_ids' => $activities->pluck('group_id')->values()->all(),
                    'activity_count'   => $activities->count(),
                    'performers'       => $performers,
                    'performer_label'  => $this->formatPerformerLabel($performers),
                    'total_candidates' => $entries->count(),
                    'passed_count'     => $passed,
                    'failed_count'     => $failed,
                    'pending_count'    => $pending,
                    'summary'          => self::activitySummaryText($entries->count(), $passed, $failed, $pending),
                    'entries'          => $entries,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, JobApplicationHistory>  $entries
     */
    protected function countEntriesByResult(Collection $entries, ActivityEntryResult $result): int
    {
        return $entries
            ->filter(fn (JobApplicationHistory $entry): bool => $entry->result?->value === $result->value)
            ->count();
    }

    /**
     * @param  Collection<int, mixed>  $performers
     */
    protected function formatPerformerLabel(Collection $performers): string
    {
        if ($performers->isEmpty()) {
            return '-';
        }

        if ($performers->count() === 1) {
            return (string) $performers->first()->name;
        }

        return __('rekrutmen::livewire/recruitment-progress-report.labels.multiple_performers', [
            'count' => $performers->count(),
        ]);
    }

    /**
     * @param  Collection<int, JobPosting>  $postings
     * @param  Collection<int, object{job_posting_id:int,total:int,in_progress:int,hired:int,rejected:int}>  $statsByPosting
     * @param  array<int, array<int, int>>  $stageCountsByPosting
     * @param  array<int, array<int, int>>  $passedCountsByPostingStage
     * @param  Collection<int, array<string, mixed>>  $formattedActivities
     * @param  Collection<int, Collection<int, array{id:int,job_posting_id:int,full_name:string,hired_at:?string,hired_at_label:string,performed_by_id:?int,performed_by:?string,notes:?string}>>  $hiredCandidatesByPosting
     * @return Collection<int, array<string, mixed>>
     */
    protected function buildPositions(
        Collection $postings,
        Collection $statsByPosting,
        array $stageCountsByPosting,
        array $passedCountsByPostingStage,
        Collection $formattedActivities,
        Collection $hiredCandidatesByPosting,
        Carbon $snapshotDate,
    ): Collection {
        $activitiesByPosting = $formattedActivities
            ->filter(fn (array $activity): bool => filled($activity['job_posting_id']))
            ->groupBy('job_posting_id');

        return $postings->map(function (JobPosting $posting) use ($statsByPosting, $stageCountsByPosting, $passedCountsByPostingStage, $activitiesByPosting, $hiredCandidatesByPosting, $snapshotDate): array {
            $stats = $statsByPosting->get($posting->id);
            $requests = $posting->requestManPowers
                ->filter(fn (RequestManPower $request): bool => $this->requestIsVisibleAtSnapshot($request, $snapshotDate))
                ->values();
            $sourceRequest = $posting->requestManPower
                && $this->requestIsVisibleAtSnapshot($posting->requestManPower, $snapshotDate)
                ? $posting->requestManPower
                : null;

            if ($requests->isEmpty() && $sourceRequest) {
                $requests = collect([$sourceRequest]);
            }

            $approvedRequests = $requests->filter(
                fn (RequestManPower $request): bool => $request->status === RequestManPowerStatus::APPROVED
            );
            $heldRequests = $requests->filter(
                fn (RequestManPower $request): bool => $request->status === RequestManPowerStatus::HOLD
            );
            $pendingRequests = $requests->filter(
                fn (RequestManPower $request): bool => $request->status === RequestManPowerStatus::PENDING
            );
            $request = $approvedRequests->first()
                ?? $heldRequests->first()
                ?? $pendingRequests->first()
                ?? $requests->first()
                ?? $sourceRequest;
            $hiredCandidates = $hiredCandidatesByPosting->get($posting->id, collect());
            $needed = $this->totalNeededForSnapshot($requests, $sourceRequest);
            $hired = $hiredCandidates->count();
            $postingActivities = $activitiesByPosting->get($posting->id, collect())->values();
            $isOnHold = $heldRequests->isNotEmpty() && $approvedRequests->isEmpty();
            $statistics = [
                'total_applicants' => (int) ($stats->total ?? 0),
                'in_progress'      => (int) ($stats->in_progress ?? 0),
                'hired'            => $hired,
                'rejected'         => (int) ($stats->rejected ?? 0),
            ];
            $cycleHealth = $this->buildCycleHealth($posting, $requests, $statistics, $needed, $postingActivities);

            $pipelineStages = $posting->rekrutmenPipeline?->stages
                ?->sortBy('order_column')
                ->map(fn ($stage): array => [
                    'id'            => $stage->id,
                    'name'          => $stage->name,
                    'current_count' => $stageCountsByPosting[$posting->id][$stage->id] ?? 0,
                    'total_passed'  => $passedCountsByPostingStage[$posting->id][$stage->id] ?? 0,
                ])
                ->values()
                ?? collect();

            return [
                'posting'                => $posting,
                'request'                => $request,
                'statistics'             => $statistics,
                'activities'             => $postingActivities,
                'cycle_health'           => $cycleHealth,
                'hired_candidates'       => $hiredCandidates->values(),
                'requests'               => $requests->values(),
                'request_fulfillments'   => $this->buildRequestFulfillments($requests, $hiredCandidates, $snapshotDate),
                'pipeline_stages'        => $pipelineStages,
                'latest_activity'        => $postingActivities->first(),
                'needed'                 => $needed,
                'fulfillment_percentage' => $needed > 0 ? min(100, round(($hired / $needed) * 100)) : 0,
                'request_status'         => $request?->status?->value,
                'request_status_label'   => $request?->status?->getLabel() ?? '-',
                'is_on_hold'             => $isOnHold,
            ];
        })->values();
    }

    protected function requestIsVisibleAtSnapshot(RequestManPower $request, Carbon $snapshotDate): bool
    {
        if ($request->trashed()) {
            return false;
        }

        if (! in_array($request->status, [
            RequestManPowerStatus::PENDING,
            RequestManPowerStatus::APPROVED,
            RequestManPowerStatus::HOLD,
        ], true)) {
            return false;
        }

        return $request->tanggal_pengajuan instanceof Carbon
            && $request->tanggal_pengajuan->copy()->startOfDay()->lte($snapshotDate);
    }

    /**
     * @param  Collection<int, RequestManPower>  $requests
     */
    protected function totalNeededForSnapshot(Collection $requests, ?RequestManPower $sourceRequest): int
    {
        $activeNeed = $requests
            ->filter(fn (RequestManPower $request): bool => in_array($request->status, [
                RequestManPowerStatus::APPROVED,
                RequestManPowerStatus::HOLD,
            ], true))
            ->sum(fn (RequestManPower $request): int => $this->requestNeededHeadcount($request));

        if ((int) $activeNeed > 0) {
            return (int) $activeNeed;
        }

        $pendingRequest = $sourceRequest
            ?? $requests->first(fn (RequestManPower $request): bool => $request->status === RequestManPowerStatus::PENDING);

        return $pendingRequest instanceof RequestManPower
            ? $this->requestNeededHeadcount($pendingRequest)
            : 0;
    }

    /**
     * @param  array{date_from?: ?string, date_to?: ?string}  $filters
     */
    protected function resolveSnapshotDate(array $filters): Carbon
    {
        return $this->parseFilterDate($filters['date_to'] ?? null)?->endOfDay()
            ?? $this->parseFilterDate($filters['date_from'] ?? null)?->endOfDay()
            ?? now()->endOfDay();
    }

    /**
     * @param  Collection<int, RequestManPower>  $requests
     * @param  Collection<int, array{id:int,job_posting_id:int,full_name:string,hired_at:?string,hired_at_label:string,performed_by_id:?int,performed_by:?string,notes:?string}>  $hiredCandidates
     * @return Collection<int, array<string, mixed>>
     */
    protected function buildRequestFulfillments(Collection $requests, Collection $hiredCandidates, Carbon $snapshotDate): Collection
    {
        if ($requests->isEmpty()) {
            return collect();
        }

        $orderedRequests = $requests
            ->filter(fn (RequestManPower $request): bool => in_array($request->status, [
                RequestManPowerStatus::PENDING,
                RequestManPowerStatus::APPROVED,
                RequestManPowerStatus::HOLD,
            ], true))
            ->sortBy(fn (RequestManPower $request): string => $this->requestSortKey($request))
            ->values();

        $countedRequests = $orderedRequests
            ->filter(fn (RequestManPower $request): bool => in_array($request->status, [
                RequestManPowerStatus::APPROVED,
                RequestManPowerStatus::HOLD,
            ], true))
            ->values();

        if ($countedRequests->isEmpty() && $orderedRequests->isNotEmpty()) {
            $countedRequests = $orderedRequests->take(1)->values();
        }

        $orderedCandidates = $hiredCandidates
            ->sortBy(fn (array $candidate): string => sprintf(
                '%s|%010d',
                $candidate['hired_at'] ?? '9999-12-31',
                (int) ($candidate['id'] ?? 0),
            ))
            ->values();
        $candidateIndex = 0;
        $filledSlotsByRequest = [];

        foreach ($countedRequests as $request) {
            $needed = $this->requestNeededHeadcount($request);
            $filledSlotsByRequest[(int) $request->getKey()] = collect();

            for ($slot = 0; $slot < $needed; $slot++) {
                $candidate = $orderedCandidates->get($candidateIndex);
                $candidateIndex++;

                if (! is_array($candidate)) {
                    break;
                }

                $filledSlotsByRequest[(int) $request->getKey()]->push($candidate);
            }
        }

        return $orderedRequests
            ->map(function (RequestManPower $request) use ($filledSlotsByRequest, $snapshotDate, $countedRequests): array {
                $requestId = (int) $request->getKey();
                $needed = $this->requestNeededHeadcount($request);
                $filledSlots = $filledSlotsByRequest[$requestId] ?? collect();
                $fulfilled = min($needed, $filledSlots->count());
                $remaining = max(0, $needed - $fulfilled);
                $requestDate = $request->tanggal_pengajuan instanceof Carbon
                    ? $request->tanggal_pengajuan->copy()->startOfDay()
                    : $request->created_at?->copy()->startOfDay();
                $closingCandidate = $remaining === 0 ? $filledSlots->last() : null;
                $fulfilledAt = is_array($closingCandidate)
                    ? $this->parseFilterDate($closingCandidate['hired_at'] ?? null)
                    : null;
                $ageUntil = $fulfilledAt?->endOfDay() ?? $snapshotDate;
                $estimatedJoin = $request->estimasi_tanggal_join instanceof Carbon
                    ? $request->estimasi_tanggal_join->copy()->endOfDay()
                    : null;
                $countedInNeed = $countedRequests->contains(
                    fn (RequestManPower $countedRequest): bool => (int) $countedRequest->getKey() === $requestId
                );
                $fulfillmentStatus = $this->requestFulfillmentStatus($request, $fulfilled, $remaining);

                return [
                    'request_id'               => $requestId,
                    'company'                  => $request->company?->name ?? '-',
                    'position'                 => $request->posisi_dibutuhkan ?? '-',
                    'location'                 => $request->lokasi_penempatan ?? '-',
                    'status'                   => $request->status?->value,
                    'status_label'             => $request->status?->getLabel() ?? '-',
                    'needed'                   => $needed,
                    'fulfilled'                => $fulfilled,
                    'remaining'                => $remaining,
                    'request_date'             => $requestDate?->toDateString(),
                    'request_date_label'       => $this->formatDate($requestDate),
                    'estimated_join'           => $estimatedJoin?->toDateString(),
                    'estimated_join_label'     => $this->formatDate($estimatedJoin),
                    'snapshot_date'            => $snapshotDate->toDateString(),
                    'snapshot_label'           => $this->formatDate($snapshotDate),
                    'fulfilled_at'             => $fulfilledAt?->toDateString(),
                    'fulfilled_at_label'       => $fulfilledAt ? $this->formatDate($fulfilledAt) : '-',
                    'closing_candidate'        => is_array($closingCandidate) ? ($closingCandidate['full_name'] ?? '-') : null,
                    'age_days'                 => $requestDate instanceof Carbon ? $this->fulfillmentDays($requestDate, $ageUntil) : 0,
                    'is_fulfilled'             => $needed > 0 && $remaining === 0,
                    'is_partially_fulfilled'   => $fulfilled > 0 && $remaining > 0,
                    'is_counted_in_need'       => $countedInNeed,
                    'estimate_missed'          => $remaining > 0
                        && $estimatedJoin instanceof Carbon
                        && $estimatedJoin->lt($snapshotDate),
                    'fulfillment_status'       => $fulfillmentStatus,
                    'fulfillment_status_label' => __('rekrutmen::livewire/recruitment-progress-report.workflow.mpp.status.'.$fulfillmentStatus),
                ];
            })
            ->values();
    }

    protected function requestSortKey(RequestManPower $request): string
    {
        return sprintf(
            '%s|%010d',
            $request->tanggal_pengajuan?->format('Y-m-d') ?? '9999-12-31',
            (int) $request->getKey(),
        );
    }

    protected function requestNeededHeadcount(RequestManPower $request): int
    {
        return max(1, (int) ($request->jumlah_karyawan_dibutuhkan ?? 1));
    }

    protected function fulfillmentDays(Carbon $from, Carbon $to): int
    {
        return (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay());
    }

    protected function requestFulfillmentStatus(RequestManPower $request, int $fulfilled, int $remaining): string
    {
        if ($request->status === RequestManPowerStatus::PENDING) {
            return 'pending_approval';
        }

        if ($request->status === RequestManPowerStatus::HOLD) {
            return 'on_hold';
        }

        if ($remaining === 0) {
            return 'fulfilled';
        }

        if ($fulfilled > 0) {
            return 'partial';
        }

        return 'open';
    }

    /**
     * @param  int[]  $postingIds
     * @param  Collection<int, object{job_posting_id:int,total:int,in_progress:int,hired:int,rejected:int}>  $statsByPosting
     * @param  Collection<int, array<string, mixed>>  $activityDigest
     * @param  array{hired:int,rejected:int}  $decisionCounts
     * @param  Collection<int, array<string, mixed>>  $positions
     * @param  Collection<int, array<string, mixed>>  $hrKpis
     * @return array<string, int>
     */
    protected function buildSummary(
        array $postingIds,
        Collection $statsByPosting,
        Collection $activityDigest,
        array $decisionCounts,
        Collection $positions,
        Collection $hrKpis,
    ): array {
        return [
            'total_positions_active'        => count($postingIds),
            'total_candidates_in_process'   => $statsByPosting->sum(fn (object $stats): int => (int) $stats->in_progress),
            'total_activities_this_period'  => $activityDigest->count(),
            'total_hired_this_period'       => $decisionCounts['hired'],
            'total_rejected_this_period'    => $decisionCounts['rejected'],
            'total_hr_kpi_people'           => $hrKpis->count(),
            'total_hr_kpi_hired_headcount'  => $hrKpis->sum(fn (array $kpi): int => (int) $kpi['hired_headcount']),
            'total_hr_kpi_fulfilled_mpp'    => $hrKpis->sum(fn (array $kpi): int => (int) $kpi['fulfilled_mpp']),
            'total_cycle_health_issues'     => $positions
                ->filter(fn (array $position): bool => ($position['cycle_health']['status'] ?? 'healthy') !== 'healthy')
                ->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $positions
     * @param  array{date_from?: ?string, date_to?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    protected function buildHrKpis(Collection $positions, array $filters): Collection
    {
        [$dateFrom, $dateTo] = $this->resolveFilterPeriod($filters);
        $kpis = [];

        foreach ($positions as $position) {
            $approvedRequests = collect($position['requests'] ?? [])
                ->filter(fn (RequestManPower $request): bool => $request->status === RequestManPowerStatus::APPROVED)
                ->sortBy(fn (RequestManPower $request): string => sprintf(
                    '%s|%010d',
                    $request->tanggal_pengajuan?->format('Y-m-d') ?? '9999-12-31',
                    (int) $request->getKey(),
                ))
                ->values();

            if ($approvedRequests->isEmpty()) {
                continue;
            }

            $hiredCandidates = collect($position['hired_candidates'] ?? [])
                ->sortBy(fn (array $candidate): string => sprintf(
                    '%s|%010d',
                    $candidate['hired_at'] ?? '9999-12-31',
                    (int) ($candidate['id'] ?? 0),
                ))
                ->values();
            $candidateIndex = 0;

            foreach ($approvedRequests as $request) {
                $needed = max(1, (int) ($request->jumlah_karyawan_dibutuhkan ?? 1));
                $filledSlots = collect();

                for ($slot = 0; $slot < $needed; $slot++) {
                    $candidate = $hiredCandidates->get($candidateIndex);
                    $candidateIndex++;

                    if (! is_array($candidate)) {
                        break;
                    }

                    $filledSlots->push($candidate);

                    if ($this->candidateIsInsidePeriod($candidate, $dateFrom, $dateTo)) {
                        $key = $this->hrKpiKey($candidate);
                        $this->ensureHrKpiRow($kpis, $key, $candidate);
                        $kpis[$key]['hired_headcount']++;
                    }
                }

                if ($filledSlots->count() !== $needed) {
                    continue;
                }

                $closingCandidate = $filledSlots->last();

                if (! is_array($closingCandidate) || ! $this->candidateIsInsidePeriod($closingCandidate, $dateFrom, $dateTo)) {
                    continue;
                }

                $key = $this->hrKpiKey($closingCandidate);
                $this->ensureHrKpiRow($kpis, $key, $closingCandidate);
                $kpis[$key]['fulfilled_mpp']++;
                $kpis[$key]['fulfilled_requests'][] = [
                    'request_id'     => (int) $request->getKey(),
                    'job_posting_id' => (int) ($position['posting']?->id ?? 0),
                    'company'        => $request->company?->name ?? '-',
                    'position'       => $position['posting']?->title ?? $request->posisi_dibutuhkan ?? '-',
                    'needed'         => $needed,
                    'fulfilled_at'   => $closingCandidate['hired_at_label'] ?? '-',
                    'closing_hire'   => $closingCandidate['full_name'] ?? '-',
                ];
            }
        }

        return collect($kpis)
            ->map(function (array $kpi): array {
                $kpi['fulfillment_summary'] = __('rekrutmen::livewire/recruitment-progress-report.workflow.kpi.fulfillment_summary', [
                    'mpp'   => $kpi['fulfilled_mpp'],
                    'hired' => $kpi['hired_headcount'],
                ]);

                return $kpi;
            })
            ->sortBy(fn (array $kpi): string => sprintf(
                '%05d|%05d|%s',
                99999 - (int) $kpi['fulfilled_mpp'],
                99999 - (int) $kpi['hired_headcount'],
                mb_strtolower((string) $kpi['performer_name']),
            ))
            ->values();
    }

    /**
     * @param  array<string, array<string, mixed>>  $kpis
     * @param  array<string, mixed>  $candidate
     */
    protected function ensureHrKpiRow(array &$kpis, string $key, array $candidate): void
    {
        if (isset($kpis[$key])) {
            return;
        }

        $performerId = is_numeric($candidate['performed_by_id'] ?? null)
            ? (int) $candidate['performed_by_id']
            : null;

        $kpis[$key] = [
            'performer_id'       => $performerId,
            'performer_name'     => filled($candidate['performed_by'] ?? null)
                ? (string) $candidate['performed_by']
                : __('rekrutmen::livewire/recruitment-progress-report.workflow.kpi.unassigned_pic'),
            'hired_headcount'    => 0,
            'fulfilled_mpp'      => 0,
            'fulfilled_requests' => [],
            'has_data_gap'       => $performerId === null,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    protected function hrKpiKey(array $candidate): string
    {
        if (is_numeric($candidate['performed_by_id'] ?? null)) {
            return 'user:'.(int) $candidate['performed_by_id'];
        }

        return 'unassigned';
    }

    /**
     * @param  array{date_from?: ?string, date_to?: ?string}  $filters
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    protected function resolveFilterPeriod(array $filters): array
    {
        return [
            $this->parseFilterDate($filters['date_from'] ?? null)?->startOfDay(),
            $this->parseFilterDate($filters['date_to'] ?? null)?->endOfDay(),
        ];
    }

    protected function parseFilterDate(?string $date): ?Carbon
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        return Carbon::parse($date);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    protected function candidateIsInsidePeriod(array $candidate, ?Carbon $dateFrom, ?Carbon $dateTo): bool
    {
        if (! is_string($candidate['hired_at'] ?? null) || $candidate['hired_at'] === '') {
            return false;
        }

        $hiredAt = Carbon::parse($candidate['hired_at'])->endOfDay();

        if ($dateFrom && $hiredAt->lt($dateFrom)) {
            return false;
        }

        if ($dateTo && $hiredAt->gt($dateTo)) {
            return false;
        }

        return true;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $formattedActivities
     * @return Collection<int, array<string, mixed>>
     */
    protected function buildTimeline(Collection $formattedActivities): Collection
    {
        return $formattedActivities
            ->groupBy(fn (array $activity): string => $activity['activity_date']?->format('Y-m-d') ?? '')
            ->map(function (Collection $activities, string $date): array {
                $representative = $activities->first();

                return [
                    'date'               => $date,
                    'date_label'         => $date !== '' ? Carbon::parse($date)->translatedFormat('l, d F Y') : '-',
                    'activities'         => $activities->values(),
                    'count'              => $activities->count(),
                    'raw_activity_count' => $activities->sum(fn (array $activity): int => (int) ($activity['activity_count'] ?? 1)),
                    'candidate_count'    => $activities->sum(fn (array $activity): int => (int) ($activity['total_candidates'] ?? 0)),
                    'activity'           => $representative,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $positions
     * @return Collection<int, array<string, mixed>>
     */
    protected function buildOverview(Collection $positions): Collection
    {
        return $positions->map(function (array $position): array {
            return [
                'job_posting_id'         => $position['posting']->id,
                'position'               => $position['posting']->title,
                'company'                => $position['request']?->company?->name,
                'location'               => $position['posting']->location,
                'needed'                 => $position['needed'],
                'total_applicants'       => $position['statistics']['total_applicants'],
                'hired'                  => $position['statistics']['hired'],
                'hired_candidates'       => $position['hired_candidates'],
                'in_progress'            => $position['statistics']['in_progress'],
                'rejected'               => $position['statistics']['rejected'],
                'latest_activity'        => $position['latest_activity'],
                'pipeline_progress'      => $position['pipeline_stages'],
                'fulfillment_percentage' => $position['fulfillment_percentage'],
                'request_status'         => $position['request_status'],
                'request_status_label'   => $position['request_status_label'],
                'is_on_hold'             => $position['is_on_hold'],
                'cycle_health'           => $position['cycle_health'],
                'request_fulfillments'   => $position['request_fulfillments'],
            ];
        })->values();
    }

    /**
     * @param  Collection<int, RequestManPower>  $requests
     * @param  array{total_applicants:int,in_progress:int,hired:int,rejected:int}  $statistics
     * @param  Collection<int, array<string, mixed>>  $activities
     * @return array{
     *     status:string,
     *     status_label:string,
     *     summary:string,
     *     description:string,
     *     issues:array<int, array{key:string,severity:string,label:string,description:string}>
     * }
     */
    protected function buildCycleHealth(JobPosting $posting, Collection $requests, array $statistics, int $needed, Collection $activities): array
    {
        $issues = [];
        $outstanding = max(0, $needed - (int) $statistics['hired']);
        $approvedRequests = $requests->filter(
            fn (RequestManPower $request): bool => $request->status === RequestManPowerStatus::APPROVED
        );
        $pendingRequests = $requests->filter(
            fn (RequestManPower $request): bool => $request->status === RequestManPowerStatus::PENDING
        );

        if ($requests->isEmpty()) {
            $issues[] = $this->cycleIssue('orphan_posting', 'risk');
        }

        if ($approvedRequests->isEmpty() && $pendingRequests->isNotEmpty()) {
            $issues[] = $this->cycleIssue('pending_request', 'watch');
        }

        if ($outstanding > 0 && ! $posting->is_published) {
            $issues[] = $this->cycleIssue('posting_unpublished', 'risk');
        }

        if ($outstanding > 0 && $posting->closing_date?->lt(today())) {
            $issues[] = $this->cycleIssue('posting_closed', 'risk');
        }

        if ((int) $statistics['hired'] > $needed && $needed > 0) {
            $issues[] = $this->cycleIssue('over_hired', 'risk');
        }

        if ($approvedRequests->isNotEmpty() && $outstanding > 0 && (int) $statistics['total_applicants'] === 0) {
            $issues[] = $this->cycleIssue('no_applicants', 'watch');
        }

        if (
            $approvedRequests->isNotEmpty()
            && $outstanding > 0
            && (int) $statistics['total_applicants'] > 0
            && (int) $statistics['in_progress'] === 0
        ) {
            $issues[] = $this->cycleIssue('no_active_candidates', 'watch');
        }

        $hasActivityWithoutPic = $activities->contains(function (array $activity): bool {
            $performers = collect($activity['performers'] ?? []);

            return (int) ($activity['total_candidates'] ?? 0) > 0 && $performers->isEmpty();
        });

        if ($hasActivityWithoutPic) {
            $issues[] = $this->cycleIssue('missing_activity_pic', 'risk');
        }

        if ($issues === []) {
            return [
                'status'       => 'healthy',
                'status_label' => __('rekrutmen::livewire/recruitment-progress-report.workflow.cycle_health.status.healthy'),
                'summary'      => __('rekrutmen::livewire/recruitment-progress-report.workflow.cycle_health.healthy.summary'),
                'description'  => __('rekrutmen::livewire/recruitment-progress-report.workflow.cycle_health.healthy.description'),
                'issues'       => [],
            ];
        }

        $status = collect($issues)->contains(fn (array $issue): bool => $issue['severity'] === 'risk')
            ? 'risk'
            : 'watch';
        $primaryIssue = $issues[0];

        return [
            'status'       => $status,
            'status_label' => __("rekrutmen::livewire/recruitment-progress-report.workflow.cycle_health.status.{$status}"),
            'summary'      => $primaryIssue['label'],
            'description'  => $primaryIssue['description'],
            'issues'       => $issues,
        ];
    }

    /**
     * @return array{key:string,severity:string,label:string,description:string}
     */
    protected function cycleIssue(string $key, string $severity): array
    {
        return [
            'key'         => $key,
            'severity'    => $severity,
            'label'       => __("rekrutmen::livewire/recruitment-progress-report.workflow.cycle_health.issues.{$key}.label"),
            'description' => __("rekrutmen::livewire/recruitment-progress-report.workflow.cycle_health.issues.{$key}.description"),
        ];
    }

    public static function activitySummaryText(int $total, int $passed, int $failed, int $pending): string
    {
        if ($total > 0) {
            $nonZeroResults = array_filter([
                'passed'  => $passed,
                'failed'  => $failed,
                'pending' => $pending,
            ]);

            if (count($nonZeroResults) === 1 && reset($nonZeroResults) === $total) {
                return __('rekrutmen::livewire/recruitment-progress-report.summary_text.all_'.array_key_first($nonZeroResults), ['count' => $total]);
            }
        }

        $parts = [
            __('rekrutmen::livewire/recruitment-progress-report.summary_text.total_candidates', ['count' => $total]),
        ];

        if ($passed > 0) {
            $parts[] = __('rekrutmen::livewire/recruitment-progress-report.summary_text.passed', ['count' => $passed]);
        }

        if ($failed > 0) {
            $parts[] = __('rekrutmen::livewire/recruitment-progress-report.summary_text.failed', ['count' => $failed]);
        }

        if ($pending > 0) {
            $parts[] = __('rekrutmen::livewire/recruitment-progress-report.summary_text.pending', ['count' => $pending]);
        }

        return implode(' ', $parts);
    }

    protected function historyEventDate(?JobApplicationHistory $history): ?Carbon
    {
        if (! $history instanceof JobApplicationHistory) {
            return null;
        }

        if ($history->activity_date instanceof Carbon) {
            return $history->activity_date->copy()->endOfDay();
        }

        return $history->created_at?->copy();
    }

    protected function formatDate(mixed $date): string
    {
        if ($date instanceof Carbon) {
            return $date->format('d M Y');
        }

        return '-';
    }
}
