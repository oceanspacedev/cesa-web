<?php

namespace Cesa\Waste\Services;

use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Models\WasteWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class WasteAccessService
{
    public function canManageAnyBrand(?object $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->isGlobal($user) || WasteBrand::query()
            ->whereHas('users', fn (Builder $query): Builder => $query->whereKey($user->getKey()))
            ->exists();
    }

    public function canAccessReports(?object $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->canManageAnyBrand($user) || WasteOutlet::query()
            ->whereHas('users', fn (Builder $query): Builder => $query->whereKey($user->getKey()))
            ->exists();
    }

    public function canAssignBrand(?object $user, ?int $brandId, ?Model $record = null): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->isGlobal($user)) {
            return true;
        }

        if ($brandId === null) {
            return false;
        }

        $brand = WasteBrand::query()->find($brandId);
        if ($brand && $this->canManageBrand($user, $brand)) {
            return true;
        }

        return $record instanceof WasteOutlet
            && $record->exists
            && (int) $record->getOriginal('brand_id') === $brandId
            && $this->canManageOutlet($user, $record);
    }

    public function canManageBrand(?object $user, WasteBrand $brand): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->isGlobal($user)) {
            return true;
        }

        return $brand->users()->whereKey($user->getKey())->exists();
    }

    public function canManageOutlet(?object $user, WasteOutlet $outlet): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->canManageBrand($user, $outlet->brand)) {
            return true;
        }

        return $outlet->users()->whereKey($user->getKey())->exists();
    }

    public function canManageItem(?object $user, WasteItem $item): bool
    {
        return $this->canManageBrand($user, $item->brand);
    }

    public function canManageSection(?object $user, WasteSection $section): bool
    {
        return $this->canManageBrand($user, $section->brand);
    }

    public function canManageCategory(?object $user, WasteCategory $category): bool
    {
        return $category->brand_id === null
            ? (bool) ($user && method_exists($user, 'can') && $user->can('view_any_waste_waste::report'))
            : $this->canManageBrand($user, $category->brand);
    }

    public function canManageWorkflow(?object $user, WasteWorkflow $workflow): bool
    {
        return $this->canManageBrand($user, $workflow->brand);
    }

    public function scopeBrands(Builder $query, ?object $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isGlobal($user)) {
            return $query;
        }

        $brandIds = $this->brandIds($user);
        $outletBrandIds = WasteOutlet::query()
            ->whereIn('id', $this->outletIds($user))
            ->pluck('brand_id')
            ->all();

        return $query->whereIn('id', array_values(array_unique([...$brandIds, ...$outletBrandIds])));
    }

    public function scopeOutlets(Builder $query, ?object $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isGlobal($user)) {
            return $query;
        }

        $brandIds = $this->brandIds($user);
        $outletIds = $this->outletIds($user);

        if ($brandIds === [] && $outletIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($brandIds, $outletIds): void {
            if ($brandIds !== []) {
                $query->whereIn('brand_id', $brandIds);
            }

            if ($outletIds !== []) {
                $query->orWhereIn('id', $outletIds);
            }
        });
    }

    public function scopeItems(Builder $query, ?object $user): Builder
    {
        return $this->scopeByBrands($query, $user, 'brand_id');
    }

    public function scopeSections(Builder $query, ?object $user): Builder
    {
        return $this->scopeByBrands($query, $user, 'brand_id');
    }

    public function scopeCategories(Builder $query, ?object $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isGlobal($user)) {
            return $query;
        }

        $brandIds = $this->visibleBrandIds($user);

        return $query->where(function (Builder $query) use ($brandIds): void {
            $query->whereNull('brand_id');
            if ($brandIds !== []) {
                $query->orWhereIn('brand_id', $brandIds);
            }
        });
    }

    public function scopeWorkflows(Builder $query, ?object $user): Builder
    {
        return $this->scopeByBrands($query, $user, 'brand_id');
    }

    public function scopeReports(Builder $query, ?object $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isGlobal($user)) {
            return $query;
        }

        $brandIds = Schema::hasTable('waste_brand_user')
            ? $this->brandIds($user)
            : [];
        $outletIds = Schema::hasTable('waste_outlet_user')
            ? $this->outletIds($user)
            : [];

        if ($brandIds === [] && $outletIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($brandIds, $outletIds): void {
            if ($brandIds !== []) {
                $query->whereIn('brand_id', $brandIds);
            }

            if ($outletIds !== []) {
                $query->orWhereIn('outlet_id', $outletIds);
            }
        });
    }

    /**
     * @return array<int, int>
     */
    protected function brandIds(object $user): array
    {
        return WasteBrand::query()
            ->whereHas('users', fn (Builder $query): Builder => $query->whereKey($user->getKey()))
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<int, int>
     */
    protected function outletIds(object $user): array
    {
        return WasteOutlet::query()
            ->whereHas('users', fn (Builder $query): Builder => $query->whereKey($user->getKey()))
            ->pluck('id')
            ->all();
    }

    protected function isGlobal(object $user): bool
    {
        if (! method_exists($user, 'can') || ! $user->can('view_any_waste_waste::report')) {
            return false;
        }

        return $this->brandIds($user) === [] && $this->outletIds($user) === [];
    }

    protected function scopeByBrands(Builder $query, ?object $user, string $column): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isGlobal($user)) {
            return $query;
        }

        $brandQuery = WasteBrand::query()->select('id');

        return $query->whereIn($column, $this->scopeBrands($brandQuery, $user));
    }

    /**
     * @return array<int, int>
     */
    protected function visibleBrandIds(object $user): array
    {
        return $this->scopeBrands(WasteBrand::query(), $user)->pluck('id')->all();
    }
}
