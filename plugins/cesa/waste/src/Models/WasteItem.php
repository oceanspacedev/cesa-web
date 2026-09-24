<?php

namespace Cesa\Waste\Models;

use Cesa\Waste\Models\Concerns\FillsDerivedWasteKeys;
use Cesa\Waste\Models\Concerns\GuardsWasteBrandAssignment;
use Cesa\Waste\Support\WasteConfigurationKeys;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WasteItem extends Model
{
    use FillsDerivedWasteKeys;
    use GuardsWasteBrandAssignment;
    use HasFactory;

    protected $table = 'waste_items';

    protected $fillable = ['brand_id', 'code', 'name', 'item_type', 'unit', 'source_unit_label', 'source_status', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function fillDerivedWasteKeys(): void
    {
        WasteConfigurationKeys::assignCode($this, 100, static::query()->where('brand_id', $this->brand_id));
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(WasteBrand::class, 'brand_id');
    }

    public function unitMaster(): BelongsTo
    {
        return $this->belongsTo(WasteUnit::class, 'unit', 'code');
    }

    public function alternateUnits(): BelongsToMany
    {
        return $this->belongsToMany(WasteUnit::class, 'waste_item_alternate_units', 'item_id', 'unit_id')->withTimestamps();
    }

    public function alternateUnitCandidates(): HasMany
    {
        return $this->hasMany(WasteItemUnitCandidate::class, 'item_id');
    }

    public function eventLines(): HasMany
    {
        return $this->hasMany(WasteEventLine::class, 'item_id');
    }
}
