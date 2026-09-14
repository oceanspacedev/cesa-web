<?php

namespace Cesa\Rekrutmen\Models;

use Database\Factories\NotificationDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'rekrutmen_notification_deliveries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload'                   => 'array',
            'stage_snapshot'            => 'array',
            'attempts'                  => 'integer',
            'whatsapp_account_id'       => 'integer',
            'application_id'            => 'integer',
            'scheduled_notification_id' => 'integer',
            'available_at'              => 'datetime',
            'queued_at'                 => 'datetime',
            'claimed_at'                => 'datetime',
            'lease_expires_at'          => 'datetime',
            'stage_applied_at'          => 'datetime',
            'last_reconciled_at'        => 'datetime',
            'sent_at'                   => 'datetime',
        ];
    }

    protected static function newFactory(): NotificationDeliveryFactory
    {
        return NotificationDeliveryFactory::new();
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(ScheduledNotification::class, 'scheduled_notification_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'application_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }
}
