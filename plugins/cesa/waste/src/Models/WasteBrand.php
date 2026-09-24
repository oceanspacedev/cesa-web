<?php

namespace Cesa\Waste\Models;

use Cesa\Waste\Models\Concerns\FillsDerivedWasteKeys;
use Cesa\Waste\Support\WasteConfigurationKeys;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WasteBrand extends Model
{
    use FillsDerivedWasteKeys;
    use HasFactory;

    protected $table = 'waste_brands';

    protected $fillable = ['name', 'code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function fillDerivedWasteKeys(): void
    {
        WasteConfigurationKeys::assignCode($this, 50, static::query());
    }

    public function outlets(): HasMany
    {
        return $this->hasMany(WasteOutlet::class, 'brand_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WasteItem::class, 'brand_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(WasteCategory::class, 'brand_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(WasteSection::class, 'brand_id');
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(WasteWorkflow::class, 'brand_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(config('auth.providers.users.model'), 'waste_brand_user', 'brand_id', 'user_id')->withTimestamps();
    }
}
