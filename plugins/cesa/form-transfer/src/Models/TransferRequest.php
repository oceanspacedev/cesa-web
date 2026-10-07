<?php

namespace Cesa\FormTransfer\Models;

use Cesa\FormTransfer\Database\Factories\TransferRequestFactory;
use Cesa\FormTransfer\Enums\TransferRequestApprovalStatus;
use Cesa\FormTransfer\Enums\TransferRequestRealizationStatus;
use Cesa\FormTransfer\Enums\TransferRequestSubmissionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Security\Models\Scopes\UserPermissionScope;
use Webkul\Security\Models\User;
use Webkul\Security\Traits\HasNullableCreator;
use Webkul\Support\Models\Company;

class TransferRequest extends Model
{
    use HasChatter, HasFactory, HasNullableCreator, SoftDeletes;

    protected $table = 'form_transfer_requests';

    /**
     * @var array<string, array<int, string>>
     */
    protected array $originalAttachmentSnapshot = [];

    protected $fillable = [
        'uid',
        'submission_status',
        'approval_status',
        'realization_status',
        'status_response_id',
        'form_transfer_id',
        'company_id',
        'user_id',
        'creator_id',
        'requester_name',
        'division_name',
        'division_id',
        'email',
        'account_number',
        'account_name',
        'bank_id',
        'transfer_amount',
        'realized_amount',
        'purpose',
        'reference_note',
        'invoice_path',
        'account_attachment_path',
        'realized_at',
        'realization_proof_path',
        'realization_notes',
        'approval_workflow_id',
        'approvals',
    ];

    protected static function booted(): void
    {
        if (Auth::check()) {
            static::addGlobalScope(new UserPermissionScope('user', 'company_id'));
        }

        static::creating(function (TransferRequest $request): void {
            if (empty($request->form_transfer_id)) {
                throw new RuntimeException('Form transfer must be specified for a transfer request.');
            }

            $formTransfer = FormTransfer::query()->find($request->form_transfer_id);

            if (! $formTransfer) {
                throw new RuntimeException('Invalid form transfer selected for request.');
            }

            $authenticatedUser = Auth::user();

            if (empty($request->uid)) {
                $request->uid = $formTransfer->generateNextRequestUid();
            }

            if (empty($request->company_id)) {
                $request->company_id = $formTransfer->company_id;
            }

            if ($authenticatedUser && empty($request->user_id)) {
                $request->user_id = $authenticatedUser->getAuthIdentifier();
            }

            if (empty($request->user_id) && $formTransfer->company?->createdBy) {
                $request->user_id = $formTransfer->company->createdBy->getKey();
            }

            if ($authenticatedUser && empty($request->creator_id)) {
                $request->creator_id = $authenticatedUser->getAuthIdentifier();
            }

            if (empty($request->creator_id) && $request->user_id) {
                $request->creator_id = $request->user_id;
            }

            if (empty($request->status_response_id)) {
                $request->status_response_id = (string) Str::uuid();
            }

            if ($request->division_id && empty($request->division_name)) {
                $request->division_name = TransferDivision::query()
                    ->withTrashed()
                    ->find($request->division_id)?->name;
            }

            if (empty($request->submission_status)) {
                $request->submission_status = TransferRequestSubmissionStatus::BARU;
            }

            if (empty($request->approval_status)) {
                $request->approval_status = TransferRequestApprovalStatus::PENDING;
            }

            if (empty($request->realization_status)) {
                $request->realization_status = TransferRequestRealizationStatus::PENDING;
            }

            if ($request->realized_amount === null || $request->realized_amount === '') {
                $request->realized_amount = 0;
            }
        });

        static::saving(function (TransferRequest $request): void {
            if (is_string($request->email)) {
                $request->email = trim($request->email);
            }

            if (blank($request->email)) {
                throw ValidationException::withMessages([
                    'email' => __('validation.required', [
                        'attribute' => Str::lower(__('form-transfer::filament/resources/transfer-request/fields.email')),
                    ]),
                ]);
            }

            $request->snapshotOriginalAttachments();

            $approvalStatus = $request->approval_status instanceof TransferRequestApprovalStatus
                ? $request->approval_status
                : TransferRequestApprovalStatus::tryFrom((string) $request->approval_status);

            if ($approvalStatus !== TransferRequestApprovalStatus::REJECTED) {
                return;
            }

            $realizationStatus = $request->realization_status instanceof TransferRequestRealizationStatus
                ? $request->realization_status
                : TransferRequestRealizationStatus::tryFrom((string) $request->realization_status);

            if ($realizationStatus !== TransferRequestRealizationStatus::CANCELLED) {
                $request->realization_status = TransferRequestRealizationStatus::CANCELLED;
            }
        });

        static::saved(function (TransferRequest $request): void {
            $request->syncAttachmentStorageNames();
            $request->deleteAttachmentsRemovedFromCurrentState();
        });

        static::deleting(function (TransferRequest $request): void {
            if ($request->isForceDeleting()) {
                $request->deleteRealizationProofFiles();
            }
        });

        static::forceDeleted(function (TransferRequest $request): void {
            $request->deleteCurrentAttachmentFiles();
        });
    }

    protected function casts(): array
    {
        return [
            'transfer_amount'    => 'decimal:2',
            'realized_amount'    => 'decimal:2',
            'realized_at'        => 'date',
            'approvals'          => 'array',
            'submission_status'  => TransferRequestSubmissionStatus::class,
            'approval_status'    => TransferRequestApprovalStatus::class,
            'realization_status' => TransferRequestRealizationStatus::class,
            'realization_notes'  => 'string',
        ];
    }

    public function setTransferAmountAttribute(mixed $value): void
    {
        $this->attributes['transfer_amount'] = static::normalizeTransferAmount($value);
    }

    public static function formatTransferAmountForForm(mixed $value): ?string
    {
        $normalized = static::normalizeTransferAmount($value);

        if ($normalized === null || $normalized === '') {
            return null;
        }

        if (! is_numeric($normalized)) {
            return (string) $value;
        }

        $amount = (float) $normalized;
        $decimalPlaces = floor($amount) === $amount ? 0 : 2;

        return number_format($amount, $decimalPlaces, ',', '.');
    }

    public static function normalizeTransferAmount(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $normalized = static::normalizeLocalizedNumber($value);

        return is_numeric($normalized)
            ? number_format((float) $normalized, 2, '.', '')
            : $normalized;
    }

    /**
     * @return array<int, string>
     */
    public static function normalizeAttachmentPaths(mixed $value): array
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return [];
            }

            $value = static::decodeAttachmentPayload($trimmed);

            if (is_string($value)) {
                $value = [$value];
            }
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            Arr::wrap($value),
            fn ($path): bool => is_string($path) && $path !== ''
        ));
    }

    /**
     * Decode attachment payloads that may be encoded more than once.
     */
    protected static function decodeAttachmentPayload(string $value): array|string
    {
        $candidate = $value;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $decoded = json_decode($candidate, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $unescaped = stripcslashes($candidate);

                if ($unescaped === $candidate) {
                    break;
                }

                $decoded = json_decode($unescaped, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    break;
                }
            }

            if (is_array($decoded)) {
                return $decoded;
            }

            if (! is_string($decoded)) {
                break;
            }

            $candidate = trim($decoded);

            if ($candidate === '') {
                return [];
            }
        }

        return trim($candidate, "\"'");
    }

    protected static function encodeAttachmentPaths(mixed $value): ?string
    {
        $paths = static::normalizeAttachmentPaths($value);

        if ($paths === []) {
            return null;
        }

        if (count($paths) === 1) {
            return $paths[0];
        }

        $encoded = json_encode($paths);

        return is_string($encoded) ? $encoded : null;
    }

    /**
     * @return array<int, string>
     */
    public function getInvoicePathAttribute(mixed $value): array
    {
        return static::normalizeAttachmentPaths($value);
    }

    /**
     * @return array<int, string>
     */
    public function getAccountAttachmentPathAttribute(mixed $value): array
    {
        return static::normalizeAttachmentPaths($value);
    }

    public function setInvoicePathAttribute(mixed $value): void
    {
        $this->attributes['invoice_path'] = static::encodeAttachmentPaths($value);
    }

    public function setAccountAttachmentPathAttribute(mixed $value): void
    {
        $this->attributes['account_attachment_path'] = static::encodeAttachmentPaths($value);
    }

    public function syncAttachmentStorageNames(): void
    {
        if (blank($this->uid)) {
            return;
        }

        $updatedAttributes = [];

        foreach ($this->attachmentStorageDirectories() as $attribute => $directory) {
            $renamedPaths = $this->renameAttachmentPaths($attribute, $directory);

            if ($renamedPaths === null) {
                continue;
            }

            $updatedAttributes[$attribute] = static::encodeAttachmentPaths($renamedPaths);
        }

        if ($updatedAttributes === []) {
            return;
        }

        $this->forceFill($updatedAttributes);
        $this->saveQuietly();
    }

    protected function snapshotOriginalAttachments(): void
    {
        if (! $this->exists) {
            $this->originalAttachmentSnapshot = [];

            return;
        }

        $snapshot = [];

        foreach (array_keys($this->attachmentStorageDirectories()) as $attribute) {
            $snapshot[$attribute] = static::normalizeAttachmentPaths($this->getRawOriginal($attribute));
        }

        $this->originalAttachmentSnapshot = $snapshot;
    }

    protected function deleteAttachmentsRemovedFromCurrentState(): void
    {
        if ($this->originalAttachmentSnapshot === []) {
            return;
        }

        $pathsToDelete = [];

        foreach ($this->originalAttachmentSnapshot as $attribute => $originalPaths) {
            $currentPaths = static::normalizeAttachmentPaths($this->getAttribute($attribute));
            $pathsToDelete = [
                ...$pathsToDelete,
                ...array_values(array_diff($originalPaths, $currentPaths)),
            ];
        }

        $this->originalAttachmentSnapshot = [];

        $this->deleteAttachmentFiles($pathsToDelete);
    }

    protected function deleteCurrentAttachmentFiles(): void
    {
        $paths = [];

        foreach (array_keys($this->attachmentStorageDirectories()) as $attribute) {
            $paths = [
                ...$paths,
                ...static::normalizeAttachmentPaths($this->getRawOriginal($attribute) ?? $this->getAttribute($attribute)),
            ];
        }

        $this->deleteAttachmentFiles($paths);
    }

    protected function deleteRealizationProofFiles(): void
    {
        if (! $this->exists) {
            return;
        }

        $paths = $this->realizations()
            ->withTrashed()
            ->pluck('proof_path')
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->all();

        $this->deleteAttachmentFiles($paths);
    }

    /**
     * @return array<string, string>
     */
    protected function attachmentStorageDirectories(): array
    {
        return [
            'invoice_path'            => 'form-transfer/invoices',
            'account_attachment_path' => 'form-transfer/account-attachments',
            'realization_proof_path'  => 'form-transfer/realizations',
        ];
    }

    /**
     * @return array<int, string>|null
     */
    protected function renameAttachmentPaths(string $attribute, string $directory): ?array
    {
        $paths = static::normalizeAttachmentPaths($this->getAttribute($attribute));

        if ($paths === []) {
            return null;
        }

        $renamedPaths = [];
        $hasChanges = false;
        $totalPaths = count($paths);

        foreach ($paths as $index => $path) {
            $renamedPath = $this->renameAttachmentPath($path, $directory, $index, $totalPaths);

            $renamedPaths[] = $renamedPath;
            $hasChanges = $hasChanges || ($renamedPath !== $path);
        }

        return $hasChanges ? $renamedPaths : null;
    }

    protected function renameAttachmentPath(string $path, string $directory, int $index, int $totalPaths): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $relativePath = ltrim($path, '/');

        if ($relativePath === '') {
            return $path;
        }

        $baseName = pathinfo($relativePath, PATHINFO_BASENAME);

        if (str_starts_with($baseName, "{$this->uid}-")) {
            return $relativePath;
        }

        $disk = $this->resolveAttachmentDisk($relativePath);

        if (! $disk) {
            return $path;
        }

        try {
            $targetDirectory = trim(pathinfo($relativePath, PATHINFO_DIRNAME), './');
            $targetDirectory = $targetDirectory !== '' ? $targetDirectory : $directory;
            $targetPath = $this->buildManagedAttachmentPath($targetDirectory, $index, $totalPaths, $relativePath, $disk);

            if ($targetPath === $relativePath) {
                return $relativePath;
            }

            Storage::disk($disk)->move($relativePath, $targetPath);

            return $targetPath;
        } catch (\Throwable $e) {
            logger()->warning('Failed to rename attachment path during sync.', [
                'transfer_request_id' => $this->getKey(),
                'original_path'       => $path,
                'target_directory'    => $directory,
                'disk'                => $disk,
                'error'               => $e->getMessage(),
            ]);

            return $path;
        }
    }

    protected function buildManagedAttachmentPath(
        string $directory,
        int $index,
        int $totalPaths,
        string $currentPath,
        string $disk
    ): string {
        $extension = Str::lower(pathinfo($currentPath, PATHINFO_EXTENSION));
        $pathPrefix = trim($directory, '/').'/';
        $sequenceSuffix = $totalPaths > 1
            ? '-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)
            : '';

        do {
            $fileName = $this->uid.$sequenceSuffix.'-'.Str::lower(Str::random(6));

            if ($extension !== '') {
                $fileName .= '.'.$extension;
            }

            $targetPath = $pathPrefix.$fileName;
        } while (Storage::disk($disk)->exists($targetPath));

        return $targetPath;
    }

    /**
     * @param  array<int, string>  $paths
     */
    protected function deleteAttachmentFiles(array $paths): void
    {
        foreach (array_values(array_unique($paths)) as $path) {
            if (filter_var($path, FILTER_VALIDATE_URL)) {
                continue;
            }

            $relativePath = ltrim($path, '/');

            if ($relativePath === '') {
                continue;
            }

            $disk = $this->resolveAttachmentDisk($relativePath);

            if (! $disk) {
                continue;
            }

            try {
                Storage::disk($disk)->delete($relativePath);
            } catch (\Throwable $e) {
                logger()->warning('Failed to delete attachment file.', [
                    'transfer_request_id' => $this->getKey(),
                    'path'                => $relativePath,
                    'disk'                => $disk,
                    'error'               => $e->getMessage(),
                ]);

                continue;
            }
        }
    }

    protected function resolveAttachmentDisk(string $relativePath): ?string
    {
        $candidateDisks = array_values(array_unique(array_filter([
            config('filament.default_filesystem_disk'),
            config('filesystems.default'),
            'local',
            'public',
            'ftp',
        ], fn (mixed $disk): bool => is_string($disk) && $disk !== '')));

        foreach ($candidateDisks as $disk) {
            if (! config()->has("filesystems.disks.{$disk}")) {
                continue;
            }

            try {
                if (Storage::disk($disk)->exists($relativePath)) {
                    return $disk;
                }
            } catch (\Throwable $e) {
                logger()->debug('Failed to check file existence on disk.', [
                    'disk'  => $disk,
                    'path'  => $relativePath,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
        }

        return null;
    }

    public function formTransfer(): BelongsTo
    {
        return $this->belongsTo(FormTransfer::class)->withTrashed();
    }

    public function scopeApplyPermissionScope(Builder $query): Builder
    {
        $user = filament()->auth()->user();

        if (! $user) {
            return $query;
        }

        $userIds = bouncer()->getAuthorizedUserIds();

        if (empty($userIds)) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($userIds): void {
            $builder
                ->whereIn('creator_id', $userIds)
                ->orWhereIn('user_id', $userIds);

            if (! Schema::hasTable('form_transfer_user_accesses')) {
                return;
            }

            $builder->orWhereHas('formTransfer', function (Builder $formTransferQuery) use ($userIds): void {
                $formTransferQuery
                    ->whereDoesntHave('allowedUsers')
                    ->orWhereHas('allowedUsers', function (Builder $userQuery) use ($userIds): void {
                        $userQuery->whereIn('users.id', $userIds);
                    });
            });
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id')->withTrashed();
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(TransferDivision::class, 'division_id')->withTrashed();
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(TransferBank::class, 'bank_id')->withTrashed();
    }

    /**
     * Get bank information from association
     */
    public function getBankInfo(): ?TransferBank
    {
        if (! $this->bank_id) {
            return null;
        }

        if ($this->relationLoaded('bank')) {
            return $this->getRelationValue('bank');
        }

        return $this->bank()->first();
    }

    /**
     * Get bank display name
     */
    public function getBankDisplayNameAttribute(): ?string
    {
        return $this->getBankInfo()?->display_name;
    }

    /**
     * Get bank name accessor for backwards compatibility
     */
    public function getBankNameAttribute(): ?string
    {
        return $this->getBankInfo()?->name;
    }

    public function approvalWorkflow(): BelongsTo
    {
        return $this->belongsTo(TransferApprovalWorkflow::class, 'approval_workflow_id')->withTrashed();
    }

    public function realizations(): HasMany
    {
        return $this->hasMany(TransferRequestRealization::class)->oldest('realized_at')->oldest('id');
    }

    public function canRecordAdditionalRealization(): bool
    {
        $status = $this->realization_status instanceof TransferRequestRealizationStatus
            ? $this->realization_status
            : TransferRequestRealizationStatus::tryFrom((string) $this->realization_status);

        return in_array($status, [
            TransferRequestRealizationStatus::PENDING,
            TransferRequestRealizationStatus::PARTIAL,
        ], true) && static::amountToCents($this->remaining_realization_amount) > 0;
    }

    /**
     * Approval reminders only apply while both approval and realization are still pending.
     * Once realization has started (partial/done) or been cancelled, do not nag approvers.
     */
    public function needsApprovalReminder(): bool
    {
        $approvalStatus = $this->approval_status instanceof TransferRequestApprovalStatus
            ? $this->approval_status
            : TransferRequestApprovalStatus::tryFrom((string) $this->approval_status);

        $realizationStatus = $this->realization_status instanceof TransferRequestRealizationStatus
            ? $this->realization_status
            : TransferRequestRealizationStatus::tryFrom((string) $this->realization_status);

        return $approvalStatus === TransferRequestApprovalStatus::PENDING
            && $realizationStatus === TransferRequestRealizationStatus::PENDING;
    }

    /**
     * @param  Builder<TransferRequest>  $query
     * @return Builder<TransferRequest>
     */
    public function scopeNeedsApprovalReminder($query)
    {
        return $query
            ->where('approval_status', TransferRequestApprovalStatus::PENDING)
            ->where('realization_status', TransferRequestRealizationStatus::PENDING);
    }

    /**
     * @param  array{amount: mixed, realized_at?: mixed, proof_path?: mixed, notes?: mixed, user_id?: mixed}  $data
     */
    public function recordRealization(array $data): TransferRequestRealization
    {
        if (! $this->canRecordAdditionalRealization()) {
            throw ValidationException::withMessages([
                'realization_status' => __('form-transfer::filament/resources/transfer-request/validation.realization_closed'),
            ]);
        }

        $amountCents = static::amountToCents($data['amount'] ?? null);
        $remainingCents = static::amountToCents($this->remaining_realization_amount);

        if ($amountCents <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('form-transfer::filament/resources/transfer-request/validation.realization_amount_min'),
            ]);
        }

        if ($amountCents > $remainingCents) {
            throw ValidationException::withMessages([
                'amount' => __('form-transfer::filament/resources/transfer-request/validation.realization_amount_max', [
                    'amount' => static::centsToAmount($remainingCents),
                ]),
            ]);
        }

        return $this->realizations()->create([
            'user_id'     => $data['user_id'] ?? null,
            'amount'      => static::centsToAmount($amountCents),
            'realized_at' => $data['realized_at'] ?? null,
            'proof_path'  => $data['proof_path'] ?? null,
            'notes'       => $data['notes'] ?? null,
        ]);
    }

    /**
     * @param  array<int, array{id?: mixed, amount?: mixed, realized_at?: mixed, proof_path?: mixed, notes?: mixed}>  $realizations
     */
    public function replaceRealizations(array $realizations, ?int $userId = null): void
    {
        $normalizedRealizations = [];
        $totalCents = 0;

        foreach (array_values($realizations) as $realization) {
            if (! is_array($realization)) {
                continue;
            }

            $amountCents = static::amountToCents($realization['amount'] ?? null);

            if ($amountCents <= 0) {
                throw ValidationException::withMessages([
                    'realizations' => __('form-transfer::filament/resources/transfer-request/validation.realization_amount_min'),
                ]);
            }

            $totalCents += $amountCents;

            $normalizedRealizations[] = [
                'id'          => is_numeric($realization['id'] ?? null) ? (int) $realization['id'] : null,
                'amount'      => static::centsToAmount($amountCents),
                'realized_at' => $realization['realized_at'] ?? null,
                'proof_path'  => $realization['proof_path'] ?? null,
                'notes'       => $realization['notes'] ?? null,
            ];
        }

        $transferCents = static::amountToCents($this->transfer_amount);

        if ($transferCents > 0 && $totalCents > $transferCents) {
            throw ValidationException::withMessages([
                'realizations' => __('form-transfer::filament/resources/transfer-request/validation.realization_total_amount_max', [
                    'amount' => static::centsToAmount($transferCents),
                ]),
            ]);
        }

        $this->getConnection()->transaction(function () use ($normalizedRealizations, $userId): void {
            $activeRealizationIds = [];

            foreach ($normalizedRealizations as $realizationData) {
                $realization = null;

                if ($realizationData['id']) {
                    $realization = $this->realizations()
                        ->whereKey($realizationData['id'])
                        ->first();

                    if (! $realization instanceof TransferRequestRealization) {
                        throw ValidationException::withMessages([
                            'realizations' => __('form-transfer::filament/resources/transfer-request/validation.realization_not_found'),
                        ]);
                    }
                }

                if (! $realization instanceof TransferRequestRealization) {
                    $realization = $this->realizations()->make([
                        'user_id' => $userId,
                    ]);
                }

                $realization->fill([
                    'amount'      => $realizationData['amount'],
                    'realized_at' => $realizationData['realized_at'],
                    'proof_path'  => $this->resolveReplacementProofPath($realization, $realizationData['proof_path']),
                    'notes'       => $realizationData['notes'],
                ]);

                if (! $realization->user_id && $userId) {
                    $realization->user_id = $userId;
                }

                $realization->save();

                $activeRealizationIds[] = $realization->getKey();
            }

            $realizationsToDelete = $this->realizations();

            if ($activeRealizationIds !== []) {
                $realizationsToDelete->whereNotIn('id', $activeRealizationIds);
            }

            $realizationsToDelete
                ->get()
                ->each(fn (TransferRequestRealization $realization): ?bool => $realization->delete());

            $this->refreshRealizationSummary();
        });
    }

    protected function resolveReplacementProofPath(TransferRequestRealization $realization, mixed $submittedProofPath): mixed
    {
        if (! $realization->exists || filled($submittedProofPath)) {
            return $submittedProofPath;
        }

        $currentProofPath = $realization->getRawOriginal('proof_path') ?? $realization->proof_path;

        return filled($currentProofPath) ? $currentProofPath : $submittedProofPath;
    }

    public function cancelRealization(?string $notes = null): void
    {
        $this->forceFill([
            'realization_notes'  => $notes ?? $this->realization_notes,
            'realization_status' => TransferRequestRealizationStatus::CANCELLED,
        ]);

        $this->save();
    }

    public function refreshRealizationSummary(): void
    {
        $realizations = $this->realizations()
            ->withoutTrashed()
            ->get();

        $totalCents = $realizations->sum(
            fn (TransferRequestRealization $realization): int => static::amountToCents($realization->amount)
        );
        $transferCents = static::amountToCents($this->transfer_amount);
        $latest = $realizations
            ->sortByDesc(fn (TransferRequestRealization $realization): string => sprintf(
                '%s-%010d',
                $realization->realized_at?->format('Y-m-d') ?? '',
                $realization->getKey()
            ))
            ->first();

        $status = match (true) {
            $totalCents <= 0                                   => TransferRequestRealizationStatus::PENDING,
            $transferCents > 0 && $totalCents < $transferCents => TransferRequestRealizationStatus::PARTIAL,
            default                                            => TransferRequestRealizationStatus::DONE,
        };

        $this->forceFill([
            'realized_amount'        => static::centsToAmount($totalCents),
            'realized_at'            => $latest?->realized_at,
            'realization_proof_path' => $latest?->proof_path,
            'realization_notes'      => $latest?->notes,
            'realization_status'     => $status,
        ]);

        $this->saveQuietly();
    }

    public function getRemainingRealizationAmountAttribute(): string
    {
        $remainingCents = static::amountToCents($this->transfer_amount)
            - static::amountToCents($this->realized_amount);

        return static::centsToAmount(max(0, $remainingCents));
    }

    protected static function amountToCents(mixed $amount): int
    {
        $amount = static::normalizeTransferAmount($amount);

        if ($amount === null || $amount === '' || ! is_numeric($amount)) {
            return 0;
        }

        $normalized = number_format((float) $amount, 2, '.', '');
        [$whole, $fraction] = explode('.', $normalized);

        return (((int) $whole) * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    protected static function centsToAmount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    protected static function normalizeLocalizedNumber(string $value): string
    {
        $value = preg_replace('/[^\d,.\-]/', '', $value) ?? '';

        if ($value === '') {
            return '';
        }

        $isNegative = str_starts_with($value, '-');
        $value = str_replace('-', '', $value);

        $lastCommaPosition = strrpos($value, ',');
        $lastDotPosition = strrpos($value, '.');
        $decimalSeparator = null;

        if ($lastCommaPosition !== false && $lastDotPosition !== false) {
            $decimalSeparator = $lastCommaPosition > $lastDotPosition ? ',' : '.';
        } elseif ($lastCommaPosition !== false) {
            $fractionLength = strlen($value) - $lastCommaPosition - 1;
            $decimalSeparator = $fractionLength > 0 && $fractionLength <= 2 ? ',' : null;
        } elseif ($lastDotPosition !== false) {
            $fractionLength = strlen($value) - $lastDotPosition - 1;
            $decimalSeparator = $fractionLength > 0 && $fractionLength <= 2 ? '.' : null;
        }

        if ($decimalSeparator === null) {
            $normalized = preg_replace('/\D/', '', $value) ?? '';

            return $isNegative && $normalized !== '' ? "-{$normalized}" : $normalized;
        }

        $separatorPosition = strrpos($value, $decimalSeparator);
        $integer = preg_replace('/\D/', '', substr($value, 0, $separatorPosition)) ?: '0';
        $fraction = preg_replace('/\D/', '', substr($value, $separatorPosition + 1)) ?? '';
        $normalized = "{$integer}.{$fraction}";

        return $isNegative ? "-{$normalized}" : $normalized;
    }

    protected static function newFactory(): Factory
    {
        return TransferRequestFactory::new();
    }
}
