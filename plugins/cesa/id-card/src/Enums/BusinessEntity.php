<?php

namespace Cesa\IdCard\Enums;

use Filament\Support\Contracts\HasLabel;

enum BusinessEntity: string implements HasLabel
{
    case Smi = 'smi';
    case Msi = 'msi';
    case Top = 'top';

    public function getLabel(): string
    {
        return strtoupper($this->value);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $entity): array => [$entity->value => $entity->getLabel()])->all();
    }
}
