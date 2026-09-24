<?php

namespace Cesa\Waste\Filament\Support;

use Filament\Tables\Columns\TextColumn;

class WasteActiveColumn
{
    public static function make(): TextColumn
    {
        return TextColumn::make('is_active')
            ->label('Status')
            ->badge()
            ->formatStateUsing(fn (mixed $state): string => filter_var($state, FILTER_VALIDATE_BOOLEAN) ? 'Aktif' : 'Nonaktif')
            ->color(fn (mixed $state): string => filter_var($state, FILTER_VALIDATE_BOOLEAN) ? 'success' : 'gray');
    }
}
