<?php

namespace Cesa\ExitClearance\Models;

use Cesa\ExitClearance\Database\Factories\RequestFactory;
use Cesa\ExitClearance\Services\ExitClearanceRequestService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Security\Traits\HasNullableCreator;
use Webkul\Support\Models\Company;

class Request extends Model
{
    use HasFactory, HasNullableCreator, SoftDeletes;

    protected ?string $originalResignationLetterPath = null;

    protected $table = 'exit_clearance_requests';

    protected $fillable = [
        'company_id',
        'department_id',
        'name',
        'email',
        'phone',
        'position',
        'placement',
        'join_date',
        'request_date',
        'departure_date',
        'reason',
        'workload_feedback',
        'career_growth_feedback',
        'facility_welfare_feedback',
        'work_relationship_feedback',
        'compensation_feedback',
        'division_feedback',
        'company_feedback',
        'clearance_kartu_halo',
        'clearance_employee_debt',
        'clearance_uniform_return',
        'clearance_vehicle_return',
        'clearance_inventory_return',
        'clearance_account_deactivation',
        'clearance_receivable_data',
        'clearance_promotor_internal',
        'clearance_nota_pending',
        'clearance_stock_opname',
        'resignation_letter_url',
        'form_uid',
        'form_status',
        'form_response_id',
        'creator_id',
    ];

    protected $casts = [
        'company_id'     => 'integer',
        'join_date'      => 'date',
        'request_date'   => 'date',
        'departure_date' => 'date',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
        'deleted_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Request $request): void {
            if (empty($request->request_date)) {
                $request->request_date = now()->toDateString();
            }

            if (empty($request->form_uid)) {
                $request->form_uid = app(ExitClearanceRequestService::class)->generateFormUid();
            }

            if (empty($request->form_response_id)) {
                $request->form_response_id = (string) Str::uuid();
            }

            if (empty($request->form_status)) {
                $request->form_status = ExitClearanceRequestService::FORM_STATUS_PENDING;
            }
        });

        static::saving(function (Request $request): void {
            $request->snapshotOriginalResignationLetterPath();
        });

        static::saved(function (Request $request): void {
            $request->syncManagedResignationLetterPath();
            $request->deleteRemovedResignationLetterFile();
        });

        static::forceDeleted(function (Request $request): void {
            $request->deleteManagedFile(
                $request->normalizeManagedFilePath(
                    $request->getRawOriginal('resignation_letter_url') ?? $request->resignation_letter_url
                )
            );
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id')->withTrashed();
    }

    protected function getAssignmentColumn(): ?string
    {
        return null;
    }

    public function approvers(): BelongsToMany
    {
        return $this->belongsToMany(Approver::class, 'exit_clearance_request_approver', 'request_id', 'approver_id')
            ->withPivot(['approved_at', 'notes', 'status'])
            ->withTimestamps()
            ->withTrashed();
    }

    public function getPublicProgressUrl(): string
    {
        if (blank($this->form_response_id)) {
            return url('exit-clearance');
        }

        return route('exit-clearance.public.progress', [
            'response' => $this->form_response_id,
        ]);
    }

    protected static function newFactory(): RequestFactory
    {
        return RequestFactory::new();
    }

    protected function snapshotOriginalResignationLetterPath(): void
    {
        if (! $this->exists) {
            $this->originalResignationLetterPath = null;

            return;
        }

        $this->originalResignationLetterPath = $this->normalizeManagedFilePath(
            $this->getRawOriginal('resignation_letter_url')
        );
    }

    protected function syncManagedResignationLetterPath(): void
    {
        $path = $this->normalizeManagedFilePath($this->resignation_letter_url);

        if (! $path || blank($this->form_uid)) {
            return;
        }

        $renamedPath = $this->renameManagedFile(
            $path,
            'resignation-letters',
            $this->form_uid
        );

        if ($renamedPath === $path) {
            return;
        }

        $this->forceFill([
            'resignation_letter_url' => $renamedPath,
        ]);

        $this->saveQuietly();
    }

    protected function deleteRemovedResignationLetterFile(): void
    {
        if ($this->originalResignationLetterPath === null) {
            return;
        }

        $originalPath = $this->originalResignationLetterPath;
        $currentPath = $this->normalizeManagedFilePath($this->resignation_letter_url);

        $this->originalResignationLetterPath = null;

        if ($originalPath === $currentPath) {
            return;
        }

        $this->deleteManagedFile($originalPath);
    }

    protected function normalizeManagedFilePath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $relativePath = ltrim(trim($path), '/');

        return $relativePath !== '' ? $relativePath : null;
    }

    protected function renameManagedFile(string $path, string $directory, string $prefix): string
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

        $disk = $this->resolveManagedFileDisk($path);

        if (! $disk) {
            return $path;
        }

        do {
            $targetPath = $canonicalDirectory.'/'.$prefix.'-'.Str::lower(Str::random(6));

            if ($extension !== '') {
                $targetPath .= '.'.$extension;
            }
        } while (Storage::disk($disk)->exists($targetPath));

        try {
            Storage::disk($disk)->move($path, $targetPath);

            return $targetPath;
        } catch (\Throwable) {
            return $path;
        }
    }

    protected function deleteManagedFile(?string $path): void
    {
        if (! $path || filter_var($path, FILTER_VALIDATE_URL)) {
            return;
        }

        $disk = $this->resolveManagedFileDisk($path);

        if (! $disk) {
            return;
        }

        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable) {
            return;
        }
    }

    protected function resolveManagedFileDisk(string $path): ?string
    {
        $candidateDisks = array_values(array_unique(array_filter([
            config('filament.default_filesystem_disk'),
            config('filesystems.default'),
            'local',
            'public',
        ], fn (mixed $disk): bool => is_string($disk) && $disk !== '')));

        foreach ($candidateDisks as $disk) {
            if (! config()->has("filesystems.disks.{$disk}")) {
                continue;
            }

            try {
                if (Storage::disk($disk)->exists($path)) {
                    return $disk;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
