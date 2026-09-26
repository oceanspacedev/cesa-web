<?php

namespace Cesa\Waste\Filament\Resources\WasteSectionResource\Pages;

use Cesa\Waste\Filament\Resources\WasteSectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWasteSections extends ManageRecords
{
    protected static string $resource = WasteSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()->modalWidth('md')];
    }
}
