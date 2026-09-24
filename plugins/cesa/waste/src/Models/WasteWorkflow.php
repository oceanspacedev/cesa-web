<?php

namespace Cesa\Waste\Models;

use Cesa\Waste\Models\Concerns\FillsDerivedWasteKeys;
use Cesa\Waste\Models\Concerns\GuardsWasteBrandAssignment;
use Cesa\Waste\Support\WasteConfigurationKeys;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WasteWorkflow extends Model
{
    use FillsDerivedWasteKeys;
    use GuardsWasteBrandAssignment;
    use HasFactory;

    protected $table = 'waste_workflows';

    protected $fillable = ['brand_id', 'outlet_id', 'name', 'steps', 'is_active'];

    protected function casts(): array
    {
        return ['steps' => 'array', 'is_active' => 'boolean'];
    }

    public function fillDerivedWasteKeys(): void
    {
        if (filled($this->name) || blank($this->brand_id)) {
            return;
        }

        $this->name = WasteConfigurationKeys::approvalName(
            (int) $this->brand_id,
            $this->outlet_id ? (int) $this->outlet_id : null,
        );
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(WasteBrand::class, 'brand_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(WasteOutlet::class, 'outlet_id');
    }
}
