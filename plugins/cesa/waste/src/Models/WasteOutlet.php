<?php

namespace Cesa\Waste\Models;

use Cesa\Waste\Models\Concerns\GuardsWasteBrandAssignment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WasteOutlet extends Model
{
    use GuardsWasteBrandAssignment;
    use HasFactory;

    protected $table = 'waste_outlets';

    protected $fillable = ['brand_id', 'name', 'code', 'slug', 'timezone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(WasteBrand::class, 'brand_id');
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(WasteWorkflow::class, 'outlet_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(config('auth.providers.users.model'), 'waste_outlet_user', 'outlet_id', 'user_id')->withTimestamps();
    }
}
