<?php

namespace Cesa\Waste\Models;

use Cesa\Waste\Models\Concerns\FillsDerivedWasteKeys;
use Cesa\Waste\Support\WasteConfigurationKeys;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WasteUnit extends Model
{
    use FillsDerivedWasteKeys;
    use HasFactory;

    protected $table = 'waste_units';

    protected $fillable = ['code', 'name', 'is_active'];

    public static function normalizeCode(mixed $value): ?string
    {
        $unit = Str::upper(trim((string) $value));
        if ($unit === '' || in_array($unit, ['#N/A', 'N/A', '-'], true)) {
            return null;
        }

        return match ($unit) {
            'G', 'GRAM', 'GR.' => 'GR',
            'KG', 'KGS', 'KILOGRAM', 'KILOGRAMS' => 'KG',
            'ML', 'MILLILITER', 'MILLILITRE', 'MILILITER' => 'ML',
            'L', 'LITER', 'LITRE' => 'L',
            'PCS', 'PC', 'PIECE', 'PIECES', 'PCE' => 'PCS',
            'PORSI' => 'PRS',
            'ROLL'  => 'ROL',
            'PACK'  => 'PCK',
            default => $unit,
        };
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function fillDerivedWasteKeys(): void
    {
        WasteConfigurationKeys::assignCode($this, 32, static::query(), true);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WasteItem::class, 'unit', 'code');
    }

    public function alternateItems(): BelongsToMany
    {
        return $this->belongsToMany(WasteItem::class, 'waste_item_alternate_units', 'unit_id', 'item_id')->withTimestamps();
    }
}
