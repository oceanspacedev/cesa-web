<?php

namespace Cesa\Waste\Models;

use Cesa\Waste\Models\Concerns\GuardsWasteBrandAssignment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WasteWorkflow extends Model
{
    use GuardsWasteBrandAssignment;
    use HasFactory;

    protected $table = 'waste_workflows';

    protected $fillable = ['brand_id', 'outlet_id', 'name', 'steps', 'is_active'];

    protected function casts(): array
    {
        return ['steps' => 'array', 'is_active' => 'boolean'];
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
