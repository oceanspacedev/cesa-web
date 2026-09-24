<?php

namespace Cesa\Waste\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WasteNotificationDelivery extends Model
{
    use HasFactory;

    protected $table = 'waste_notification_deliveries';

    protected $fillable = [
        'report_id', 'version_id', 'channel', 'type', 'recipient', 'payload', 'status',
        'attempts', 'last_error', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer', 'sent_at' => 'datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(WasteReport::class, 'report_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WasteReportVersion::class, 'version_id');
    }
}
