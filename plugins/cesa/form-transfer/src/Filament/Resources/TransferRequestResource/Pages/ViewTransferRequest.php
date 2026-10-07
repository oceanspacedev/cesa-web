<?php

namespace Cesa\FormTransfer\Filament\Resources\TransferRequestResource\Pages;

use Cesa\FormTransfer\Enums\ApprovalStatus;
use Cesa\FormTransfer\Enums\TransferRequestRealizationStatus;
use Cesa\FormTransfer\Filament\Resources\TransferRequestResource;
use Cesa\FormTransfer\Models\TransferRequest;
use Cesa\FormTransfer\Services\ApprovalWorkflowService;
use Cesa\FormTransfer\Services\TransferApprovalNotificationService;
use Cesa\FormTransfer\Services\TransferRequestPdfService;
use Cesa\FormTransfer\Support\TransferRequestAttachmentField;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ViewTransferRequest extends ViewRecord
{
    protected static string $resource = TransferRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->slideOver(),
            Actions\RestoreAction::make(),
            Actions\ForceDeleteAction::make(),
            Action::make('download-pdf')
                ->label(__('form-transfer::filament/resources/transfer-request/view.transfer_request.actions.download_pdf'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (TransferRequest $record) => app(TransferRequestPdfService::class)->download($record)),
            Action::make('resend-pending-approver')
                ->label(__('form-transfer::filament/resources/transfer-request/view.transfer_request.actions.resend_pending_approver'))
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading(__('form-transfer::filament/resources/transfer-request/view.transfer_request.actions.resend_notification_heading'))
                ->modalDescription(__('form-transfer::filament/resources/transfer-request/view.transfer_request.actions.resend_notification_description'))
                ->visible(fn (TransferRequest $record): bool => $this->hasPendingApprover($record) && Gate::allows('update', $record))
                ->action(function (TransferRequest $record): void {
                    Gate::authorize('update', $record);

                    if (! $record->needsApprovalReminder()) {
                        Notification::make()
                            ->title(__('form-transfer::filament/resources/transfer-request/view.transfer_request.notifications.reminder_not_needed_title'))
                            ->body(__('form-transfer::filament/resources/transfer-request/view.transfer_request.notifications.reminder_not_needed_body'))
                            ->warning()
                            ->send();

                        return;
                    }

                    $pending = $this->getCurrentPendingApproval($record);

                    if (! $pending) {
                        Notification::make()
                            ->title(__('form-transfer::filament/resources/transfer-request/view.transfer_request.notifications.no_pending_approver_title'))
                            ->body(__('form-transfer::filament/resources/transfer-request/view.transfer_request.notifications.no_pending_approver_body'))
                            ->warning()
                            ->send();

                        return;
                    }

                    $approval = $pending['approval'] ?? [];
                    $approverEmail = $approval['email'] ?? null;
                    $approverName = $approval['name'] ?? __('form-transfer::filament/resources/transfer-request/view.transfer_request.defaults.approver_name');

                    if (! $approverEmail) {
                        Notification::make()
                            ->title(__('form-transfer::filament/resources/transfer-request/view.transfer_request.notifications.empty_approver_email_title'))
                            ->body(__('form-transfer::filament/resources/transfer-request/view.transfer_request.notifications.empty_approver_email_body'))
                            ->warning()
                            ->send();

                        return;
                    }

                    $approvals = $record->approvals ?? [];
                    app(TransferApprovalNotificationService::class)->notifyApprover($record, $approval, $approvals);

                    if (isset($pending['index'], $approvals[$pending['index']])) {
                        $approvals[$pending['index']]['notified_at'] = now()->toISOString();
                        $record->approvals = $approvals;
                        $record->save();
                    }

                    Notification::make()
                        ->title(__('form-transfer::filament/resources/transfer-request/view.transfer_request.notifications.notification_resent_title'))
                        ->body(__('form-transfer::filament/resources/transfer-request/view.transfer_request.notifications.notification_resent_body', [
                            'approver' => $approverName,
                        ]))
                        ->success()
                        ->send();
                }),
            Action::make('realize-transfer')
                ->label(__('form-transfer::filament/resources/transfer-request/actions.realize_transfer'))
                ->icon('heroicon-m-banknotes')
                ->color('success')
                ->slideOver()
                ->modalWidth('md')
                ->visible(fn (TransferRequest $record): bool => Gate::allows('update', $record) && $record->canRecordAdditionalRealization())
                ->form([
                    Select::make('realization_status')
                        ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_status'))
                        ->options([
                            TransferRequestRealizationStatus::DONE->value      => __('form-transfer::filament/resources/transfer-request/actions.add_realization'),
                            TransferRequestRealizationStatus::CANCELLED->value => TransferRequestRealizationStatus::CANCELLED->getLabel(),
                        ])
                        ->default(TransferRequestRealizationStatus::DONE->value)
                        ->required()
                        ->live(),
                    TextInput::make('amount')
                        ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_amount'))
                        ->numeric()
                        ->prefix('Rp')
                        ->required(fn (Get $get): bool => $get('realization_status') === TransferRequestRealizationStatus::DONE->value)
                        ->visible(fn (Get $get): bool => $get('realization_status') === TransferRequestRealizationStatus::DONE->value),
                    DatePicker::make('realized_at')
                        ->label(__('form-transfer::filament/resources/transfer-request/fields.realized_at'))
                        ->native(false)
                        ->required(fn (Get $get): bool => $get('realization_status') === TransferRequestRealizationStatus::DONE->value)
                        ->visible(fn (Get $get): bool => $get('realization_status') === TransferRequestRealizationStatus::DONE->value),
                    Textarea::make('realization_notes')
                        ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_notes'))
                        ->rows(3)
                        ->required(fn (Get $get): bool => $get('realization_status') === TransferRequestRealizationStatus::CANCELLED->value),
                    TransferRequestAttachmentField::makeRealizationProof()
                        ->required(fn (Get $get): bool => $get('realization_status') === TransferRequestRealizationStatus::DONE->value)
                        ->visible(fn (Get $get): bool => $get('realization_status') === TransferRequestRealizationStatus::DONE->value),
                ])
                ->fillForm(fn (TransferRequest $record): array => [
                    'amount'             => null,
                    'realization_status' => TransferRequestRealizationStatus::DONE->value,
                    'realized_at'        => now()->toDateString(),
                    'realization_notes'  => null,
                ])
                ->action(function (TransferRequest $record, array $data): void {
                    Gate::authorize('update', $record);

                    $targetStatus = TransferRequestRealizationStatus::tryFrom((string) ($data['realization_status'] ?? ''));

                    if ($targetStatus === TransferRequestRealizationStatus::CANCELLED) {
                        $record->cancelRealization($data['realization_notes'] ?? null);

                        return;
                    }

                    $record->recordRealization([
                        'amount'      => $data['amount'] ?? null,
                        'realized_at' => $data['realized_at'] ?? null,
                        'proof_path'  => $data['realization_proof_path'] ?? null,
                        'notes'       => $data['realization_notes'] ?? null,
                        'user_id'     => Auth::id(),
                    ]);
                })
                ->modalHeading(__('form-transfer::filament/resources/transfer-request/actions.realize_transfer')),
            Action::make('edit-realizations')
                ->label(__('form-transfer::filament/resources/transfer-request/actions.edit_realization'))
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->slideOver()
                ->modalWidth('lg')
                ->visible(fn (TransferRequest $record): bool => Gate::allows('update', $record) && $record->realizations()->exists())
                ->form([
                    Repeater::make('realizations')
                        ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_history'))
                        ->schema([
                            Hidden::make('id'),
                            TextInput::make('amount')
                                ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_amount'))
                                ->numeric()
                                ->prefix('Rp')
                                ->required(),
                            DatePicker::make('realized_at')
                                ->label(__('form-transfer::filament/resources/transfer-request/fields.realized_at'))
                                ->native(false)
                                ->required(),
                            Textarea::make('notes')
                                ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_notes'))
                                ->rows(3)
                                ->nullable()
                                ->columnSpanFull(),
                            TransferRequestAttachmentField::makeRealizationProof('proof_path')
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->reorderable(false)
                        ->addActionLabel(__('form-transfer::filament/resources/transfer-request/actions.add_realization'))
                        ->columnSpanFull(),
                ])
                ->fillForm(fn (TransferRequest $record): array => [
                    'realizations' => $record->realizations()
                        ->get()
                        ->map(fn ($realization): array => [
                            'id'          => $realization->getKey(),
                            'amount'      => $realization->amount,
                            'realized_at' => $realization->realized_at?->toDateString(),
                            'proof_path'  => $realization->proof_path,
                            'notes'       => $realization->notes,
                        ])
                        ->values()
                        ->all(),
                ])
                ->action(function (TransferRequest $record, array $data): void {
                    Gate::authorize('update', $record);

                    Log::info('Transfer request realizations replace submitted.', [
                        'transfer_request_id' => $record->getKey(),
                        'user_id'             => Auth::id(),
                        'realizations'        => $data['realizations'] ?? null,
                    ]);

                    $record->replaceRealizations(
                        is_array($data['realizations'] ?? null) ? $data['realizations'] : [],
                        Auth::id(),
                    );
                })
                ->modalHeading(__('form-transfer::filament/resources/transfer-request/actions.edit_realization')),
        ];
    }

    protected function hasPendingApprover(TransferRequest $record): bool
    {
        if (! $record->needsApprovalReminder()) {
            return false;
        }

        return $this->getCurrentPendingApproval($record) !== null;
    }

    /**
     * @return array{index: int, approval: array}|null
     */
    protected function getCurrentPendingApproval(TransferRequest $record): ?array
    {
        $approvals = $record->approvals ?? [];

        if ($approvals === []) {
            return null;
        }

        $pending = app(ApprovalWorkflowService::class)->getCurrentPendingApproval($approvals);

        if (! $pending || ! isset($pending['approval'])) {
            return null;
        }

        $status = $pending['approval']['status'] ?? null;

        if ($status !== ApprovalStatus::PENDING->value) {
            return null;
        }

        return $pending;
    }
}
