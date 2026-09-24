<?php

namespace Cesa\Waste\Filament\Resources\WasteReportResource\Pages;

use Cesa\Waste\Filament\Resources\WasteReportResource;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Services\WasteReportService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditWasteReport extends EditRecord
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

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var WasteReport $report */
        $report = $this->getRecord();
        $report->loadMissing('latestVersion.events.lines');

        $data['event_date'] = optional($report->event_date)->format('Y-m-d');
        if ($report->hasQaReporterPlaceholder()) {
            $data['reporter_name'] = $report->reporterNameForDisplay();
        }

        if ($report->hasQaSourceMarker() || $report->reporter_phone === '0000000000') {
            $data['reporter_phone'] = null;
        }

        if ($report->hasQaSourceMarker()) {
            $data['reporter_email'] = null;
        }
        $data['events'] = $report->latestVersion?->events->map(fn ($event): array => [
            'id'           => $event->getKey(),
            'section'      => $event->section,
            'category_id'  => $event->category_id,
            'reason'       => $event->reason,
            'pip_item_id'  => $event->pip_item_id,
            'pip_quantity' => $event->pip_quantity,
            'lines'        => $event->lines->map(fn ($line): array => [
                'id'            => $line->getKey(),
                'item_id'       => $line->item_id,
                'quantity'      => $line->quantity,
                'unit'          => $line->unit,
                'sm_checked'    => $line->sm_checked === null ? null : ($line->sm_checked ? '1' : '0'),
                'audit_checked' => $line->audit_checked === null ? null : ($line->audit_checked ? '1' : '0'),
            ])->all(),
        ])->all() ?? [];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var WasteReport $record */
        if ($record->hasQaReporterPlaceholder() && ($data['reporter_name'] ?? null) === __('waste::waste.qa_reporter')) {
            $data['reporter_name'] = $record->reporter_name;
        }

        if (($record->hasQaSourceMarker() || $record->reporter_phone === '0000000000') && blank($data['reporter_phone'] ?? null)) {
            $data['reporter_phone'] = $record->reporter_phone;
        }

        if ($record->hasQaSourceMarker() && blank($data['reporter_email'] ?? null)) {
            $data['reporter_email'] = $record->reporter_email;
        }

        return app(WasteReportService::class)->saveByAdmin($data, $record, filament()->auth()->user());
    }
}
