<?php

namespace Cesa\IdCard\Enums;

use Filament\Support\Contracts\HasLabel;

enum Position: string implements HasLabel
{
    case Sales = 'sales';
    case Courier = 'courier';

    public function getLabel(): string
    {
        return __('id-card::id-card.positions.'.$this->value);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $position): array => [$position->value => $position->getLabel()])->all();
    }
}
