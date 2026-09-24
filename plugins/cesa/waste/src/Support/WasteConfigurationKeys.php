<?php

namespace Cesa\Waste\Support;

use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WasteConfigurationKeys
{
    public static function code(?string $name, int $maxLength = 50, bool $normalizeUnit = false): string
    {
        $readable = str_replace(['/', '\\', '-', '.'], ' ', trim((string) $name));
        $code = trim(Str::upper(Str::slug($readable, '_')), '_');

        if ($code === '') {
            return '';
        }

        if ($normalizeUnit) {
            $code = WasteUnit::normalizeCode($code) ?? $code;
        }

        return Str::limit($code, $maxLength, '');
    }

    public static function outletSlug(?string $brandCode, ?string $name): string
    {
        return Str::limit(Str::slug(trim($brandCode.' '.$name)), 100, '');
    }

    public static function approvalName(?int $brandId, ?int $outletId): string
    {
        $outletName = $outletId ? WasteOutlet::query()->whereKey($outletId)->value('name') : null;

        if (filled($outletName)) {
            return 'Persetujuan '.$outletName;
        }

        $brandName = $brandId ? WasteBrand::query()->whereKey($brandId)->value('name') : null;

        return filled($brandName) ? 'Persetujuan '.$brandName : '';
    }

    public static function assignCode(Model $model, int $maxLength, Builder $scope, bool $normalizeUnit = false): void
    {
        if (filled($model->getAttribute('code')) || blank($model->getAttribute('name'))) {
            return;
        }

        $model->setAttribute('code', self::unique(
            self::code((string) $model->getAttribute('name'), $maxLength, $normalizeUnit),
            $scope,
            $model->exists ? (int) $model->getKey() : null,
            $maxLength,
            'code',
        ));
    }

    public static function assignOutletSlug(WasteOutlet $outlet): void
    {
        if (filled($outlet->slug) || blank($outlet->name)) {
            return;
        }

        $brandCode = $outlet->relationLoaded('brand')
            ? $outlet->brand?->code
            : WasteBrand::query()->whereKey($outlet->brand_id)->value('code');

        $outlet->slug = self::unique(
            self::outletSlug(is_string($brandCode) ? $brandCode : null, $outlet->name),
            WasteOutlet::query()->where('brand_id', $outlet->brand_id),
            $outlet->exists ? (int) $outlet->getKey() : null,
            100,
            'slug',
        );
    }

    public static function unique(string $value, Builder $scope, ?int $ignoreId, int $maxLength, string $column): string
    {
        if ($value === '') {
            return '';
        }

        $candidate = $value;
        $suffix = 2;

        while ((clone $scope)->when($ignoreId, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))->where($column, $candidate)->exists()) {
            $tail = $column === 'slug' ? '-'.$suffix : '_'.$suffix;
            $candidate = Str::limit($value, $maxLength - strlen($tail), '').$tail;
            $suffix++;
        }

        return $candidate;
    }
}
