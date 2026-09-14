<?php

namespace Cesa\ExitClearance\Livewire;

use Cesa\ExitClearance\Models\Approver;
use Cesa\ExitClearance\Models\Request as ExitClearanceRequest;
use Cesa\ExitClearance\Services\ExitClearanceNotificationService;
use Cesa\ExitClearance\Services\ExitClearanceRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\SimplePage;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Webkul\PluginManager\Package;

class PublicExitClearanceApprovalPage extends SimplePage
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static string $layout = 'exit-clearance::layouts.form';

    protected string $view = 'exit-clearance::livewire.public-exit-clearance-approval-page';

    public ExitClearanceRequest $requestRecord;

    public Approver $approverRecord;

    public array $summary = [];

    public array $approvals = [];

    public array $currentApproval = [];

    public string $statusLabel = '';

    public bool $actionTaken = false;

    public ?array $data = [];

    protected ExitClearanceRequestService $requestService;

    protected ExitClearanceNotificationService $notificationService;

    public function boot(
        ExitClearanceRequestService $requestService,
        ExitClearanceNotificationService $notificationService,
    ): void {
        $this->requestService = $requestService;
        $this->notificationService = $notificationService;
    }

    public function mount(int|string $request, int|string $approver): void
    {
        if (! Package::isPluginInstalled('exit-clearance')) {
            abort(404);
        }

        $requestRecord = ExitClearanceRequest::query()
            ->with(['approvers', 'company', 'department'])
            ->find($request);

        $approverRecord = Approver::query()->find($approver);

        if (! $requestRecord || ! $approverRecord) {
            abort(404);
        }

        $pivot = $requestRecord->approvers->firstWhere('id', $approverRecord->id)?->pivot;

        if (! $pivot) {
            abort(404);
        }

        $this->requestRecord = $requestRecord;
        $this->approverRecord = $approverRecord;
        $this->currentApproval = [
            'status'      => $pivot->status,
            'notes'       => $pivot->notes,
            'approved_at' => $pivot->approved_at,
        ];

        $this->summary = $this->requestService->buildCategorizedSummary($this->requestRecord);
        $this->approvals = $this->requestService->buildApprovals($this->requestRecord);
        $this->statusLabel = $this->requestService->formatFormStatus($this->requestRecord->form_status);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('notes')
                    ->label(__('exit-clearance::livewire/public-exit-clearance-approval-page.notes_label'))
                    ->rows(4),
            ])
            ->statePath('data');
    }

    public function approve(): void
    {
        if (! $this->isPendingApproval()) {
            Notification::make()
                ->title(__('exit-clearance::livewire/public-exit-clearance-approval-page.cannot_process'))
                ->danger()
                ->send();

            return;
        }

        $notes = Arr::get($this->form->getState(), 'notes');

        $this->requestRecord->approvers()->updateExistingPivot($this->approverRecord->id, [
            'status'      => ExitClearanceRequestService::APPROVAL_APPROVED,
            'notes'       => $notes,
            'approved_at' => now(),
        ]);

        $this->requestRecord->refresh();
        $this->requestService->syncOverallStatus($this->requestRecord);
        $this->notificationService->notifyRequesterIfFinal($this->requestRecord);

        $this->refreshDisplayState();

        Notification::make()
            ->title(__('exit-clearance::livewire/public-exit-clearance-approval-page.approved_success'))
            ->success()
            ->send();
    }

    public function reject(): void
    {
        if (! $this->isPendingApproval()) {
            Notification::make()
                ->title(__('exit-clearance::livewire/public-exit-clearance-approval-page.cannot_process'))
                ->danger()
                ->send();

            return;
        }

        $notes = Arr::get($this->form->getState(), 'notes');

        $this->requestRecord->approvers()->updateExistingPivot($this->approverRecord->id, [
            'status'      => ExitClearanceRequestService::APPROVAL_REJECTED,
            'notes'       => $notes,
            'approved_at' => now(),
        ]);

        $this->requestRecord->refresh();
        $this->requestService->syncOverallStatus($this->requestRecord);
        $this->notificationService->notifyRequesterIfFinal($this->requestRecord);

        $this->refreshDisplayState();

        Notification::make()
            ->title(__('exit-clearance::livewire/public-exit-clearance-approval-page.rejected_success'))
            ->danger()
            ->send();
    }

    public function confirmApproveAction(): Action
    {
        return Action::make('confirmApprove')
            ->label(__('exit-clearance::livewire/public-exit-clearance-approval-page.approve'))
            ->button()
            ->color('success')
            ->icon('heroicon-m-check-circle')
            ->extraAttributes(['class' => 'w-full justify-center'])
            ->requiresConfirmation()
            ->modalHeading(__('exit-clearance::livewire/public-exit-clearance-approval-page.confirm.approve_heading'))
            ->modalDescription(__('exit-clearance::livewire/public-exit-clearance-approval-page.confirm.approve_description'))
            ->modalSubmitActionLabel(__('exit-clearance::livewire/public-exit-clearance-approval-page.approve'))
            ->modalIcon('heroicon-m-check-circle')
            ->modalIconColor('success')
            ->action(function (): void {
                $this->approve();
            });
    }

    public function confirmRejectAction(): Action
    {
        return Action::make('confirmReject')
            ->label(__('exit-clearance::livewire/public-exit-clearance-approval-page.reject'))
            ->button()
            ->color('danger')
            ->icon('heroicon-m-x-circle')
            ->extraAttributes(['class' => 'w-full justify-center'])
            ->requiresConfirmation()
            ->modalHeading(__('exit-clearance::livewire/public-exit-clearance-approval-page.confirm.reject_heading'))
            ->modalDescription(__('exit-clearance::livewire/public-exit-clearance-approval-page.confirm.reject_description'))
            ->modalSubmitActionLabel(__('exit-clearance::livewire/public-exit-clearance-approval-page.reject'))
            ->modalIcon('heroicon-m-x-circle')
            ->modalIconColor('danger')
            ->action(function (): void {
                $this->reject();
            });
    }

    public function isPendingApproval(): bool
    {
        $approvalStatus = $this->requestService->normalizeApprovalStatus($this->currentApproval['status'] ?? null);
        $formStatus = $this->requestService->normalizeFormStatus($this->requestRecord->form_status);

        return $approvalStatus === ExitClearanceRequestService::APPROVAL_PENDING && $formStatus === 'pending';
    }

    protected function refreshDisplayState(): void
    {
        $this->requestRecord->loadMissing('approvers', 'company', 'department');

        $pivot = $this->requestRecord->approvers->firstWhere('id', $this->approverRecord->id)?->pivot;
        $this->currentApproval = [
            'status'      => $pivot?->status,
            'notes'       => $pivot?->notes,
            'approved_at' => $pivot?->approved_at,
        ];

        $this->summary = $this->requestService->buildCategorizedSummary($this->requestRecord);
        $this->approvals = $this->requestService->buildApprovals($this->requestRecord);
        $this->statusLabel = $this->requestService->formatFormStatus($this->requestRecord->form_status);
        $this->actionTaken = true;
    }

    public function getHeading(): string
    {
        return __('exit-clearance::livewire/public-exit-clearance-approval-page.heading');
    }

    public function getSubheading(): string
    {
        $name = $this->requestRecord->name ?? null;

        return $name
            ? __('exit-clearance::livewire/public-exit-clearance-approval-page.subheading', ['name' => $name])
            : __('exit-clearance::livewire/public-exit-clearance-approval-page.subheading_default');
    }

    public function hasLogo(): bool
    {
        return false;
    }
}
