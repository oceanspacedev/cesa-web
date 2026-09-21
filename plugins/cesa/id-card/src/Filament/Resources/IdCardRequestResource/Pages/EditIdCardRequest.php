<?php

namespace Cesa\IdCard\Filament\Resources\IdCardRequestResource\Pages;

use Cesa\IdCard\Filament\Resources\IdCardRequestResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditIdCardRequest extends EditRecord
{
    protected static string $resource = IdCardRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
