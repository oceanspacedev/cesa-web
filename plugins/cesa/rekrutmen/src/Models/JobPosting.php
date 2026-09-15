<?php

namespace Cesa\Rekrutmen\Models;

use Cesa\Rekrutmen\Enums\JobApplicationStatus;
use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Cesa\Rekrutmen\Services\RekrutmenStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Webkul\Security\Traits\HasNullableCreator;
use Webkul\Support\Models\Company;

class JobPosting extends Model
{
    use HasFactory, HasNullableCreator, SoftDeletes;

    public const THUMBNAIL_DIRECTORY = 'rekrutmen/job-postings';

    protected $table = 'rekrutmen_job_postings';

    protected bool $thumbnailDiskWasAssigned = false;

    protected $fillable = [
        'company_id',
        'request_man_power_id',
        'rekrutmen_pipeline_id',
        'title',
        'slug',
        'description',
        'requirements',
        'location',
        'thumbnail_path',
        'thumbnail_disk',
        'is_published',
        'closing_date',
    ];

    protected $casts = [
        'company_id'   => 'integer',
        'is_published' => 'boolean',
        'closing_date' => 'date',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
        'deleted_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $jobPosting): void {
            if (blank($jobPosting->thumbnail_path)) {
                $jobPosting->thumbnail_disk = null;

                return;
            }

            if ($jobPosting->isDirty('thumbnail_path') && ! $jobPosting->thumbnailDiskWasAssigned) {
                $jobPosting->thumbnail_disk = null;
            }

            if (! $jobPosting->isDirty('thumbnail_path') && $jobPosting->thumbnail_disk === null) {
                $jobPosting->thumbnail_disk = $jobPosting->getOriginal('thumbnail_disk');
            }

            $jobPosting->thumbnail_disk ??= $jobPosting->resolveThumbnailDisk();
        });

        static::updated(function (self $jobPosting): void {
            if (! $jobPosting->wasChanged(['thumbnail_path', 'thumbnail_disk'])) {
                return;
            }

            $oldPath = $jobPosting->getOriginal('thumbnail_path');
            $oldDisk = $jobPosting->getOriginal('thumbnail_disk');

            if (! is_string($oldPath) || $oldPath === '') {
                return;
            }

            $oldDisk = app(RekrutmenStorage::class)->resolveDisk($oldPath, $oldDisk, self::thumbnailDisk());

            if ($oldDisk === null || ($oldPath === $jobPosting->thumbnail_path && $oldDisk === $jobPosting->thumbnail_disk)) {
                return;
            }

            try {
                Storage::disk($oldDisk)->delete($oldPath);
            } catch (Throwable) {
                return;
            }
        });

        static::saved(function (self $jobPosting): void {
            $jobPosting->thumbnailDiskWasAssigned = false;

            if (! is_numeric($jobPosting->request_man_power_id)) {
                return;
            }

            RequestManPower::query()
                ->whereKey((int) $jobPosting->request_man_power_id)
                ->whereNull('job_posting_id')
                ->update([
                    'job_posting_id' => $jobPosting->getKey(),
                    'updated_at'     => now(),
                ]);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id')->withTrashed();
    }

    public function requestManPower(): BelongsTo
    {
        return $this->belongsTo(RequestManPower::class, 'request_man_power_id')->withTrashed();
    }

    public function requestManPowers(): HasMany
    {
        return $this->hasMany(RequestManPower::class, 'job_posting_id')->withTrashed();
    }

    public function resolveCompany(): ?Company
    {
        if ($this->relationLoaded('company') && $this->company) {
            return $this->company;
        }

        if ($this->company_id) {
            $company = $this->company()->first();
            if ($company) {
                return $company;
            }
        }

        $requests = $this->relationLoaded('requestManPowers')
            ? $this->requestManPowers->filter(fn (RequestManPower $r): bool => $r->deleted_at === null)->values()
            : $this->requestManPowers()->with('company')->whereNull('deleted_at')->get();

        $sourceRequest = $this->relationLoaded('requestManPower')
            ? $this->requestManPower
            : $this->requestManPower()->with('company')->first();

        if ($requests->isEmpty() && $sourceRequest && ! $sourceRequest->trashed()) {
            $requests = collect([$sourceRequest]);
        }

        $approved = $requests->first(
            fn (RequestManPower $r): bool => $r->status === RequestManPowerStatus::APPROVED && $r->company !== null
        );

        if ($approved?->company) {
            return $approved->company;
        }

        $withCompany = $requests->first(fn (RequestManPower $r): bool => $r->company !== null);

        if ($withCompany?->company) {
            return $withCompany->company;
        }

        if ($sourceRequest && ! $sourceRequest->trashed() && $sourceRequest->company) {
            return $sourceRequest->company;
        }

        return null;
    }

    public function resolveCompanyName(): string
    {
        return $this->resolveCompany()?->name ?? 'PT Complete Selular Group';
    }

    public function getCompanyNameAttribute(): string
    {
        return $this->resolveCompanyName();
    }

    public function rekrutmenPipeline(): BelongsTo
    {
        return $this->belongsTo(RekrutmenPipeline::class, 'rekrutmen_pipeline_id')->withTrashed();
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class, 'job_posting_id');
    }

    public function scopeAcceptingApplications(Builder $query): Builder
    {
        return $query
            ->openForCandidateIntake()
            ->whereHas('rekrutmenPipeline.activeStages');
    }

    public function scopeOpenForCandidateIntake(Builder $query): Builder
    {
        return $query
            ->where($this->qualifyColumn('is_published'), true)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull($this->qualifyColumn('closing_date'))
                    ->orWhereDate($this->qualifyColumn('closing_date'), '>=', today());
            })
            ->whereRaw($this->hiredApplicationsCountSql().' < '.$this->activeOpeningHeadcountSql(), [
                JobApplicationStatus::HIRED->value,
                ...$this->activeOpeningHeadcountSqlBindings(),
            ]);
    }

    public static function thumbnailDisk(): string
    {
        return app(RekrutmenStorage::class)->thumbnailDisk();
    }

    public function setThumbnailDiskAttribute(mixed $disk): void
    {
        $this->attributes['thumbnail_disk'] = is_string($disk) && trim($disk) !== '' ? trim($disk) : null;
        $this->thumbnailDiskWasAssigned = true;
    }

    public function resolveThumbnailDisk(): ?string
    {
        if (! is_string($this->thumbnail_path) || $this->thumbnail_path === '') {
            return null;
        }

        $disk = app(RekrutmenStorage::class)->resolveDisk($this->thumbnail_path, $this->thumbnail_disk, self::thumbnailDisk());

        if ($disk !== null && $this->thumbnail_disk === null && $this->exists
            && ! $this->isDirty(['thumbnail_path', 'thumbnail_disk'])) {
            $persisted = static::query()->withoutGlobalScopes()->whereKey($this->getKey())
                ->where('thumbnail_path', $this->getRawOriginal('thumbnail_path'))
                ->whereNull('thumbnail_disk')
                ->update(['thumbnail_disk' => $disk]);

            if ($persisted) {
                $this->attributes['thumbnail_disk'] = $disk;
                $this->syncOriginalAttribute('thumbnail_disk');
            }
        }

        return $disk;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (! is_string($this->thumbnail_path) || $this->thumbnail_path === '') {
            return null;
        }

        $disk = $this->resolveThumbnailDisk();

        return app(RekrutmenStorage::class)->url($this->thumbnail_path, $this->thumbnail_disk ?? $disk, self::thumbnailDisk());
    }

    public function isAcceptingApplications(): bool
    {
        if (! $this->isOpenForCandidateIntake()) {
            return false;
        }

        if (! $this->hasActivePipelineStages()) {
            return false;
        }

        return $this->remainingHeadcount() > 0;
    }

    public function isOpenForCandidateIntake(): bool
    {
        if (! $this->is_published) {
            return false;
        }

        if ($this->closing_date?->lt(today())) {
            return false;
        }

        return $this->remainingHeadcount() > 0;
    }

    public function remainingHeadcount(): int
    {
        return max(0, $this->activeOpeningHeadcount() - $this->hiredApplicationsCount());
    }

    public function activeOpeningHeadcount(): int
    {
        $linkedRequests = $this->relationLoaded('requestManPowers')
            ? $this->requestManPowers
            : $this->requestManPowers()->get();

        $activeLinkedRequests = $linkedRequests
            ->filter(fn (RequestManPower $requestManPower): bool => $requestManPower->deleted_at === null);

        if ($activeLinkedRequests->isNotEmpty()) {
            return (int) $activeLinkedRequests
                ->filter(fn (RequestManPower $requestManPower): bool => $requestManPower->status === RequestManPowerStatus::APPROVED)
                ->sum('jumlah_karyawan_dibutuhkan');
        }

        $sourceRequest = $this->requestManPower;

        if (
            $sourceRequest
            && ! $sourceRequest->trashed()
            && $sourceRequest->status === RequestManPowerStatus::APPROVED
            && (
                ! is_numeric($sourceRequest->job_posting_id)
                || (int) $sourceRequest->job_posting_id === (int) $this->getKey()
            )
        ) {
            return max(1, (int) ($sourceRequest->jumlah_karyawan_dibutuhkan ?? 1));
        }

        if ($sourceRequest) {
            return 0;
        }

        return 1;
    }

    public function hiredApplicationsCount(): int
    {
        if ($this->relationLoaded('applications')) {
            return $this->applications
                ->filter(function (JobApplication $application): bool {
                    if ($application->status instanceof JobApplicationStatus) {
                        return $application->status === JobApplicationStatus::HIRED;
                    }

                    return $application->status === JobApplicationStatus::HIRED->value;
                })
                ->count();
        }

        return $this->applications()
            ->where('status', JobApplicationStatus::HIRED->value)
            ->count();
    }

    private function hasActivePipelineStages(): bool
    {
        if (! is_numeric($this->rekrutmen_pipeline_id)) {
            return false;
        }

        if ($this->relationLoaded('rekrutmenPipeline') && $this->rekrutmenPipeline?->relationLoaded('activeStages')) {
            return $this->rekrutmenPipeline->activeStages->isNotEmpty();
        }

        return RekrutmenStage::query()
            ->where('rekrutmen_pipeline_id', (int) $this->rekrutmen_pipeline_id)
            ->exists();
    }

    public function totalNeeded(): int
    {
        $totalNeeded = $this->requestManPowers()
            ->whereNull((new RequestManPower)->qualifyColumn('deleted_at'))
            ->whereIn('status', [
                RequestManPowerStatus::APPROVED->value,
                RequestManPowerStatus::HOLD->value,
            ])
            ->sum('jumlah_karyawan_dibutuhkan');

        if ((int) $totalNeeded > 0) {
            return (int) $totalNeeded;
        }

        $sourceRequest = $this->requestManPower;

        if (! $sourceRequest) {
            return 1;
        }

        if (
            $sourceRequest->trashed()
            || ! in_array($sourceRequest->status, [
                RequestManPowerStatus::PENDING,
                RequestManPowerStatus::APPROVED,
                RequestManPowerStatus::HOLD,
            ], true)
        ) {
            return 0;
        }

        if (
            is_numeric($sourceRequest->job_posting_id)
            && (int) $sourceRequest->job_posting_id !== (int) $this->getKey()
        ) {
            return 0;
        }

        return (int) ($sourceRequest->jumlah_karyawan_dibutuhkan ?? 1);
    }

    private function hiredApplicationsCountSql(): string
    {
        $applicationsTable = (new JobApplication)->getTable();

        return implode(' ', [
            '(select count(*)',
            'from '.$applicationsTable,
            'where '.$applicationsTable.'.job_posting_id = '.$this->qualifyColumn('id'),
            'and '.$applicationsTable.'.deleted_at is null',
            'and '.$applicationsTable.'.status = ?)',
        ]);
    }

    private function activeOpeningHeadcountSql(): string
    {
        $requestTable = (new RequestManPower)->getTable();

        $linkedRequestExistsSql = implode(' ', [
            '(select count(*)',
            'from '.$requestTable,
            'where '.$requestTable.'.job_posting_id = '.$this->qualifyColumn('id'),
            'and '.$requestTable.'.deleted_at is null)',
        ]);

        $linkedApprovedHeadcountSql = implode(' ', [
            '(select sum('.$requestTable.'.jumlah_karyawan_dibutuhkan)',
            'from '.$requestTable,
            'where '.$requestTable.'.job_posting_id = '.$this->qualifyColumn('id'),
            'and '.$requestTable.'.deleted_at is null',
            'and '.$requestTable.'.status = ?)',
        ]);

        $sourceApprovedHeadcountSql = implode(' ', [
            '(select COALESCE(NULLIF('.$requestTable.'.jumlah_karyawan_dibutuhkan, 0), 1)',
            'from '.$requestTable,
            'where '.$requestTable.'.id = '.$this->qualifyColumn('request_man_power_id'),
            'and '.$requestTable.'.deleted_at is null',
            'and '.$requestTable.'.status = ?',
            'and ('.$requestTable.'.job_posting_id is null or '.$requestTable.'.job_posting_id = '.$this->qualifyColumn('id').')',
            'limit 1)',
        ]);

        return implode(' ', [
            '(case',
            'when '.$linkedRequestExistsSql.' > 0 then COALESCE('.$linkedApprovedHeadcountSql.', 0)',
            'when '.$this->qualifyColumn('request_man_power_id').' is null then 1',
            'else COALESCE('.$sourceApprovedHeadcountSql.', 0)',
            'end)',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function activeOpeningHeadcountSqlBindings(): array
    {
        return [
            RequestManPowerStatus::APPROVED->value,
            RequestManPowerStatus::APPROVED->value,
        ];
    }
}
