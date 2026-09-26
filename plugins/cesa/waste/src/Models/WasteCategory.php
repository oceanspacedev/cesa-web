<?php

namespace Cesa\Waste\Models;

use Cesa\Waste\Models\Concerns\FillsDerivedWasteKeys;
use Cesa\Waste\Models\Concerns\GuardsWasteBrandAssignment;
use Cesa\Waste\Support\WasteConfigurationKeys;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WasteCategory extends Model
{
    use FillsDerivedWasteKeys;
    use GuardsWasteBrandAssignment;
    use HasFactory;

    protected $table = 'waste_categories';

    protected $fillable = ['brand_id', 'code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * Source Excel files carry misspelled report labels; they must land on the seeded category names.
     */
    public static function normalizedName(mixed $value): string
    {
        $name = trim((string) $value);

        return match (Str::upper($name)) {
            'TARINING/TRIAL'           => 'Training/Trial',
            'PEMOTREATAN/EVENT/TALENT' => 'Pemotretan/Event/Talent',
            default                    => $name,
        };
    }

    public function fillDerivedWasteKeys(): void
    {
        WasteConfigurationKeys::assignCode($this, 100, static::query()->where('brand_id', $this->brand_id));
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(WasteBrand::class, 'brand_id');
    }
}
