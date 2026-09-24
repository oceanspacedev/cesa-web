<?php

namespace Cesa\Waste\Filament\Resources\WasteCategoryResource\Pages;

use Cesa\Waste\Filament\Resources\WasteCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWasteCategories extends ManageRecords
{
    protected static string $resource = WasteCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()];
    }
}
