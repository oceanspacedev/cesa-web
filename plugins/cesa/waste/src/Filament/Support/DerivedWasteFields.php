<?php

namespace Cesa\Waste\Filament\Support;

use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Support\WasteConfigurationKeys;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class DerivedWasteFields
{
    public static function name(string $label = 'Nama', int $codeMax = 50, bool $slug = false, bool $normalizeUnit = false): TextInput
    {
        return TextInput::make('name')
            ->label($label)
            ->required()
            ->maxLength(255)
            ->live(onBlur: true)
            ->afterStateUpdated(function (?string $state, ?string $old, Get $get, Set $set) use ($codeMax, $slug, $normalizeUnit): void {
                self::replaceWhenAutomatic($get, $set, 'code', WasteConfigurationKeys::code($old, $codeMax, $normalizeUnit), WasteConfigurationKeys::code($state, $codeMax, $normalizeUnit));

                if ($slug) {
                    self::replaceWhenAutomatic($get, $set, 'slug', self::outletSlug($get('brand_id'), $old), self::outletSlug($get('brand_id'), $state));
                }
            });
    }

    public static function code(int $maxLength): TextInput
    {
        return TextInput::make('code')
            ->label('Kode')
            ->helperText('Terisi otomatis dari nama. Ubah hanya jika perlu.')
            ->maxLength($maxLength);
    }

    public static function outletSlugFromBrand(mixed $brandId, ?string $oldBrandId, Get $get, Set $set): void
    {
        self::replaceWhenAutomatic(
            $get,
            $set,
            'slug',
            self::outletSlug($oldBrandId, $get('name')),
            self::outletSlug($brandId, $get('name')),
        );
    }

    public static function replaceWhenAutomatic(Get $get, Set $set, string $field, string $previous, string $next): void
    {
        if ($next === '') {
            return;
        }

        $current = (string) $get($field);

        if ($current === '' || $current === $previous) {
            $set($field, $next);
        }
    }

    public static function outletSlug(mixed $brandId, ?string $name): string
    {
        $brandCode = $brandId ? WasteBrand::query()->whereKey($brandId)->value('code') : null;

        return WasteConfigurationKeys::outletSlug(is_string($brandCode) ? $brandCode : null, $name);
    }
}
