<?php

namespace Cesa\Waste\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WasteEvent extends Model
{
    use HasFactory;

    protected $table = 'waste_events';

    protected $fillable = [
        'version_id', 'sequence', 'section', 'category_id', 'category_name', 'reason',
        'pip_item_id', 'pip_item_code', 'pip_item_name', 'pip_unit', 'pip_quantity',
    ];

    protected function casts(): array
    {
        return ['pip_quantity' => 'decimal:4'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WasteReportVersion::class, 'version_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WasteEventLine::class, 'event_id');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(WasteEvidence::class, 'event_id');
    }
}
