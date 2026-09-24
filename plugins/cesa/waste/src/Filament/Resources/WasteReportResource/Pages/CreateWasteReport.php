<?php

namespace Cesa\Waste\Filament\Resources\WasteReportResource\Pages;

use Cesa\Waste\Filament\Resources\WasteReportResource;
use Cesa\Waste\Services\WasteReportService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWasteReport extends CreateRecord
{
    protected static string $resource = WasteReportResource::class;

    public function getSubheading(): ?string
    {
        return __('waste::waste.admin.hints.create');
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(WasteReportService::class)->saveByAdmin($data, null, filament()->auth()->user());
    }
}
