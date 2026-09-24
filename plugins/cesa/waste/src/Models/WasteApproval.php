<?php

namespace Cesa\Waste\Models;

use Cesa\Waste\Enums\WasteApprovalStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WasteApproval extends Model
{
    use HasFactory;

    protected $table = 'waste_approvals';

    protected $fillable = [
        'version_id', 'step_order', 'label', 'approver_name', 'approver_email',
        'approver_phone', 'token_hash', 'status', 'decision_note', 'decided_at', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'status'      => WasteApprovalStatus::class,
            'decided_at'  => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WasteReportVersion::class, 'version_id');
    }
}
