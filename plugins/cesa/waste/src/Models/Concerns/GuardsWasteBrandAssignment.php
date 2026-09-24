<?php

namespace Cesa\Waste\Models\Concerns;

use Cesa\Waste\Services\WasteAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

trait GuardsWasteBrandAssignment
{
    protected static function bootGuardsWasteBrandAssignment(): void
    {
        static::saving(function (Model $model): void {
            $user = auth()->user();
            if (! $user) {
                return;
            }

            $brandId = $model->getAttribute('brand_id');
            $allowed = app(WasteAccessService::class)->canAssignBrand(
                $user,
                $brandId === null ? null : (int) $brandId,
                $model,
            );

            if (! $allowed) {
                throw ValidationException::withMessages([
                    'brand_id' => 'Brand ini tidak dapat dikelola.',
                ]);
            }
        });
    }
}
