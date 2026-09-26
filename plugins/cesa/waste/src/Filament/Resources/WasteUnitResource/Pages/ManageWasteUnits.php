<?php

namespace Cesa\Waste\Filament\Resources\WasteUnitResource\Pages;

use Cesa\Waste\Filament\Resources\WasteUnitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWasteUnits extends ManageRecords
{
    protected static string $resource = WasteUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()->modalWidth('md')];
    }
}
