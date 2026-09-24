<?php

namespace Cesa\Waste\Models;

use Cesa\Waste\Enums\WasteReportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WasteReportVersion extends Model
{
    use HasFactory;

    protected $table = 'waste_report_versions';

    protected $fillable = ['report_id', 'version_number', 'status', 'rejection_reason', 'workflow_snapshot'];

    protected function casts(): array
    {
        return [
            'status'            => WasteReportStatus::class,
            'workflow_snapshot' => 'array',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(WasteReport::class, 'report_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WasteEvent::class, 'version_id')->orderBy('sequence');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(WasteApproval::class, 'version_id')->orderBy('step_order');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(WasteActivityLog::class, 'version_id');
    }
}
