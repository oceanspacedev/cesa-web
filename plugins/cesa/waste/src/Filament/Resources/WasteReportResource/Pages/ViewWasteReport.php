<?php

namespace Cesa\Waste\Filament\Resources\WasteReportResource\Pages;

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Filament\Resources\WasteReportResource;
use Cesa\Waste\Services\WasteAccessService;
use Cesa\Waste\Services\WasteMisReviewService;
use Cesa\Waste\Services\WasteNotificationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ViewWasteReport extends ViewRecord
{
    protected static string $resource = WasteReportResource::class;

    public function getSubheading(): ?string
    {
        return __('waste::waste.admin.hints.view');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewEvidence')
                ->label('Lihat foto kejadian')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->visible(fn (): bool => Gate::forUser(filament()->auth()->user())->allows('view', $this->getRecord()))
                ->modalHeading('Bukti foto kejadian')
                ->modalWidth('5xl')
                ->modalContent(fn (): View => view('waste::admin-evidence', [
                    'report' => $this->getRecord()->load('latestVersion.events.evidences'),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Tutup'),
            Action::make('misApprove')
                ->label('Setujui')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->canReviewInternally())
                ->requiresConfirmation()
                ->modalHeading('Setujui laporan waste?')
                ->action(function (WasteMisReviewService $service): void {
                    try {
                        $service->approve($this->getRecord(), filament()->auth()->user());
                    } catch (ValidationException $exception) {
                        $this->notifyValidationFailure($exception);

                        return;
                    }

                    $this->getRecord()->refresh()->unsetRelation('latestVersion');
                    Notification::make()->title('Laporan disetujui.')->success()->send();
                }),
            Action::make('misReject')
                ->label('Tolak')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->canReviewInternally())
                ->schema([
                    Textarea::make('reason')
                        ->label('Alasan penolakan')
                        ->required()
                        ->maxLength(2000),
                ])
                ->modalHeading('Tolak laporan waste?')
                ->action(function (array $data, WasteMisReviewService $service): void {
                    try {
                        $service->reject($this->getRecord(), filament()->auth()->user(), (string) $data['reason']);
                    } catch (ValidationException $exception) {
                        $this->notifyValidationFailure($exception);

                        return;
                    }

                    $this->getRecord()->refresh()->unsetRelation('latestVersion');
                    Notification::make()->title('Laporan ditolak.')->success()->send();
                }),
            Action::make('retryNotifications')
                ->label('Kirim ulang pemberitahuan')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => app(WasteAccessService::class)->canManageBrand(filament()->auth()->user(), $this->getRecord()->brand)
                    && $this->getRecord()->notifications()->where('status', 'failed')->exists())
                ->requiresConfirmation()
                ->action(function (WasteNotificationService $service): void {
                    $count = $service->retryFailed($this->record, filament()->auth()->user());
                    Notification::make()
                        ->title($count > 0 ? "{$count} pemberitahuan dijadwalkan ulang" : 'Tidak ada pemberitahuan yang perlu dikirim ulang')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function notifyValidationFailure(ValidationException $exception): void
    {
        Notification::make()
            ->title((string) Arr::first(Arr::flatten($exception->errors())))
            ->danger()
            ->send();
    }

    protected function canReviewInternally(): bool
    {
        $report = $this->getRecord();
        $version = $report->latestVersion;

        return $report->status === WasteReportStatus::Pending
            && $version?->status === WasteReportStatus::Pending
            && Gate::forUser(filament()->auth()->user())->allows('review', $report);
    }
}
