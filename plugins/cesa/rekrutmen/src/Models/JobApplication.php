<?php

namespace Cesa\Rekrutmen\Models;

use Cesa\Rekrutmen\Enums\ActivityEntryResult;
use Cesa\Rekrutmen\Enums\JobApplicationGender;
use Cesa\Rekrutmen\Enums\JobApplicationMaritalStatus;
use Cesa\Rekrutmen\Enums\JobApplicationStatus;
use Cesa\Rekrutmen\Services\MailThrottleService;
use Cesa\Rekrutmen\Services\RekrutmenStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Relaticle\Flowforge\Services\DecimalPosition;
use Throwable;
use Webkul\Security\Traits\HasNullableCreator;

class JobApplication extends Model
{
    use HasFactory, HasNullableCreator, SoftDeletes;

    public const RESUME_DIRECTORY = 'rekrutmen/cv';

    public const PHOTO_DIRECTORY = 'rekrutmen/photos';

    /**
     * @var array<string, array{path: ?string, disk: ?string}>
     */
    protected array $originalAttachmentPaths = [];

    /**
     * @var array<string, bool>
     */
    protected array $explicitAttachmentDisks = [];

    /**
     * @var array{job_posting_id: int, active_email: string}|null
     */
    protected ?array $originalActiveEmailOwnership = null;

    /**
     * @var array{job_posting_id: int, active_whatsapp: string}|null
     */
    protected ?array $originalActiveWhatsappOwnership = null;

    protected $table = 'rekrutmen_job_applications';

    protected $fillable = [
        'job_posting_id',
        'current_stage_id',
        'source',
        'position',
        'full_name',
        'gender',
        'birth_date',
        'marital_status',
        'address_ktp',
        'address_domicile',
        'whatsapp_number',
        'active_whatsapp',
        'active_phone',
        'emergency_contact_name',
        'emergency_contact_relation',
        'emergency_contact_phone',
        'email',
        'source',
        'photo_path',
        'photo_disk',
        'resume_path',
        'resume_disk',
        'status',
        'ai_match_score',
        'ai_recommendation',
        'ai_summary',
        'ai_analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'gender'         => JobApplicationGender::class,
            'birth_date'     => 'date',
            'marital_status' => JobApplicationMaritalStatus::class,
            'status'         => JobApplicationStatus::class,
            'ai_match_score' => 'integer',
            'ai_analyzed_at' => 'datetime',
            'position'       => 'decimal:10',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
            'deleted_at'     => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (JobApplication $application): void {
            $application->normalizeTransactionalInput();
            $application->snapshotOriginalActiveEmailOwnership();
            $application->snapshotOriginalActiveWhatsappOwnership();
            $application->syncActiveEmail();
            $application->syncActiveWhatsapp();
            $application->assertActiveEmailIsUniqueForJobPosting();
            $application->assertActiveWhatsappIsUniqueForJobPosting();
            $application->ensurePipelineStageIntegrity();
            $application->ensureBoardPosition();
            $application->snapshotOriginalAttachmentPaths();
            $application->prepareManagedAttachmentPathForPersistence('resume_path', self::RESUME_DIRECTORY, 'CV');
            $application->prepareManagedAttachmentPathForPersistence('photo_path', self::PHOTO_DIRECTORY, 'PHOTO');
        });

        static::saved(function (JobApplication $application): void {
            if ($application->wasRecentlyCreated) {
                $application->syncManagedAttachmentPath('resume_path', self::RESUME_DIRECTORY, 'CV');
                $application->syncManagedAttachmentPath('photo_path', self::PHOTO_DIRECTORY, 'PHOTO');
            }

            $application->deleteRemovedAttachmentFile('resume_path');
            $application->deleteRemovedAttachmentFile('photo_path');
            $application->explicitAttachmentDisks = [];
            $application->reassignOriginalActiveEmailIfNeeded();
            $application->reassignOriginalActiveWhatsappIfNeeded();
        });

        static::created(function (JobApplication $application): void {
            $application->ensureInitialHistory();
        });

        static::deleted(function (JobApplication $application): void {
            $normalizedEmail = $application->normalizeEmail($application->getRawOriginal('email') ?? $application->email);
            $normalizedWhatsapp = $application->normalizePhoneNumber($application->getRawOriginal('whatsapp_number') ?? $application->whatsapp_number);

            if (! $application->isForceDeleting()) {
                static::query()
                    ->withoutGlobalScopes()
                    ->whereKey($application->getKey())
                    ->update([
                        'active_email'    => null,
                        'active_whatsapp' => null,
                    ]);

                $application->active_email = null;
                $application->active_whatsapp = null;
            }

            $application->reassignActiveEmailToCanonicalPeer($normalizedEmail);
            $application->reassignActiveWhatsappToCanonicalPeer($normalizedWhatsapp);
        });

        static::restored(function (JobApplication $application): void {
            $application->syncActiveEmail();
            $application->syncActiveWhatsapp();

            static::query()
                ->withoutGlobalScopes()
                ->whereKey($application->getKey())
                ->update([
                    'active_email'    => $application->active_email,
                    'active_whatsapp' => $application->active_whatsapp,
                ]);
        });

        static::forceDeleted(function (JobApplication $application): void {
            $application->deleteManagedFile($application->normalizeManagedFilePath(
                $application->getRawOriginal('resume_path') ?? $application->resume_path
            ), $application->normalizeAttachmentDisk($application->getRawOriginal('resume_disk')));
            $application->deleteManagedFile($application->normalizeManagedFilePath(
                $application->getRawOriginal('photo_path') ?? $application->photo_path
            ), $application->normalizeAttachmentDisk($application->getRawOriginal('photo_disk')));
        });
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class, 'job_posting_id')->withTrashed();
    }

    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(RekrutmenStage::class, 'current_stage_id')->withTrashed();
    }

    public function histories(): HasMany
    {
        return $this->hasMany(JobApplicationHistory::class, 'job_application_id')->latest();
    }

    public function sendSubmittedNotification(): void
    {
        if (blank($this->email)) {
            return;
        }

        try {
            $notification = new JobApplicationSubmittedNotification($this->fresh(['jobPosting', 'currentStage']) ?? $this);
            $delaySeconds = app(MailThrottleService::class)->getDispatchDelaySeconds();

            if ($delaySeconds > 0) {
                $notification->delay(now()->addSeconds($delaySeconds));
            }

            NotificationFacade::route('mail', $this->email)
                ->notify($notification);
        } catch (Throwable $exception) {
            Log::error('Failed to send job application submitted notification.', [
                'job_application_id' => $this->getKey(),
                'email'              => $this->email,
                'exception'          => $exception,
            ]);
        }
    }

    public function isTerminalStatus(): bool
    {
        return in_array($this->status, [
            JobApplicationStatus::HIRED,
            JobApplicationStatus::REJECTED,
            JobApplicationStatus::WITHDRAWN,
        ], true);
    }

    public static function resolveInitialStageIdForJobPostingId(mixed $jobPostingId): ?int
    {
        if (! is_numeric($jobPostingId)) {
            return null;
        }

        $jobPosting = JobPosting::query()->find((int) $jobPostingId);

        if (! $jobPosting?->rekrutmen_pipeline_id) {
            return null;
        }

        return RekrutmenStage::query()
            ->where('rekrutmen_pipeline_id', $jobPosting->rekrutmen_pipeline_id)
            ->orderBy('order_column')
            ->value('id');
    }

    public function stageBelongsToCurrentPipeline(?int $stageId): bool
    {
        $pipelineId = $this->resolvePipelineIdForCurrentJobPosting();

        if (! $pipelineId) {
            return $stageId === null;
        }

        if ($stageId === null) {
            return false;
        }

        return RekrutmenStage::query()
            ->whereKey($stageId)
            ->where('rekrutmen_pipeline_id', $pipelineId)
            ->exists();
    }

    public function syncCurrentStageToJobPosting(?int $performedBy = null, ?string $notes = null): void
    {
        $targetStageId = self::resolveInitialStageIdForJobPostingId($this->job_posting_id);

        if ($targetStageId === $this->current_stage_id) {
            return;
        }

        $fromStageId = $this->current_stage_id;

        $this->update([
            'current_stage_id' => $targetStageId,
            'position'         => $this->nextPositionForStage($targetStageId),
        ]);

        $this->recordHistory(
            $fromStageId,
            $targetStageId,
            $this->status ?? JobApplicationStatus::IN_PROGRESS,
            $notes ?? __('rekrutmen::filament/resources/job-application.workflow_notes.stage_synced'),
            $performedBy,
        );
    }

    public function transitionToStage(int $toStageId, ?string $notes = null, ?int $performedBy = null): void
    {
        if ($this->isTerminalStatus()) {
            throw new InvalidArgumentException(__('rekrutmen::filament/resources/job-application.workflow_errors.terminal_stage_locked'));
        }

        if (! $this->stageBelongsToCurrentPipeline($toStageId)) {
            throw new InvalidArgumentException(__('rekrutmen::filament/resources/job-application.workflow_errors.invalid_stage'));
        }

        if ($toStageId === $this->current_stage_id) {
            return;
        }

        $fromStageId = $this->current_stage_id;

        $this->update([
            'current_stage_id' => $toStageId,
            'position'         => $this->nextPositionForStage($toStageId),
        ]);

        $this->recordHistory(
            $fromStageId,
            $toStageId,
            $this->status ?? JobApplicationStatus::IN_PROGRESS,
            $notes ?? __('rekrutmen::filament/resources/job-application.workflow_notes.stage_changed'),
            $performedBy,
        );
    }

    public function nextStageAfterCurrentStage(): ?RekrutmenStage
    {
        if ($this->isTerminalStatus() || ! is_numeric($this->current_stage_id)) {
            return null;
        }

        $pipelineId = $this->resolvePipelineIdForCurrentJobPosting();

        if (! $pipelineId) {
            return null;
        }

        $currentStageOrder = RekrutmenStage::query()
            ->whereKey((int) $this->current_stage_id)
            ->where('rekrutmen_pipeline_id', $pipelineId)
            ->value('order_column');

        if ($currentStageOrder === null) {
            return null;
        }

        return RekrutmenStage::query()
            ->where('rekrutmen_pipeline_id', $pipelineId)
            ->where('order_column', '>', $currentStageOrder)
            ->orderBy('order_column')
            ->first();
    }

    public function canPassCurrentStage(): bool
    {
        return $this->nextStageAfterCurrentStage() instanceof RekrutmenStage;
    }

    public function canMarkAsHired(): bool
    {
        return $this->status === JobApplicationStatus::IN_PROGRESS
            && $this->isAtFinalHiringDecisionStage();
    }

    public function canMarkAsWithdrawn(): bool
    {
        return $this->status === JobApplicationStatus::HIRED;
    }

    public function isAtFinalPipelineStage(): bool
    {
        return $this->isAtFinalHiringDecisionStage();
    }

    public function isAtFinalHiringDecisionStage(): bool
    {
        if (! is_numeric($this->current_stage_id)) {
            return false;
        }

        $currentStageId = (int) $this->current_stage_id;
        $pipelineId = $this->resolvePipelineIdForCurrentJobPosting();

        if (! $pipelineId) {
            return false;
        }

        $hiredStageId = $this->resolveTerminalStageIdForStatus(JobApplicationStatus::HIRED);

        if ($hiredStageId !== null) {
            return $currentStageId === $hiredStageId;
        }

        $currentStageOrder = RekrutmenStage::query()
            ->whereKey($currentStageId)
            ->where('rekrutmen_pipeline_id', $pipelineId)
            ->value('order_column');

        if ($currentStageOrder === null) {
            return false;
        }

        return ! RekrutmenStage::query()
            ->where('rekrutmen_pipeline_id', $pipelineId)
            ->where('order_column', '>', $currentStageOrder)
            ->exists();
    }

    public function passCurrentStage(string $activityDate, ?string $notes = null, ?int $performedBy = null): RekrutmenStage
    {
        if ($this->isTerminalStatus()) {
            throw new InvalidArgumentException(__('rekrutmen::filament/resources/job-application.workflow_errors.terminal_stage_locked'));
        }

        if (! is_numeric($this->job_posting_id) || ! is_numeric($this->current_stage_id)) {
            throw new InvalidArgumentException(__('rekrutmen::filament/resources/job-application.workflow_errors.invalid_stage'));
        }

        $nextStage = $this->nextStageAfterCurrentStage();

        if (! $nextStage) {
            throw new InvalidArgumentException(__('rekrutmen::filament/resources/job-application.workflow_errors.no_next_stage'));
        }

        static::recordBatchActivity(
            (int) $this->job_posting_id,
            (int) $this->current_stage_id,
            $activityDate,
            [[
                'job_application_id' => (int) $this->getKey(),
                'result'             => ActivityEntryResult::PASSED->value,
                'notes'              => $notes,
            ]],
            $performedBy,
        );

        $this->refresh();

        return $nextStage;
    }

    public function markAsHired(?string $notes = null, ?int $performedBy = null, ?string $activityDate = null): void
    {
        $this->assertDecisionNotesProvided($notes);

        if (! $this->canMarkAsHired()) {
            throw new InvalidArgumentException(__('rekrutmen::filament/resources/job-application.workflow_errors.terminal_stage_locked'));
        }

        $this->changeStatus(
            JobApplicationStatus::HIRED,
            $notes,
            $performedBy,
            $this->resolveTerminalStageIdForStatus(JobApplicationStatus::HIRED),
            ActivityEntryResult::ACCEPTED,
            $activityDate ?? now()->toDateString(),
            __('rekrutmen::filament/resources/job-application.table.actions.mark_hired'),
        );
    }

    public function markAsRejected(?string $notes = null, ?int $performedBy = null, ?string $activityDate = null): void
    {
        $this->assertDecisionNotesProvided($notes);

        $this->changeStatus(
            JobApplicationStatus::REJECTED,
            $notes,
            $performedBy,
            null,
            ActivityEntryResult::REJECTED,
            $activityDate ?? now()->toDateString(),
            __('rekrutmen::filament/resources/job-application.table.actions.mark_rejected'),
        );
    }

    public function markAsWithdrawn(?string $notes = null, ?int $performedBy = null, ?string $activityDate = null): void
    {
        $this->assertDecisionNotesProvided($notes);

        if (! $this->canMarkAsWithdrawn()) {
            throw new InvalidArgumentException(__('rekrutmen::filament/resources/job-application.workflow_errors.terminal_stage_locked'));
        }

        $this->changeStatus(
            JobApplicationStatus::WITHDRAWN,
            $notes,
            $performedBy,
            null,
            null,
            $activityDate ?? now()->toDateString(),
            __('rekrutmen::filament/resources/job-application.table.actions.mark_withdrawn'),
        );
    }

    public function changeStatus(
        JobApplicationStatus $status,
        ?string $notes = null,
        ?int $performedBy = null,
        ?int $toStageId = null,
        ?ActivityEntryResult $result = null,
        ?string $activityDate = null,
        ?string $activityLabel = null,
    ): void {
        if ($toStageId !== null && ! $this->stageBelongsToCurrentPipeline($toStageId)) {
            $toStageId = null;
        }

        $targetStageId = $toStageId ?? $this->current_stage_id;

        if ($this->status === $status && $targetStageId === $this->current_stage_id) {
            return;
        }

        $currentStageId = $this->current_stage_id;

        $attributes = [
            'status' => $status,
        ];

        if ($targetStageId !== $this->current_stage_id) {
            $attributes['current_stage_id'] = $targetStageId;
            $attributes['position'] = $this->nextPositionForStage($targetStageId);
        }

        $this->update($attributes);

        $this->recordHistory(
            $currentStageId,
            $targetStageId,
            $status,
            $notes,
            $performedBy,
            $result,
            $activityDate,
            $activityLabel,
        );
    }

    public function ensureInitialHistory(?string $notes = null, ?int $performedBy = null): void
    {
        $status = $this->status ?? JobApplicationStatus::IN_PROGRESS;

        if ($this->current_stage_id === null || $this->histories()->exists() || $status !== JobApplicationStatus::IN_PROGRESS) {
            return;
        }

        $this->recordHistory(
            null,
            $this->current_stage_id,
            $status,
            $notes ?? __('rekrutmen::filament/resources/job-application.workflow_notes.submitted'),
            $performedBy,
        );
    }

    public static function resumeDisk(): string
    {
        return app(RekrutmenStorage::class)->disk();
    }

    public function setResumeDiskAttribute(mixed $disk): void
    {
        $this->attributes['resume_disk'] = $this->normalizeAttachmentDisk($disk);
        $this->explicitAttachmentDisks['resume_disk'] = true;
    }

    public function setPhotoDiskAttribute(mixed $disk): void
    {
        $this->attributes['photo_disk'] = $this->normalizeAttachmentDisk($disk);
        $this->explicitAttachmentDisks['photo_disk'] = true;
    }

    /**
     * Record a batch activity (e.g., interview, screening) for multiple candidates.
     *
     * This is the single entry point for HR to record batch activities.
     * It creates JobApplicationHistory entries AND moves candidates to the next stage
     * or rejects them based on the result.
     *
     * @param  array{job_application_id: int, result: string, notes?: string}[]  $entries
     * @return string The activity_group_id
     */
    public static function recordBatchActivity(
        int $jobPostingId,
        int $stageId,
        string $activityDate,
        array $entries,
        ?int $performedBy,
    ): string {
        $activityGroupId = (string) Str::uuid();

        $stage = RekrutmenStage::query()
            ->whereKey($stageId)
            ->whereHas('pipeline.jobPostings', fn (Builder $query) => $query->whereKey($jobPostingId))
            ->first();

        if (! $stage) {
            throw new InvalidArgumentException(__('rekrutmen::filament/resources/activity-log.errors.invalid_stage'));
        }

        $applicationIds = collect($entries)
            ->pluck('job_application_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $applications = static::query()
            ->whereIn('id', $applicationIds)
            ->where('job_posting_id', $jobPostingId)
            ->with('jobPosting')
            ->get()
            ->keyBy('id');

        if ($applications->count() !== $applicationIds->count()) {
            throw new InvalidArgumentException(__('rekrutmen::filament/resources/activity-log.errors.invalid_candidates'));
        }

        $activityType = $stage->activityKey();
        $activityTitle = self::generateBatchActivityTitle($stage->activityLabel(), $activityDate);

        $nextStageId = RekrutmenStage::query()
            ->where('rekrutmen_pipeline_id', $stage->rekrutmen_pipeline_id)
            ->where('order_column', '>', $stage->order_column)
            ->orderBy('order_column')
            ->value('id');

        $nextStageId = is_numeric($nextStageId) ? (int) $nextStageId : null;

        DB::transaction(function () use ($activityGroupId, $stageId, $nextStageId, $activityType, $activityDate, $activityTitle, $entries, $performedBy, $applications): void {
            foreach ($entries as $entry) {
                $application = $applications->get((int) $entry['job_application_id']);

                if (! $application) {
                    continue;
                }

                if ($application->isTerminalStatus()) {
                    continue;
                }

                if ($application->current_stage_id !== $stageId) {
                    throw new InvalidArgumentException(__('rekrutmen::filament/resources/activity-log.errors.invalid_candidates'));
                }

                $fromStageId = $application->current_stage_id;
                $resultValue = $entry['result'] ?? null;
                $result = $resultValue instanceof ActivityEntryResult
                    ? $resultValue
                    : ActivityEntryResult::tryFrom($resultValue) ?? ActivityEntryResult::PENDING;

                if (! $result->isActivityOutcome()) {
                    throw new InvalidArgumentException(__('rekrutmen::filament/resources/activity-log.errors.invalid_result'));
                }

                $entryNotes = $entry['notes'] ?? null;
                $historyStatus = match ($result) {
                    ActivityEntryResult::FAILED => JobApplicationStatus::REJECTED,
                    default                     => $application->status ?? JobApplicationStatus::IN_PROGRESS,
                };
                $toStageId = match ($result) {
                    ActivityEntryResult::PASSED => $nextStageId ?? $stageId,
                    default                     => $stageId,
                };

                if ($result === ActivityEntryResult::FAILED) {
                    $application->assertDecisionNotesProvided($entryNotes);
                }

                $application->histories()->create([
                    'from_stage_id'     => $fromStageId,
                    'to_stage_id'       => $toStageId,
                    'activity_type'     => $activityType,
                    'activity_date'     => $activityDate,
                    'result'            => $result,
                    'activity_title'    => $activityTitle,
                    'activity_group_id' => $activityGroupId,
                    'status'            => $historyStatus,
                    'notes'             => $entryNotes,
                    'performed_by'      => $performedBy,
                ]);

                match ($result) {
                    ActivityEntryResult::PASSED  => $application->advanceToStageWithoutHistory($toStageId),
                    ActivityEntryResult::FAILED  => $application->update(['status' => JobApplicationStatus::REJECTED]),
                    ActivityEntryResult::PENDING => null,
                };
            }
        });

        return $activityGroupId;
    }

    public static function generateBatchActivityTitle(
        string $stageName,
        string $activityDate,
    ): string {
        $formattedDate = Carbon::parse($activityDate)->translatedFormat('d M Y');

        return sprintf('%s (%s)', $stageName, $formattedDate);
    }

    /**
     * Move candidate to the next stage in the pipeline after the given stage.
     * If the candidate is already at or past the given stage, no action is taken.
     */
    public function transitionToNextStage(int $completedStageId, ?string $notes = null, ?int $performedBy = null): void
    {
        if (! $this->jobPosting?->rekrutmen_pipeline_id) {
            return;
        }

        $currentStageOrder = RekrutmenStage::query()
            ->whereKey($completedStageId)
            ->value('order_column');

        if ($currentStageOrder === null) {
            return;
        }

        $nextStage = RekrutmenStage::query()
            ->where('rekrutmen_pipeline_id', $this->jobPosting->rekrutmen_pipeline_id)
            ->where('order_column', '>', $currentStageOrder)
            ->orderBy('order_column')
            ->first();

        if ($nextStage) {
            $this->transitionToStage($nextStage->id, $notes ?? __('rekrutmen::filament/resources/job-application.workflow_notes.stage_changed'), $performedBy);
        }
    }

    protected function advanceToStageWithoutHistory(int $toStageId): void
    {
        if ($toStageId === $this->current_stage_id) {
            return;
        }

        $this->update([
            'current_stage_id' => $toStageId,
            'position'         => $this->nextPositionForStage($toStageId),
        ]);
    }

    protected function normalizeTransactionalInput(): void
    {
        $this->full_name = $this->normalizeUppercaseText($this->full_name);
        $this->email = $this->normalizeEmail($this->email);
        $this->address_ktp = $this->normalizeUppercaseText($this->address_ktp);
        $this->address_domicile = $this->normalizeUppercaseText($this->address_domicile);
        $this->whatsapp_number = $this->normalizePhoneNumber($this->whatsapp_number);
        $this->active_phone = $this->normalizePhoneNumber($this->active_phone);
        $this->emergency_contact_name = $this->normalizeUppercaseText($this->emergency_contact_name);
        $this->emergency_contact_relation = $this->normalizeUppercaseText($this->emergency_contact_relation);
        $this->emergency_contact_phone = $this->normalizePhoneNumber($this->emergency_contact_phone);
    }

    protected function syncActiveEmail(): void
    {
        if ($this->deleted_at) {
            $this->active_email = null;

            return;
        }

        if (! is_string($this->email) || $this->email === '') {
            $this->active_email = null;

            return;
        }

        if ($this->shouldTreatAsLegacyDuplicateRecord()) {
            $this->active_email = $this->shouldClaimActiveEmailForLegacyRecord()
                ? $this->email
                : null;

            return;
        }

        $this->active_email = $this->email;
    }

    protected function syncActiveWhatsapp(): void
    {
        if ($this->deleted_at) {
            $this->active_whatsapp = null;

            return;
        }

        if (! is_string($this->whatsapp_number) || $this->whatsapp_number === '') {
            $this->active_whatsapp = null;

            return;
        }

        if ($this->shouldTreatAsLegacyDuplicateWhatsappRecord()) {
            $this->active_whatsapp = $this->shouldClaimActiveWhatsappForLegacyRecord()
                ? $this->whatsapp_number
                : null;

            return;
        }

        $this->active_whatsapp = $this->whatsapp_number;
    }

    protected function snapshotOriginalActiveEmailOwnership(): void
    {
        if (! $this->exists) {
            $this->originalActiveEmailOwnership = null;

            return;
        }

        $originalActiveEmail = $this->normalizeEmail($this->getRawOriginal('active_email'));
        $originalJobPostingId = $this->getRawOriginal('job_posting_id');

        if (! is_string($originalActiveEmail) || $originalActiveEmail === '' || ! is_numeric($originalJobPostingId)) {
            $this->originalActiveEmailOwnership = null;

            return;
        }

        $this->originalActiveEmailOwnership = [
            'job_posting_id' => (int) $originalJobPostingId,
            'active_email'   => $originalActiveEmail,
        ];
    }

    protected function snapshotOriginalActiveWhatsappOwnership(): void
    {
        if (! $this->exists) {
            $this->originalActiveWhatsappOwnership = null;

            return;
        }

        $originalActiveWhatsapp = $this->normalizePhoneNumber($this->getRawOriginal('active_whatsapp'));
        $originalJobPostingId = $this->getRawOriginal('job_posting_id');

        if (! is_string($originalActiveWhatsapp) || $originalActiveWhatsapp === '' || ! is_numeric($originalJobPostingId)) {
            $this->originalActiveWhatsappOwnership = null;

            return;
        }

        $this->originalActiveWhatsappOwnership = [
            'job_posting_id'  => (int) $originalJobPostingId,
            'active_whatsapp' => $originalActiveWhatsapp,
        ];
    }

    protected function shouldTreatAsLegacyDuplicateRecord(): bool
    {
        return $this->exists
            && $this->getRawOriginal('active_email') === null
            && $this->normalizeEmail($this->getRawOriginal('email')) === $this->email
            && ! (
                $this->getRawOriginal('deleted_at') !== null
                && $this->deleted_at === null
            );
    }

    protected function shouldClaimActiveEmailForLegacyRecord(): bool
    {
        if (! $this->exists || ! is_numeric($this->getKey())) {
            return false;
        }

        $peerQuery = $this->matchingActiveEmailPeerQuery();

        if ((clone $peerQuery)
            ->where('active_email', $this->email)
            ->whereKeyNot($this->getKey())
            ->exists()) {
            return false;
        }

        $canonicalPeerId = (clone $peerQuery)
            ->orderBy('id')
            ->value('id');

        return is_numeric($canonicalPeerId)
            && (int) $canonicalPeerId === (int) $this->getKey();
    }

    protected function shouldTreatAsLegacyDuplicateWhatsappRecord(): bool
    {
        return $this->exists
            && $this->getRawOriginal('active_whatsapp') === null
            && $this->normalizePhoneNumber($this->getRawOriginal('whatsapp_number')) === $this->whatsapp_number
            && ! (
                $this->getRawOriginal('deleted_at') !== null
                && $this->deleted_at === null
            );
    }

    protected function shouldClaimActiveWhatsappForLegacyRecord(): bool
    {
        if (! $this->exists || ! is_numeric($this->getKey())) {
            return false;
        }

        $peerQuery = $this->matchingActiveWhatsappPeerQuery();

        if ((clone $peerQuery)
            ->where('active_whatsapp', $this->whatsapp_number)
            ->whereKeyNot($this->getKey())
            ->exists()) {
            return false;
        }

        $canonicalPeerId = (clone $peerQuery)
            ->orderBy('id')
            ->value('id');

        return is_numeric($canonicalPeerId)
            && (int) $canonicalPeerId === (int) $this->getKey();
    }

    protected function matchingActiveEmailPeerQuery(?string $normalizedEmail = null, mixed $jobPostingId = null): Builder
    {
        $normalizedEmail ??= $this->email;
        $jobPostingId ??= $this->job_posting_id;

        $query = static::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at');

        if (! is_numeric($jobPostingId) || ! is_string($normalizedEmail) || $normalizedEmail === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('job_posting_id', (int) $jobPostingId)
            ->whereRaw('LOWER(TRIM(email)) = ?', [$normalizedEmail]);
    }

    protected function matchingActiveWhatsappPeerQuery(?string $normalizedWhatsapp = null, mixed $jobPostingId = null): Builder
    {
        $normalizedWhatsapp ??= $this->whatsapp_number;
        $jobPostingId ??= $this->job_posting_id;

        $query = static::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at');

        if (! is_numeric($jobPostingId) || ! is_string($normalizedWhatsapp) || $normalizedWhatsapp === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('job_posting_id', (int) $jobPostingId)
            ->where('whatsapp_number', $normalizedWhatsapp);
    }

    protected function reassignOriginalActiveEmailIfNeeded(): void
    {
        if ($this->originalActiveEmailOwnership === null) {
            return;
        }

        $originalActiveEmailOwnership = $this->originalActiveEmailOwnership;
        $this->originalActiveEmailOwnership = null;

        if (
            $originalActiveEmailOwnership['job_posting_id'] === (int) $this->job_posting_id
            && $originalActiveEmailOwnership['active_email'] === $this->active_email
        ) {
            return;
        }

        $this->reassignActiveEmailToCanonicalPeer(
            $originalActiveEmailOwnership['active_email'],
            $originalActiveEmailOwnership['job_posting_id'],
        );
    }

    protected function reassignOriginalActiveWhatsappIfNeeded(): void
    {
        if ($this->originalActiveWhatsappOwnership === null) {
            return;
        }

        $originalActiveWhatsappOwnership = $this->originalActiveWhatsappOwnership;
        $this->originalActiveWhatsappOwnership = null;

        if (
            $originalActiveWhatsappOwnership['job_posting_id'] === (int) $this->job_posting_id
            && $originalActiveWhatsappOwnership['active_whatsapp'] === $this->active_whatsapp
        ) {
            return;
        }

        $this->reassignActiveWhatsappToCanonicalPeer(
            $originalActiveWhatsappOwnership['active_whatsapp'],
            $originalActiveWhatsappOwnership['job_posting_id'],
        );
    }

    protected function reassignActiveEmailToCanonicalPeer(?string $normalizedEmail = null, mixed $jobPostingId = null): void
    {
        $normalizedEmail ??= $this->email;
        $jobPostingId ??= $this->job_posting_id;

        if (! is_string($normalizedEmail) || $normalizedEmail === '') {
            return;
        }

        $peerQuery = $this->matchingActiveEmailPeerQuery($normalizedEmail, $jobPostingId);

        if ((clone $peerQuery)->where('active_email', $normalizedEmail)->exists()) {
            return;
        }

        $canonicalPeerId = (clone $peerQuery)
            ->orderBy('id')
            ->value('id');

        if (! is_numeric($canonicalPeerId)) {
            return;
        }

        static::query()
            ->withoutGlobalScopes()
            ->whereKey((int) $canonicalPeerId)
            ->update(['active_email' => $normalizedEmail]);
    }

    protected function reassignActiveWhatsappToCanonicalPeer(?string $normalizedWhatsapp = null, mixed $jobPostingId = null): void
    {
        $normalizedWhatsapp ??= $this->whatsapp_number;
        $jobPostingId ??= $this->job_posting_id;

        if (! is_string($normalizedWhatsapp) || $normalizedWhatsapp === '') {
            return;
        }

        $peerQuery = $this->matchingActiveWhatsappPeerQuery($normalizedWhatsapp, $jobPostingId);

        if ((clone $peerQuery)->where('active_whatsapp', $normalizedWhatsapp)->exists()) {
            return;
        }

        $canonicalPeerId = (clone $peerQuery)
            ->orderBy('id')
            ->value('id');

        if (! is_numeric($canonicalPeerId)) {
            return;
        }

        static::query()
            ->withoutGlobalScopes()
            ->whereKey((int) $canonicalPeerId)
            ->update(['active_whatsapp' => $normalizedWhatsapp]);
    }

    protected function assertActiveEmailIsUniqueForJobPosting(): void
    {
        if (! is_numeric($this->job_posting_id) || ! is_string($this->active_email) || $this->active_email === '') {
            return;
        }

        $duplicateExists = static::query()
            ->withoutGlobalScopes()
            ->where('job_posting_id', (int) $this->job_posting_id)
            ->where('active_email', $this->active_email)
            ->whereKeyNot($this->getKey())
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'email' => __('rekrutmen::api/career.validation.messages.email.unique'),
            ]);
        }
    }

    protected function assertActiveWhatsappIsUniqueForJobPosting(): void
    {
        if (! is_numeric($this->job_posting_id) || ! is_string($this->active_whatsapp) || $this->active_whatsapp === '') {
            return;
        }

        $duplicateExists = static::query()
            ->withoutGlobalScopes()
            ->where('job_posting_id', (int) $this->job_posting_id)
            ->where('active_whatsapp', $this->active_whatsapp)
            ->whereKeyNot($this->getKey())
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('rekrutmen::api/career.validation.messages.whatsapp_number.unique'),
            ]);
        }
    }

    protected function ensureBoardPosition(): void
    {
        if ($this->current_stage_id === null) {
            $this->position = null;

            return;
        }

        if ($this->exists && $this->isDirty('current_stage_id') && ! $this->isDirty('position')) {
            $this->position = $this->nextPositionForStage($this->current_stage_id);

            return;
        }

        if (! $this->exists && blank($this->position)) {
            $this->position = $this->nextPositionForStage($this->current_stage_id);
        }
    }

    protected function ensurePipelineStageIntegrity(): void
    {
        $normalizedStageId = $this->resolveNormalizedStageId();

        if ($normalizedStageId === $this->current_stage_id) {
            return;
        }

        $this->current_stage_id = $normalizedStageId;
    }

    protected function resolveNormalizedStageId(): ?int
    {
        $pipelineId = $this->resolvePipelineIdForCurrentJobPosting();

        if (! $pipelineId) {
            return null;
        }

        $currentStageId = is_numeric($this->current_stage_id)
            ? (int) $this->current_stage_id
            : null;

        if ($this->status === JobApplicationStatus::HIRED) {
            $hiredStageId = $this->resolveTerminalStageIdForStatus(JobApplicationStatus::HIRED);

            if ($hiredStageId !== null) {
                return $hiredStageId;
            }
        }

        if ($currentStageId !== null && $this->stageBelongsToCurrentPipeline($currentStageId)) {
            return $currentStageId;
        }

        return self::resolveInitialStageIdForJobPostingId($this->job_posting_id);
    }

    protected function resolvePipelineIdForCurrentJobPosting(): ?int
    {
        if (! is_numeric($this->job_posting_id)) {
            return null;
        }

        $pipelineId = JobPosting::query()
            ->whereKey((int) $this->job_posting_id)
            ->value('rekrutmen_pipeline_id');

        return is_numeric($pipelineId) ? (int) $pipelineId : null;
    }

    protected function snapshotOriginalAttachmentPaths(): void
    {
        if (! $this->exists) {
            $this->originalAttachmentPaths = [];

            return;
        }

        foreach (['resume_path', 'photo_path'] as $attribute) {
            $path = $this->normalizeManagedFilePath($this->getRawOriginal($attribute));
            $disk = $this->normalizeAttachmentDisk($this->getRawOriginal($this->attachmentDiskAttribute($attribute)));

            $this->originalAttachmentPaths[$attribute] = [
                'path' => $path,
                'disk' => $disk ?? ($path ? $this->resolveManagedFileDisk($path) : null),
            ];
        }
    }

    protected function prepareManagedAttachmentPathForPersistence(string $attribute, string $directory, string $prefix): void
    {
        $path = $this->normalizeManagedFilePath($this->{$attribute});
        $diskAttribute = $this->attachmentDiskAttribute($attribute);
        $disk = $this->normalizeAttachmentDisk($this->{$diskAttribute});
        $this->attributes[$attribute] = $path;

        if (! $path || filter_var($path, FILTER_VALIDATE_URL)) {
            $this->attributes[$diskAttribute] = null;

            return;
        }

        if (! $this->exists || $this->isDirty($attribute)) {
            if (! $disk || (! isset($this->explicitAttachmentDisks[$diskAttribute]) && ! $this->isDirty($diskAttribute))) {
                $disk = self::resumeDisk();
            }
        } elseif (! $disk) {
            $disk = $this->originalAttachmentPaths[$attribute]['disk'] ?? null;
        }

        $this->attributes[$diskAttribute] = $disk;

        if (! $this->exists || ! $disk) {
            return;
        }

        $renamedPath = $this->renameManagedFile(
            $path,
            $directory,
            $prefix.'-'.$this->getKey(),
            $disk,
            $this->originalAttachmentPaths[$attribute] ?? null,
        );

        if ($renamedPath !== $path) {
            $this->{$attribute} = $renamedPath;
        }
    }

    protected function syncManagedAttachmentPath(string $attribute, string $directory, string $prefix): void
    {
        $path = $this->normalizeManagedFilePath($this->{$attribute});

        if (! $path || ! $this->exists) {
            return;
        }

        $renamedPath = $this->renameManagedFile(
            $path,
            $directory,
            $prefix.'-'.$this->getKey(),
            $this->normalizeAttachmentDisk($this->{$this->attachmentDiskAttribute($attribute)}),
            $this->originalAttachmentPaths[$attribute] ?? null,
        );

        if ($renamedPath === $path) {
            return;
        }

        $this->forceFill([
            $attribute => $renamedPath,
        ]);

        $this->saveQuietly();
    }

    protected function deleteRemovedAttachmentFile(string $attribute): void
    {
        if (! array_key_exists($attribute, $this->originalAttachmentPaths)) {
            return;
        }

        $original = $this->originalAttachmentPaths[$attribute];
        $currentPath = $this->normalizeManagedFilePath($this->{$attribute});
        $currentDisk = $this->normalizeAttachmentDisk($this->{$this->attachmentDiskAttribute($attribute)});

        unset($this->originalAttachmentPaths[$attribute]);

        if (! $original['disk'] || ($original['path'] === $currentPath && $original['disk'] === $currentDisk)) {
            return;
        }

        $this->deleteManagedFile($original['path'], $original['disk']);
    }

    protected function attachmentDiskAttribute(string $pathAttribute): string
    {
        return str_replace('_path', '_disk', $pathAttribute);
    }

    protected function normalizeAttachmentDisk(mixed $disk): ?string
    {
        return is_string($disk) && trim($disk) !== '' ? trim($disk) : null;
    }

    protected function normalizeManagedFilePath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $relativePath = ltrim(trim($path), '/');

        return $relativePath !== '' ? $relativePath : null;
    }

    protected function normalizeUppercaseText(mixed $value): ?string
    {
        $normalized = $this->normalizePlainText($value);

        if ($normalized === null) {
            return null;
        }

        return mb_strtoupper($normalized);
    }

    protected function normalizePlainText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        return $normalized;
    }

    protected function normalizeEmail(mixed $value): ?string
    {
        $normalized = $this->normalizePlainText($value);

        if ($normalized === null) {
            return null;
        }

        return Str::lower($normalized);
    }

    protected function normalizePhoneNumber(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $normalized);

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '62')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        return $digits;
    }

    public function emergencyContactSummary(): ?string
    {
        $segments = array_values(array_filter([
            $this->emergency_contact_name,
            $this->emergency_contact_relation,
            $this->emergency_contact_phone,
        ], static fn (mixed $segment): bool => is_string($segment) && $segment !== ''));

        if ($segments === []) {
            return null;
        }

        return implode(' - ', $segments);
    }

    protected function nextPositionForStage(?int $stageId): string
    {
        if ($stageId === null || ! is_numeric($this->job_posting_id)) {
            return DecimalPosition::forEmptyColumn();
        }

        $lastPosition = static::query()
            ->where('job_posting_id', (int) $this->job_posting_id)
            ->where('current_stage_id', $stageId)
            ->whereKeyNot($this->getKey())
            ->whereNotNull('position')
            ->orderByDesc('position')
            ->value('position');

        if (! is_string($lastPosition) || $lastPosition === '') {
            return DecimalPosition::forEmptyColumn();
        }

        return DecimalPosition::after(DecimalPosition::normalize($lastPosition));
    }

    /**
     * @param  array{path: ?string, disk: ?string}|null  $original
     */
    protected function renameManagedFile(string $path, string $directory, string $prefix, ?string $storedDisk, ?array $original = null): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $canonicalDirectory = trim($directory, '/');
        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));
        $expectedPrefix = $canonicalDirectory.'/'.$prefix.'-';

        if (str_starts_with($path, $expectedPrefix)) {
            return $path;
        }

        $targetPath = $this->buildManagedResumePath($canonicalDirectory, $extension);

        if ($targetPath === $path) {
            return $path;
        }

        $disk = $this->resolveManagedFileDisk($path, $storedDisk);

        if (! $disk) {
            return $path;
        }

        try {
            if (Storage::disk($disk)->exists($targetPath)
                && ($original === null || $original['path'] !== $targetPath || $original['disk'] !== $disk)) {
                $targetPath = $canonicalDirectory.'/'.pathinfo($targetPath, PATHINFO_FILENAME).'-'.Str::uuid()
                    .($extension !== '' ? '.'.$extension : '');
            }

            return Storage::disk($disk)->move($path, $targetPath) ? $targetPath : $path;
        } catch (Throwable) {
            return $path;
        }
    }

    protected function buildManagedResumePath(string $directory, string $extension): string
    {
        $positionSlug = $this->resolveResumePositionSlug();
        $prefix = trim(pathinfo($directory, PATHINFO_BASENAME)) === 'photos' ? 'PHOTO' : 'CV';
        $fileName = $prefix.'-'.$this->getKey().'-'.$positionSlug;

        if ($extension !== '') {
            $fileName .= '.'.$extension;
        }

        return trim($directory, '/').'/'.$fileName;
    }

    protected function resolveResumePositionSlug(): string
    {
        $position = $this->jobPosting?->title;
        $slug = Str::slug((string) $position);

        if ($slug === '') {
            return __('rekrutmen::filament/resources/job-application.generated.unknown_position');
        }

        return Str::limit($slug, 80, '');
    }

    protected function deleteManagedFile(?string $path, ?string $storedDisk = null): void
    {
        if (! $path || filter_var($path, FILTER_VALIDATE_URL)) {
            return;
        }

        $disk = $this->resolveManagedFileDisk($path, $storedDisk);

        if (! $disk) {
            return;
        }

        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            return;
        }
    }

    protected function resolveManagedFileDisk(string $path, ?string $storedDisk = null): ?string
    {
        return app(RekrutmenStorage::class)->resolveDisk($path, $storedDisk);
    }

    public function resolveAttachmentPath(string $attachment): ?string
    {
        return match ($attachment) {
            'resume' => $this->resume_path,
            'photo'  => $this->photo_path,
            default  => null,
        };
    }

    public function resolveAttachmentDisk(string $attachment): ?string
    {
        $path = $this->resolveAttachmentPath($attachment);

        if (! $path) {
            return null;
        }

        $pathAttribute = $attachment.'_path';
        $diskAttribute = $attachment.'_disk';
        $storedDisk = $this->normalizeAttachmentDisk($this->{$diskAttribute});
        $disk = $this->resolveManagedFileDisk($path, $storedDisk);

        if ($disk && $storedDisk === null && $this->exists
            && ! $this->isDirty($pathAttribute) && ! $this->isDirty($diskAttribute)) {
            $persisted = static::query()->withoutGlobalScopes()->whereKey($this->getKey())
                ->where($pathAttribute, $this->getRawOriginal($pathAttribute))
                ->whereNull($diskAttribute)
                ->update([$diskAttribute => $disk]);

            if ($persisted) {
                $this->attributes[$diskAttribute] = $disk;
                $this->syncOriginalAttribute($diskAttribute);
            }
        }

        return $disk;
    }

    protected function recordHistory(
        ?int $fromStageId,
        ?int $toStageId,
        JobApplicationStatus $status,
        ?string $notes,
        ?int $performedBy,
        ?ActivityEntryResult $result = null,
        ?string $activityDate = null,
        ?string $activityLabel = null,
    ): void {
        $this->histories()->create([
            'from_stage_id'  => $fromStageId,
            'to_stage_id'    => $toStageId,
            'activity_type'  => $activityLabel !== null ? Str::slug($activityLabel, '_') : null,
            'activity_date'  => $activityDate,
            'result'         => $result,
            'activity_title' => $activityLabel !== null && $activityDate !== null
                ? self::generateBatchActivityTitle($activityLabel, $activityDate)
                : null,
            'status'       => $status,
            'notes'        => $notes,
            'performed_by' => $performedBy,
        ]);
    }

    protected function assertDecisionNotesProvided(?string $notes): void
    {
        if (! is_string($notes) || trim($notes) === '') {
            throw new InvalidArgumentException(
                __('rekrutmen::filament/resources/job-application.workflow_errors.decision_note_required')
            );
        }
    }

    protected function resolveTerminalStageIdForStatus(JobApplicationStatus $status): ?int
    {
        $pipelineId = $this->resolvePipelineIdForCurrentJobPosting();

        if ($status !== JobApplicationStatus::HIRED || ! $pipelineId) {
            return null;
        }

        return RekrutmenStage::query()
            ->where('rekrutmen_pipeline_id', $pipelineId)
            ->whereRaw('LOWER(name) = ?', ['hired'])
            ->orderByDesc('order_column')
            ->value('id');
    }
}
