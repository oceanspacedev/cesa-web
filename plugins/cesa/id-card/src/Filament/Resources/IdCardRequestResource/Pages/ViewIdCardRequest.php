<?php

namespace Cesa\IdCard\Filament\Resources\IdCardRequestResource\Pages;

use Cesa\IdCard\Filament\Resources\IdCardRequestResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;

class ViewIdCardRequest extends ViewRecord
{
    protected static string $resource = IdCardRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
