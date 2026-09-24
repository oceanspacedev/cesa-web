<?php

namespace Cesa\Waste\Models;

use Cesa\Waste\Enums\WasteAlternateUnitCandidateStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;

class WasteItemUnitCandidate extends Model
{
    use HasFactory;

    protected $table = 'waste_item_unit_candidates';

    protected $fillable = [
        'item_id', 'unit_id', 'source_file', 'source_sheet', 'example_row',
        'source_row_count', 'source_unit_labels', 'status', 'reviewed_by',
        'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'source_unit_labels' => 'array',
            'status'             => WasteAlternateUnitCandidateStatus::class,
            'reviewed_at'        => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(WasteItem::class, 'item_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(WasteUnit::class, 'unit_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
