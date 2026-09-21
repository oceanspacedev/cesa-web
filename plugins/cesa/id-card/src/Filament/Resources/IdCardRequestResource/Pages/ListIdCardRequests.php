<?php

namespace Cesa\IdCard\Filament\Resources\IdCardRequestResource\Pages;

use Cesa\IdCard\Filament\Resources\IdCardRequestResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListIdCardRequests extends ListRecords
{
    protected static string $resource = IdCardRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('publicForm')
                ->label(__('id-card::id-card.actions.public_form'))
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (): string => route('id-card.public.form'))
                ->openUrlInNewTab(),
        ];
    }
}
