<?php

namespace Cesa\Waste\Filament\Resources\WasteBrandResource\Pages;

use Cesa\Waste\Filament\Resources\WasteBrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWasteBrands extends ManageRecords
{
    protected static string $resource = WasteBrandResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()];
    }
}
