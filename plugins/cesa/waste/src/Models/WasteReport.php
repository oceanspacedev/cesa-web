<?php

namespace Cesa\Waste\Models;

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Services\WasteEvidenceCleanupService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WasteReport extends Model
{
    use HasFactory;

    /** @var array<int, string> */
    protected array $evidencePathsForDeletion = [];

    protected $table = 'waste_reports';

    protected $fillable = [
        'uid', 'submission_key', 'brand_id', 'outlet_id', 'event_date', 'reporter_name', 'reporter_phone',
        'reporter_email', 'status', 'manage_token_hash', 'progress_token_hash', 'token_version',
        'latest_version_id', 'submitted_at', 'approved_at', 'rejected_at',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $report): void {
            $report->evidencePathsForDeletion = app(WasteEvidenceCleanupService::class)->pathsForReport($report);
        });

        static::deleted(function (self $report): void {
            app(WasteEvidenceCleanupService::class)->deleteUnreferencedAfterCommit($report->evidencePathsForDeletion);
        });
    }

    protected function casts(): array
    {
        return [
            'event_date'    => 'date',
            'status'        => WasteReportStatus::class,
            'token_version' => 'integer',
            'submitted_at'  => 'datetime',
            'approved_at'   => 'datetime',
            'rejected_at'   => 'datetime',
        ];
    }

    public function reporterNameForDisplay(): string
    {
        return $this->hasQaReporterPlaceholder()
            ? __('waste::waste.qa_reporter')
            : (string) $this->reporter_name;
    }

    public function reporterNameForTemplate(): ?string
    {
        return $this->hasQaReporterPlaceholder() ? null : $this->reporter_name;
    }

    public function hasQaReporterPlaceholder(): bool
    {
        return in_array($this->reporter_name, [
            'Petugas QA TPS (USER Excel kosong)',
            'Petugas QA TPS (tanpa kolom USER)',
        ], true);
    }

    public function hasQaSourceMarker(): bool
    {
        return preg_match(
            '/^qa-waste-202609-(jchicken|luuca|momoyo)-row[0-9]+@example\.test$/',
            (string) $this->reporter_email,
        ) === 1;
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(WasteBrand::class, 'brand_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(WasteOutlet::class, 'outlet_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WasteReportVersion::class, 'report_id');
    }

    public function latestVersion(): BelongsTo
    {
        return $this->belongsTo(WasteReportVersion::class, 'latest_version_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(WasteActivityLog::class, 'report_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(WasteNotificationDelivery::class, 'report_id');
    }
}
