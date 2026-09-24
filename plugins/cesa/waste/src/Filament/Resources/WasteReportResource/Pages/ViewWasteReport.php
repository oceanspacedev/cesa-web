<?php

namespace Cesa\Waste\Filament\Resources\WasteReportResource\Pages;

use Cesa\Waste\Enums\WasteApprovalStatus;
use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Filament\Resources\WasteReportResource;
use Cesa\Waste\Services\WasteAccessService;
use Cesa\Waste\Services\WasteMisReviewService;
use Cesa\Waste\Services\WasteNotificationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

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
            Action::make('misReviewLines')
                ->label('Tandai SM / AUDIT (MIS)')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('primary')
                ->visible(fn (): bool => $this->canReviewExternalLines())
                ->modalHeading('Tandai pemeriksaan per barang')
                ->modalWidth('3xl')
                ->schema(fn (): array => $this->externalLineCheckSchema())
                ->fillForm(fn (): array => $this->externalLineCheckState())
                ->action(function (array $data, WasteMisReviewService $service): void {
                    $checks = [];
                    $isJchicken = strtoupper((string) $this->getRecord()->brand->code) === 'JCHICKEN';

                    foreach ($this->getRecord()->latestVersion->events()->with('lines')->get() as $event) {
                        foreach ($event->lines as $line) {
                            $checks[$line->getKey()] = $isJchicken
                                ? [
                                    'sm_checked'    => $data['line_'.$line->getKey().'_sm'] ?? null,
                                    'audit_checked' => $data['line_'.$line->getKey().'_audit'] ?? null,
                                ]
                                : ['audit_checked' => $data['line_'.$line->getKey().'_audit'] ?? null];
                        }
                    }

                    $service->updateExternalLineChecks($this->getRecord(), filament()->auth()->user(), $checks);
                    $this->getRecord()->refresh()->unsetRelation('latestVersion');
                    Notification::make()->title('Penanda MIS per barang tersimpan.')->success()->send();
                }),
            Action::make('misApprove')
                ->label('Setujui (MIS)')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->canReviewInternally())
                ->requiresConfirmation()
                ->modalHeading('Setujui laporan waste?')
                ->action(function (WasteMisReviewService $service): void {
                    $service->approve($this->getRecord(), filament()->auth()->user());
                    $this->getRecord()->refresh()->unsetRelation('latestVersion');
                    Notification::make()->title('Laporan disetujui MIS.')->success()->send();
                }),
            Action::make('misReject')
                ->label('Tolak (MIS)')
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
                    $service->reject($this->getRecord(), filament()->auth()->user(), (string) $data['reason']);
                    $this->getRecord()->refresh()->unsetRelation('latestVersion');
                    Notification::make()->title('Laporan ditolak MIS.')->success()->send();
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

    protected function canReviewInternally(): bool
    {
        $report = $this->getRecord();
        $version = $report->latestVersion;

        return $report->status === WasteReportStatus::Pending
            && $version?->status === WasteReportStatus::Pending
            && $version->approvals()->get()->every(fn ($approval): bool => $approval->status === WasteApprovalStatus::Approved)
            && Gate::forUser(filament()->auth()->user())->allows('review', $report);
    }

    protected function canReviewExternalLines(): bool
    {
        $report = $this->getRecord();

        return $this->canReviewInternally()
            && in_array(strtoupper((string) $report->brand->code), ['JCHICKEN', 'LUUCA'], true)
            && $report->latestVersion->approvals()->exists();
    }

    /**
     * @return array<int, Section>
     */
    protected function externalLineCheckSchema(): array
    {
        $sections = [];
        $isJchicken = strtoupper((string) $this->getRecord()->brand->code) === 'JCHICKEN';

        foreach ($this->getRecord()->latestVersion->events()->with('lines')->get() as $event) {
            $fields = [];

            foreach ($event->lines as $line) {
                $itemLabel = trim($line->item_code.' — '.$line->item_name);

                if ($isJchicken) {
                    $fields[] = Select::make('line_'.$line->getKey().'_sm')
                        ->label('SM — '.$itemLabel)
                        ->options(['1' => 'TRUE', '0' => 'FALSE'])
                        ->placeholder('Belum ditandai');
                }

                $fields[] = Select::make('line_'.$line->getKey().'_audit')
                    ->label('AUDIT — '.$itemLabel)
                    ->options(['1' => 'TRUE', '0' => 'FALSE'])
                    ->placeholder('Belum ditandai');
            }

            $sections[] = Section::make('Kejadian '.((int) $event->sequence + 1))
                ->description((string) $event->reason)
                ->schema($fields)
                ->columns($isJchicken ? 2 : 1);
        }

        return $sections;
    }

    /**
     * @return array<string, string|null>
     */
    protected function externalLineCheckState(): array
    {
        $state = [];
        $isJchicken = strtoupper((string) $this->getRecord()->brand->code) === 'JCHICKEN';

        foreach ($this->getRecord()->latestVersion->events()->with('lines')->get() as $event) {
            foreach ($event->lines as $line) {
                if ($isJchicken) {
                    $state['line_'.$line->getKey().'_sm'] = $this->checkedState($line->sm_checked);
                }

                $state['line_'.$line->getKey().'_audit'] = $this->checkedState($line->audit_checked);
            }
        }

        return $state;
    }

    protected function checkedState(?bool $checked): ?string
    {
        return $checked === null ? null : ($checked ? '1' : '0');
    }
}
