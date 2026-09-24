<?php

namespace Cesa\Waste\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WasteEventLine extends Model
{
    use HasFactory;

    protected $table = 'waste_event_lines';

    protected $fillable = [
        'event_id', 'item_id', 'item_code', 'item_name', 'item_type', 'unit', 'unit_label', 'quantity', 'line_role',
        'sm_checked', 'audit_checked',
    ];

    protected function casts(): array
    {
        return [
            'quantity'      => 'decimal:4',
            'sm_checked'    => 'boolean',
            'audit_checked' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(WasteEvent::class, 'event_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(WasteItem::class, 'item_id');
    }
}
