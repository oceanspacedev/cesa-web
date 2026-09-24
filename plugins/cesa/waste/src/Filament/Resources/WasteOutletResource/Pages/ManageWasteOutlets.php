<?php

namespace Cesa\Waste\Filament\Resources\WasteOutletResource\Pages;

use Cesa\Waste\Filament\Resources\WasteOutletResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWasteOutlets extends ManageRecords
{
    protected static string $resource = WasteOutletResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()];
    }
}
