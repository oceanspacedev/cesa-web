<?php

namespace Cesa\Waste\Models;

use Cesa\Waste\Models\Concerns\FillsDerivedWasteKeys;
use Cesa\Waste\Models\Concerns\GuardsWasteBrandAssignment;
use Cesa\Waste\Support\WasteConfigurationKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WasteSection extends Model
{
    use FillsDerivedWasteKeys;
    use GuardsWasteBrandAssignment;

    protected $table = 'waste_sections';

    protected $fillable = ['brand_id', 'code', 'name', 'is_active'];

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

    /**
     * @return array<string, array<int, string>>
     */
    public static function defaultNames(): array
    {
        return [
            'JCHICKEN' => ['BAR', 'COOK', 'ASSEMBLY', 'MP', 'DINING'],
        ];
    }

    public static function seedDefaults(): void
    {
        foreach (self::defaultNames() as $brandCode => $names) {
            $brandId = WasteBrand::query()->where('code', $brandCode)->value('id');
            if (! $brandId) {
                continue;
            }

            foreach ($names as $name) {
                self::query()->updateOrCreate(
                    ['brand_id' => $brandId, 'code' => $name],
                    ['name' => $name, 'is_active' => true],
                );
            }
        }
    }
}
