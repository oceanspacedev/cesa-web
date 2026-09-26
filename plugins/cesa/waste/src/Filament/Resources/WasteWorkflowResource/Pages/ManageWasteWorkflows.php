<?php

namespace Cesa\Waste\Filament\Resources\WasteWorkflowResource\Pages;

use Cesa\Waste\Filament\Resources\WasteWorkflowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWasteWorkflows extends ManageRecords
{
    protected static string $resource = WasteWorkflowResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()->modalWidth('md')];
    }
}
