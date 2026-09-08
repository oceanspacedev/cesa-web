<?php

namespace Cesa\Rekrutmen\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Webkul\Security\Models\User;
use Webkul\Security\Traits\HasNullableCreator;

class ScheduledNotification extends Model
{
    use HasFactory, HasNullableCreator, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'rekrutmen_scheduled_notifications';

    protected $fillable = [
        'creator_id',
        'application_ids',
        'channels',
        'whatsapp_account_id',
        'subject',
        'body_message',
        'schedule',
        'candidate_schedules',
        'venue_or_method',
        'action_url',
        'action_label',
        'special_note',
        'badge_text',
        'info_box_title',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
        'scheduled_at',
        'status',
        'sent_at',
        'results',
        'error_message',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'application_ids'      => 'array',
            'channels'             => 'array',
            'candidate_schedules'  => 'array',
            'whatsapp_account_id'  => 'integer',
            'results'              => 'array',
            'scheduled_at'         => 'datetime',
            'sent_at'              => 'datetime',
            'created_at'           => 'datetime',
            'updated_at'           => 'datetime',
            'deleted_at'           => 'datetime',
        ];
    }

    /**
     * Scope query to only pending notifications.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope query to pending notifications that are due for execution.
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('scheduled_at', '<=', now());
    }

    /**
     * Relation to creator.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
