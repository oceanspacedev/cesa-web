<?php

namespace Cesa\Waste\Filament\Resources\WasteItemResource\Pages;

use Cesa\Waste\Filament\Resources\WasteItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWasteItems extends ManageRecords
{
    protected static string $resource = WasteItemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()->modalWidth('md')];
    }
}
