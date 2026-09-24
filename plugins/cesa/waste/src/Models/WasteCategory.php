<?php

namespace Cesa\Waste\Models;

use Cesa\Waste\Models\Concerns\GuardsWasteBrandAssignment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WasteCategory extends Model
{
    use GuardsWasteBrandAssignment;
    use HasFactory;

    protected $table = 'waste_categories';

    protected $fillable = ['brand_id', 'code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(WasteBrand::class, 'brand_id');
    }
}
