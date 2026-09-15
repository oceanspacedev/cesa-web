<?php

namespace Cesa\FormTransfer\Filament\Resources;

use Cesa\FormTransfer\Enums\ApprovalStatus;
use Cesa\FormTransfer\Enums\TransferRequestApprovalStatus;
use Cesa\FormTransfer\Enums\TransferRequestRealizationStatus;
use Cesa\FormTransfer\Enums\TransferRequestSubmissionStatus;
use Cesa\FormTransfer\Filament\Resources\TransferRequestResource\Pages\CreateTransferRequest;
use Cesa\FormTransfer\Filament\Resources\TransferRequestResource\Pages\EditTransferRequest;
use Cesa\FormTransfer\Filament\Resources\TransferRequestResource\Pages\ListTransferRequests;
use Cesa\FormTransfer\Filament\Resources\TransferRequestResource\Pages\ViewTransferRequest;
use Cesa\FormTransfer\Models\FormTransfer;
use Cesa\FormTransfer\Models\TransferApprovalWorkflow;
use Cesa\FormTransfer\Models\TransferRequest;
use Cesa\FormTransfer\Models\TransferRequestRealization;
use Cesa\FormTransfer\Services\TransferApprovalNotificationService;
use Cesa\FormTransfer\Services\TransferRequestPdfService;
use Cesa\FormTransfer\Services\TransferRequestService;
use Cesa\FormTransfer\Support\TransferRequestAttachmentField;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class TransferRequestResource extends FormTransferResource
{
    protected static ?string $model = TransferRequest::class;

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function getNavigationLabel(): string
    {
        return __('form-transfer::filament/resources/transfer-request/navigation.label');
    }

    public static function getNavigationBadge(): ?string
    {
        $query = static::getModel()::where('approval_status', TransferRequestApprovalStatus::PENDING)
            ->whereIn('realization_status', [
                TransferRequestRealizationStatus::PENDING->value,
                TransferRequestRealizationStatus::PARTIAL->value,
            ]);

        // Apply access control
        $accessibleIds = static::getAccessibleFormTransferIds();

        if ($accessibleIds !== null) {
            if (empty($accessibleIds)) {
                return '0';
            }
            $query->whereIn('form_transfer_id', $accessibleIds);
        }

        return (string) $query->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPluralModelLabel(): string
    {
        return __('form-transfer::filament/resources/transfer-request/navigation.plural');
    }

    public static function getModelLabel(): string
    {
        return __('form-transfer::filament/resources/transfer-request/navigation.singular');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.form-transfer');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);

        $accessibleIds = static::getAccessibleFormTransferIds();

        if ($accessibleIds === null) {
            return $query;
        }

        if ($accessibleIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('form_transfer_id', $accessibleIds);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                Group::make([
                    Section::make(__('form-transfer::filament/resources/transfer-request/fields.form_transfer'))
                        ->schema([
                            Select::make('form_transfer_id')
                                ->label(__('form-transfer::filament/resources/transfer-request/fields.form_transfer'))
                                ->relationship(
                                    'formTransfer',
                                    'name',
                                    function (Builder $query): Builder {
                                        $query
                                            ->whereNull($query->qualifyColumn('deleted_at'))
                                            ->where('is_active', true);

                                        $accessibleIds = static::getAccessibleFormTransferIds();

                                        if ($accessibleIds !== null) {
                                            if (empty($accessibleIds)) {
                                                return $query->whereRaw('1 = 0');
                                            }

                                            return $query->whereIn('id', $accessibleIds);
                                        }

                                        return $query;
                                    },
                                )
                                ->required()
                                ->searchable()
                                ->preload()
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                    $set('division_id', null);
                                    $set('division_name', null);
                                    $set('reference_note', null);

                                    if (! $state) {
                                        return;
                                    }
                                })
                                ->columnSpanFull(),
                        ]),
                    Section::make(__('form-transfer::filament/resources/transfer-request/forms.requester'))
                        ->schema([
                            TextInput::make('email')
                                ->label(__('form-transfer::filament/resources/transfer-request/fields.email'))
                                ->email()
                                ->maxLength(191)
                                ->required(),
                            TextInput::make('requester_name')
                                ->label(__('form-transfer::filament/resources/transfer-request/fields.requester_name'))
                                ->required()
                                ->maxLength(191),
                            Select::make('division_id')
                                ->label(__('form-transfer::filament/resources/transfer-request/fields.division'))
                                ->options(fn (Get $get): array => static::getDivisionOptions($get('form_transfer_id')))
                                ->searchable()
                                ->required(fn (Get $get): bool => ! empty(static::getDivisionOptions($get('form_transfer_id'))))
                                ->disabled(fn (Get $get): bool => ! $get('form_transfer_id'))
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                    $set('division_name', static::resolveDivisionName($state));
                                    $set('reference_note', null);

                                    $formTransferId = (int) ($get('form_transfer_id') ?? 0);

                                    if (! $formTransferId) {
                                        return;
                                    }
                                })
                                ->placeholder(__('form-transfer::filament/resources/transfer-request/placeholders.division')),
                            Hidden::make('division_name')
                                ->dehydrated(),
                        ])
                        ->columns(2)
                        ->visible(fn (Get $get): bool => filled($get('form_transfer_id'))),
                    Section::make(__('form-transfer::filament/resources/transfer-request/forms.bank'))
                        ->schema([
                            TextInput::make('account_number')
                                ->label(__('form-transfer::filament/resources/transfer-request/fields.account_number'))
                                ->required()
                                ->maxLength(191),
                            TextInput::make('account_name')
                                ->label(__('form-transfer::filament/resources/transfer-request/fields.account_name'))
                                ->required()
                                ->maxLength(191),
                            Select::make('bank_id')
                                ->label(__('form-transfer::filament/resources/transfer-request/fields.bank_name'))
                                ->options(fn (): array => static::getBankOptions())
                                ->required()
                                ->searchable()
                                ->placeholder(__('form-transfer::filament/resources/transfer-request/placeholders.bank')),
                        ])
                        ->columns(2)
                        ->visible(fn (Get $get): bool => filled($get('form_transfer_id'))),
                    Section::make(__('form-transfer::filament/resources/transfer-request/forms.transfer'))
                        ->schema([
                            TextInput::make('transfer_amount')
                                ->label(__('form-transfer::filament/resources/transfer-request/fields.transfer_amount'))
                                ->numeric()
                                ->prefix('Rp')
                                ->required()
                                ->rule('min:0')
                                ->columnSpanFull(),
                            Textarea::make('purpose')
                                ->label(__('form-transfer::filament/resources/transfer-request/fields.purpose'))
                                ->rows(3)
                                ->required()
                                ->columnSpanFull(),
                            TransferRequestAttachmentField::makeInvoice()
                                ->getUploadedFileUsing(fn (FileUpload $component, string $file, string|array|null $storedFileNames): ?array => static::buildAttachmentFileInfo(
                                    $component,
                                    $file,
                                    $storedFileNames,
                                    'invoice',
                                )),
                            TransferRequestAttachmentField::makeAccountAttachment()
                                ->getUploadedFileUsing(fn (FileUpload $component, string $file, string|array|null $storedFileNames): ?array => static::buildAttachmentFileInfo(
                                    $component,
                                    $file,
                                    $storedFileNames,
                                    'account-attachment',
                                )),
                        ])
                        ->columns(2)
                        ->visible(fn (Get $get): bool => filled($get('form_transfer_id'))),
                ])->columnSpan(2),

                Group::make([
                    Section::make(__('form-transfer::filament/resources/transfer-request/fields.submission_status'))
                        ->schema([
                            Select::make('submission_status')
                                ->label(__('form-transfer::filament/resources/transfer-request/fields.submission_status'))
                                ->options(static::getSubmissionStatusOptions())
                                ->default(TransferRequestSubmissionStatus::BARU->value)
                                ->formatStateUsing(fn ($state) => $state instanceof TransferRequestSubmissionStatus ? $state->value : $state)
                                ->required()
                                ->placeholder(__('form-transfer::filament/resources/transfer-request/placeholders.submission_status')),
                            Select::make('reference_note')
                                ->label(__('form-transfer::filament/resources/transfer-request/fields.reference_note'))
                                ->options(fn (Get $get): array => static::getReferenceNoteOptions($get('form_transfer_id')))
                                ->searchable()
                                ->disabled(fn (Get $get): bool => ! $get('form_transfer_id'))
                                ->required(fn (Get $get): bool => ! empty(static::getReferenceNoteOptions($get('form_transfer_id'))))
                                ->visible(fn (Get $get): bool => ! empty(static::getReferenceNoteOptions($get('form_transfer_id'))))
                                ->placeholder(__('form-transfer::filament/resources/transfer-request/placeholders.reference_note')),
                            Textarea::make('reference_note')
                                ->label(__('form-transfer::filament/resources/transfer-request/fields.reference_note'))
                                ->rows(3)
                                ->visible(fn (Get $get): bool => empty(static::getReferenceNoteOptions($get('form_transfer_id'))))
                                ->required(fn (Get $get): bool => empty(static::getReferenceNoteOptions($get('form_transfer_id')))),
                        ])
                        ->visible(fn (Get $get): bool => filled($get('form_transfer_id'))),
                ])->columnSpan(1),
            ])->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)
                ->schema([
                    Group::make()
                        ->columnSpan(2)
                        ->schema([
                            Section::make(__('form-transfer::filament/resources/transfer-request/forms.request'))
                                ->schema([
                                    Grid::make(3)
                                        ->schema([
                                            TextEntry::make('uid')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.uid'))
                                                ->icon('heroicon-o-hashtag')
                                                ->copyable()
                                                ->placeholder('—'),
                                            TextEntry::make('formTransfer.name')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.form_transfer'))
                                                ->icon('heroicon-o-document-text')
                                                ->placeholder('—'),
                                            TextEntry::make('submission_status')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.submission_status'))
                                                ->icon('heroicon-o-document-check')
                                                ->placeholder('—')
                                                ->formatStateUsing(fn ($state) => static::formatSubmissionStatusLabel($state))
                                                ->badge()
                                                ->color(fn ($state) => static::resolveSubmissionStatusColor($state)),
                                            TextEntry::make('approval_status')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.approval_status'))
                                                ->icon('heroicon-o-shield-check')
                                                ->placeholder('—')
                                                ->formatStateUsing(fn ($state) => static::formatApprovalStatusLabel($state))
                                                ->badge()
                                                ->color(fn ($state) => static::resolveApprovalStatusColor($state)),
                                            TextEntry::make('realization_status')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_status'))
                                                ->icon('heroicon-o-banknotes')
                                                ->placeholder('—')
                                                ->formatStateUsing(fn ($state) => static::formatRealizationStatusLabel($state))
                                                ->badge()
                                                ->color(fn ($state) => static::resolveRealizationStatusColor($state)),
                                            TextEntry::make('created_at')
                                                ->label(__('form-transfer::filament/resources/transfer-request/table.requested_at'))
                                                ->icon('heroicon-o-calendar')
                                                ->dateTime('d M Y H:i')
                                                ->placeholder('—'),
                                        ]),
                                ])
                                ->columns(1)
                                ->collapsible(),
                            Section::make(__('form-transfer::filament/resources/transfer-request/forms.requester'))
                                ->headerActions([
                                    Action::make('resend-requester-email')
                                        ->label('Kirim ulang email pengaju')
                                        ->icon('heroicon-o-paper-airplane')
                                        ->color('primary')
                                        ->requiresConfirmation()
                                        ->modalHeading('Kirim ulang email pengaju')
                                        ->modalDescription('Email status pengajuan akan dikirim ulang ke email pengaju.')
                                        ->visible(fn (TransferRequest $record): bool => filled($record->email))
                                        ->action(function (TransferRequest $record): void {
                                            if (blank($record->email)) {
                                                Notification::make()
                                                    ->title('Email pengaju kosong')
                                                    ->body('Notifikasi tidak dapat dikirim karena email pengaju belum diisi.')
                                                    ->warning()
                                                    ->send();

                                                return;
                                            }

                                            try {
                                                app(TransferApprovalNotificationService::class)->notifyRequesterWithCurrentStatusNow($record);
                                            } catch (\Throwable $exception) {
                                                report($exception);

                                                Notification::make()
                                                    ->title('Gagal mengirim email')
                                                    ->body('Terjadi kesalahan saat mengirim email. Silakan cek log dan konfigurasi mail.')
                                                    ->danger()
                                                    ->send();

                                                return;
                                            }

                                            Notification::make()
                                                ->title('Email dikirim ulang')
                                                ->body("Dikirim ke {$record->email}.")
                                                ->success()
                                                ->send();
                                        }),
                                ])
                                ->schema([
                                    Grid::make(3)
                                        ->schema([
                                            TextEntry::make('requester_name')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.requester_name'))
                                                ->icon('heroicon-o-user')
                                                ->placeholder('—'),
                                            TextEntry::make('email')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.email'))
                                                ->icon('heroicon-o-envelope')
                                                ->placeholder('—'),
                                            TextEntry::make('division_name')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.division'))
                                                ->icon('heroicon-o-building-office-2')
                                                ->placeholder('—'),
                                        ]),
                                ])
                                ->collapsible(),
                            Section::make(__('form-transfer::filament/resources/transfer-request/forms.bank'))
                                ->schema([
                                    Grid::make(3)
                                        ->schema([
                                            TextEntry::make('bank_display_name')
                                                ->label(__('form-transfer::filament/resources/transfer-request/table.bank_name'))
                                                ->icon('heroicon-o-building-library')
                                                ->placeholder('—'),
                                            TextEntry::make('account_number')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.account_number'))
                                                ->icon('heroicon-o-credit-card')
                                                ->placeholder('—'),
                                            TextEntry::make('account_name')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.account_name'))
                                                ->icon('heroicon-o-user')
                                                ->placeholder('—'),
                                        ]),
                                ])
                                ->collapsible(),
                            Section::make(__('form-transfer::filament/resources/transfer-request/forms.transfer'))
                                ->schema([
                                    Grid::make(3)
                                        ->schema([
                                            TextEntry::make('transfer_amount')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.transfer_amount'))
                                                ->icon('heroicon-o-banknotes')
                                                ->placeholder('—')
                                                ->formatStateUsing(fn ($state) => static::formatCurrency($state)),
                                            TextEntry::make('reference_note')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.reference_note'))
                                                ->icon('heroicon-o-clipboard-document')
                                                ->placeholder('—')
                                                ->wrap(),
                                            TextEntry::make('purpose')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.purpose'))
                                                ->icon('heroicon-o-document-text')
                                                ->placeholder('—')
                                                ->wrap(),
                                            TextEntry::make('invoice_path')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.invoice'))
                                                ->icon(fn (TextEntry $entry) => $entry->getRecord()?->invoice_path ? 'heroicon-o-paper-clip' : 'heroicon-o-no-symbol')
                                                ->color(fn (TextEntry $entry) => $entry->getRecord()?->invoice_path ? 'primary' : 'gray')
                                                ->listWithLineBreaks()
                                                ->placeholder(__('form-transfer::filament/resources/transfer-request/notifications.invoice_missing'))
                                                ->formatStateUsing(fn (?string $state, TransferRequest $record): string => static::formatAttachmentLabel($record, 'invoice_path', $state))
                                                ->url(fn (?string $state, TransferRequest $record): ?string => static::getAttachmentUrlFor($record, 'invoice_path', $state), true),
                                            TextEntry::make('account_attachment_path')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.account_attachment'))
                                                ->icon(fn (TextEntry $entry) => $entry->getRecord()?->account_attachment_path ? 'heroicon-o-paper-clip' : 'heroicon-o-no-symbol')
                                                ->color(fn (TextEntry $entry) => $entry->getRecord()?->account_attachment_path ? 'primary' : 'gray')
                                                ->listWithLineBreaks()
                                                ->placeholder(__('form-transfer::filament/resources/transfer-request/notifications.account_attachment_missing'))
                                                ->formatStateUsing(fn (?string $state, TransferRequest $record): string => static::formatAttachmentLabel($record, 'account_attachment_path', $state))
                                                ->url(fn (?string $state, TransferRequest $record): ?string => static::getAttachmentUrlFor($record, 'account_attachment_path', $state), true),
                                        ]),
                                ])
                                ->collapsible(),
                        ]),
                    Group::make()
                        ->columnSpan(1)
                        ->schema([
                            Section::make(__('form-transfer::filament/resources/transfer-request/forms.approvals'))
                                ->schema([
                                    RepeatableEntry::make('approvals')
                                        ->label(__('form-transfer::filament/resources/transfer-request/fields.approvals'))
                                        ->visible(fn (TransferRequest $record): bool => filled($record->approvals))
                                        ->schema([
                                            Grid::make(2)
                                                ->schema([
                                                    TextEntry::make('name')
                                                        ->label(__('form-transfer::filament/resources/transfer-request/fields.approver_name'))
                                                        ->icon('heroicon-o-user')
                                                        ->placeholder('—'),
                                                    TextEntry::make('status')
                                                        ->label(__('form-transfer::filament/resources/transfer-request/fields.approver_status'))
                                                        ->icon('heroicon-o-check-circle')
                                                        ->placeholder('—')
                                                        ->formatStateUsing(fn ($state) => static::formatApproverStatusLabel($state))
                                                        ->badge()
                                                        ->color(fn ($state) => static::resolveApproverStatusColor($state)),
                                                    TextEntry::make('task_id')
                                                        ->label(__('form-transfer::filament/resources/transfer-request/fields.approval_link'))
                                                        ->icon('heroicon-o-arrow-top-right-on-square')
                                                        ->placeholder('—')
                                                        ->formatStateUsing(fn (): string => __('form-transfer::filament/resources/transfer-request/actions.open_approval_page'))
                                                        ->badge()
                                                        ->color('primary')
                                                        ->url(fn ($state, Get $get): ?string => static::getPendingPublicApprovalUrlFor($state, $get('status')), true)
                                                        ->visible(fn ($state, Get $get): bool => filled(static::getPendingPublicApprovalUrlFor($state, $get('status'))))
                                                        ->columnSpanFull(),
                                                ]),
                                        ])
                                        ->columns(1),
                                ])
                                ->collapsible(),
                            Section::make(__('form-transfer::filament/resources/transfer-request/forms.realisasi'))
                                ->schema([
                                    Grid::make(2)
                                        ->schema([
                                            TextEntry::make('realization_status')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_status'))
                                                ->icon('heroicon-o-adjustments-vertical')
                                                ->placeholder('—')
                                                ->formatStateUsing(fn ($state) => static::formatRealizationStatusLabel($state))
                                                ->badge()
                                                ->color(fn ($state) => static::resolveRealizationStatusColor($state)),
                                            TextEntry::make('realized_at')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.realized_at'))
                                                ->icon('heroicon-o-check-circle')
                                                ->dateTime('d M Y H:i')
                                                ->placeholder('—'),
                                            TextEntry::make('realized_amount')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.realized_amount'))
                                                ->icon('heroicon-o-banknotes')
                                                ->formatStateUsing(fn ($state) => static::formatCurrency($state))
                                                ->placeholder('—'),
                                            TextEntry::make('remaining_realization_amount')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.remaining_realization_amount'))
                                                ->icon('heroicon-o-calculator')
                                                ->formatStateUsing(fn ($state) => static::formatCurrency($state))
                                                ->placeholder('—'),
                                            TextEntry::make('realization_notes')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_notes'))
                                                ->icon('heroicon-o-document-text')
                                                ->placeholder('—')
                                                ->columnSpan(2)
                                                ->wrap(),
                                            TextEntry::make('realization_proof_path')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_proof'))
                                                ->badge()
                                                ->icon(fn (TextEntry $entry) => $entry->getRecord()?->realization_proof_path ? 'heroicon-o-paper-clip' : 'heroicon-o-no-symbol')
                                                ->color(fn (TextEntry $entry) => $entry->getRecord()?->realization_proof_path ? 'primary' : 'gray')
                                                ->formatStateUsing(fn ($state) => $state
                                                    ? __('form-transfer::filament/resources/transfer-request/notifications.view_attachment')
                                                    : __('form-transfer::filament/resources/transfer-request/notifications.realization_proof_missing'))
                                                ->url(fn (TextEntry $entry) => static::getAttachmentUrlFor($entry->getRecord(), 'realization_proof_path'), true),
                                            RepeatableEntry::make('realizations')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_history'))
                                                ->visible(fn (TransferRequest $record): bool => $record->realizations()->exists())
                                                ->schema([
                                                    Grid::make(2)
                                                        ->schema([
                                                            TextEntry::make('amount')
                                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_amount'))
                                                                ->formatStateUsing(fn ($state) => static::formatCurrency($state))
                                                                ->placeholder('—'),
                                                            TextEntry::make('realized_at')
                                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.realized_at'))
                                                                ->date('d M Y')
                                                                ->placeholder('—'),
                                                            TextEntry::make('notes')
                                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_notes'))
                                                                ->placeholder('—')
                                                                ->columnSpanFull()
                                                                ->wrap(),
                                                            TextEntry::make('proof_path')
                                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_proof'))
                                                                ->badge()
                                                                ->formatStateUsing(fn ($state) => $state
                                                                    ? __('form-transfer::filament/resources/transfer-request/notifications.view_attachment')
                                                                    : __('form-transfer::filament/resources/transfer-request/notifications.realization_proof_missing'))
                                                                ->url(fn ($state, mixed $record): ?string => $record instanceof TransferRequestRealization
                                                                    ? static::getRealizationProofUrlFor($record)
                                                                    : null, true),
                                                        ]),
                                                ])
                                                ->columns(1)
                                                ->columnSpan(2),
                                        ]),
                                ])
                                ->collapsible(),
                            Section::make(__('form-transfer::filament/resources/transfer-request/forms.system_meta'))
                                ->schema([
                                    Grid::make(2)
                                        ->schema([
                                            TextEntry::make('status_response_id')
                                                ->label(__('form-transfer::filament/resources/transfer-request/fields.status_response_id'))
                                                ->icon('heroicon-o-identification')
                                                ->color(fn ($state): string => filled(static::getPublicProgressUrlFor($state)) ? 'primary' : 'gray')
                                                ->copyable()
                                                ->url(fn ($state): ?string => static::getPublicProgressUrlFor($state), true)
                                                ->placeholder('—'),
                                            TextEntry::make('created_at')
                                                ->label(__('form-transfer::filament/resources/transfer-request/table.requested_at'))
                                                ->icon('heroicon-o-calendar')
                                                ->dateTime('d M Y H:i')
                                                ->placeholder('—'),
                                        ]),
                                ])
                                ->compact()
                                ->collapsible(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('finance_followup_copy')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.copy_followup'))
                    ->state(fn (): string => __('form-transfer::filament/resources/transfer-request/table.copy_followup'))
                    ->badge()
                    ->color('gray')
                    ->icon('heroicon-o-clipboard-document')
                    ->copyable()
                    ->copyableState(fn (TransferRequest $record): string => static::buildFinanceFollowupTemplate($record))
                    ->alignCenter(),
                TextColumn::make('uid')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.uid'))
                    ->copyable()
                    ->icon('heroicon-o-clipboard-document')
                    ->iconPosition('after')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('requester_name')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.requester_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('formTransfer.name')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.form_transfer'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('division_name')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.division'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('email')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.email'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('bank_display_name')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.bank_name'))
                    ->formatStateUsing(fn ($record): ?string => $record->bank_display_name),
                TextColumn::make('account_number')
                    ->label(__('form-transfer::filament/resources/transfer-request/fields.account_number'))
                    ->copyable()
                    ->icon('heroicon-o-clipboard-document')
                    ->iconPosition('after')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('transfer_amount')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.transfer_amount'))
                    ->formatStateUsing(fn (?string $state): ?string => $state !== null
                        ? 'Rp '.number_format((float) $state, 2, ',', '.')
                        : null)
                    ->copyable()
                    ->copyableState(fn (?string $state): ?string => $state !== null
                        ? 'Rp '.number_format((float) $state, 2, ',', '.')
                        : null)
                    ->icon('heroicon-o-clipboard-document')
                    ->iconPosition('after')
                    ->sortable()
                    ->extraAttributes(['class' => 'text-right']),
                TextColumn::make('purpose')
                    ->label(__('form-transfer::filament/resources/transfer-request/fields.purpose'))
                    ->limit(50)
                    ->wrap()
                    ->searchable()
                    ->toggleable(),
                BadgeColumn::make('submission_status')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.submission_status'))
                    ->formatStateUsing(fn (TransferRequestSubmissionStatus $state): string => static::getSubmissionStatusOptions()[$state->value] ?? $state->value)
                    ->colors([
                        'primary' => TransferRequestSubmissionStatus::BARU,
                        'warning' => TransferRequestSubmissionStatus::REVISI,
                    ])
                    ->sortable()
                    ->searchable(),
                BadgeColumn::make('approval_status')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.approval_status'))
                    ->formatStateUsing(fn (TransferRequestApprovalStatus $state): string => static::getApprovalStatusOptions()[$state->value] ?? $state->value)
                    ->colors([
                        'warning' => TransferRequestApprovalStatus::PENDING,
                        'success' => TransferRequestApprovalStatus::APPROVED,
                        'danger'  => TransferRequestApprovalStatus::REJECTED,
                    ])
                    ->sortable()
                    ->searchable(),
                BadgeColumn::make('realization_status')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.realization_status'))
                    ->formatStateUsing(fn (TransferRequestRealizationStatus $state): string => static::getRealizationStatusOptions()[$state->value] ?? $state->value)
                    ->colors([
                        'warning' => TransferRequestRealizationStatus::PENDING,
                        'info'    => TransferRequestRealizationStatus::PARTIAL,
                        'success' => TransferRequestRealizationStatus::DONE,
                        'danger'  => TransferRequestRealizationStatus::CANCELLED,
                    ])
                    ->sortable()
                    ->searchable(),
                TextColumn::make('realized_amount')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.realized_amount'))
                    ->formatStateUsing(fn (?string $state): ?string => $state !== null
                        ? 'Rp '.number_format((float) $state, 2, ',', '.')
                        : null)
                    ->sortable()
                    ->extraAttributes(['class' => 'text-right'])
                    ->toggleable(),
                TextColumn::make('remaining_realization_amount')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.remaining_realization_amount'))
                    ->formatStateUsing(fn (?string $state): ?string => $state !== null
                        ? 'Rp '.number_format((float) $state, 2, ',', '.')
                        : null)
                    ->extraAttributes(['class' => 'text-right'])
                    ->toggleable(),
                TextColumn::make('realization_notes')
                    ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_notes'))
                    ->limit(50)
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('realized_at')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.realized_at'))
                    ->date()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.requested_at'))
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('form_transfer_id')
                    ->label(__('form-transfer::filament/resources/transfer-request/fields.form_transfer'))
                    ->relationship('formTransfer', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('division_id')
                    ->label(__('form-transfer::filament/resources/transfer-request/fields.division'))
                    ->relationship('division', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('bank_id')
                    ->label(__('form-transfer::filament/resources/transfer-request/table.bank_name'))
                    ->options(static::getBankOptions()),
                SelectFilter::make('submission_status')
                    ->label(__('form-transfer::filament/resources/transfer-request/fields.submission_status'))
                    ->options(static::getSubmissionStatusOptions()),
                SelectFilter::make('approval_status')
                    ->label(__('form-transfer::filament/resources/transfer-request/fields.approval_status'))
                    ->options(static::getApprovalStatusOptions()),
                SelectFilter::make('realization_status')
                    ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_status'))
                    ->options(static::getRealizationStatusOptions()),
                SelectFilter::make('user_id')
                    ->label(__('form-transfer::filament/resources/transfer-request/fields.handler'))
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
                TrashedFilter::make(),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make()->slideOver(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
                Action::make('realize-transfer')
                    ->label(__('form-transfer::filament/resources/transfer-request/actions.realize_transfer'))
                    ->icon('heroicon-m-banknotes')
                    ->color('success')
                    ->slideOver()
                    ->modalWidth('md')
                    ->visible(fn (TransferRequest $record): bool => $record->canRecordAdditionalRealization())
                    ->form([
                        ToggleButtons::make('realization_status')
                            ->label(__('form-transfer::filament/resources/transfer-request/fields.realization_status'))
                            ->options([
                                TransferRequestRealizationStatus::DONE->value      => __('form-transfer::filament/resources/transfer-request/actions.add_realization'),
                                TransferRequestRealizationStatus::CANCELLED->value => TransferRequestRealizationStatus::CANCELLED->getLabel(),
                            ])
                            ->colors([
                                'success' => TransferRequestRealizationStatus::DONE->value,
                                'danger'  => TransferRequestRealizationStatus::CANCELLED->value,
                            ])
                            ->default(TransferRequestRealizationStatus::DONE->value)
                            ->required()
                            ->inline()
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
                            ->required(fn (Get $get): bool => $get('realization_status') === TransferRequestRealizationStatus::CANCELLED->value)
                            ->nullable(),
                        TransferRequestAttachmentField::makeRealizationProof()
                            ->required(fn (Get $get): bool => $get('realization_status') === TransferRequestRealizationStatus::DONE->value)
                            ->visible(fn (Get $get): bool => $get('realization_status') === TransferRequestRealizationStatus::DONE->value),
                    ])
                    ->fillForm(fn (TransferRequest $record): array => [
                        'amount'             => null,
                        'realized_at'        => now()->toDateString(),
                        'realization_notes'  => null,
                        'realization_status' => TransferRequestRealizationStatus::DONE->value,
                    ])
                    ->action(function (TransferRequest $record, array $data): void {
                        $realizationStatus = TransferRequestRealizationStatus::tryFrom((string) ($data['realization_status'] ?? ''))
                            ?? TransferRequestRealizationStatus::DONE;

                        if ($realizationStatus === TransferRequestRealizationStatus::CANCELLED) {
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
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkAction::make('download-pdf-bulk')
                    ->label(__('form-transfer::filament/resources/transfer-request/actions.download_selected_pdfs'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->authorizeIndividualRecords('view')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => app(TransferRequestPdfService::class)->downloadBulkArchive($records)),
                DeleteBulkAction::make(),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'formTransfer',
                'division',
                'bank',
                'user',
                'company',
                'realizations',
            ]))
            ->defaultSort('created_at', 'desc');
    }

    protected static function buildFinanceFollowupTemplate(TransferRequest $record): string
    {
        $division = $record->division_name
            ?: ($record->relationLoaded('division') ? ($record->division?->name ?? null) : null);

        $bank = $record->bank_display_name
            ?: ($record->relationLoaded('bank') ? ($record->bank?->display_name ?? $record->bank?->name ?? null) : null);

        $invoiceAttachments = static::formatFinanceFollowupAttachmentList(
            $record,
            'invoice_path',
            __('form-transfer::filament/resources/transfer-request/notifications.invoice_missing'),
        );

        $accountAttachments = static::formatFinanceFollowupAttachmentList(
            $record,
            'account_attachment_path',
            __('form-transfer::filament/resources/transfer-request/notifications.account_attachment_missing'),
        );

        $submissionStatus = static::formatSubmissionStatusLabel($record->submission_status);
        $approvalStatus = static::formatApprovalStatusLabel($record->approval_status);
        $realizationStatus = static::formatRealizationStatusLabel($record->realization_status);
        $formTransferName = $record->formTransfer?->name;

        return implode("\n", [
            '*DATA PENGAJUAN TRANSFER* - '.($formTransferName ?: '—'),
            '',
            '*Nama Pengaju:* '.($record->requester_name ?: '—'),
            '*Divisi:* '.($division ?: '—'),
            '*Tujuan Rekening:* '.($record->account_number ?: '—'),
            '*Nama Pemilik Rekening:* '.($record->account_name ?: '—'),
            '*Tujuan Bank:* '.($bank ?: '—'),
            '*Nominal:* '.(static::formatCurrency($record->transfer_amount) ?: '—'),
            '*Tujuan Pengajuan:* '.($record->purpose ?: '—'),
            '*ReffoNote:* '.($record->reference_note ?: '—'),
            '*Lampiran Invoice:* '.$invoiceAttachments,
            '*Lampiran Rekening:* '.$accountAttachments,
            '*UID:* '.($record->uid ?: '—'),
            "*Status:* {$submissionStatus}",
        ]);
    }

    protected static function formatFinanceFollowupAttachmentList(
        TransferRequest $record,
        string $attribute,
        string $emptyLabel,
    ): string {
        $paths = TransferRequest::normalizeAttachmentPaths($record->{$attribute});

        if ($paths === []) {
            return $emptyLabel;
        }

        $links = [];

        foreach ($paths as $path) {
            $url = static::getAttachmentUrlFor($record, $attribute, $path);

            if (! $url && filter_var($path, FILTER_VALIDATE_URL)) {
                $url = $path;
            }

            if ($url) {
                $links[] = $url;

                continue;
            }

            $fileName = basename($path);
            $links[] = $fileName !== '' ? $fileName : $path;
        }

        return implode(', ', $links);
    }

    public static function getRelations(): array
    {
        return [];
    }

    /**
     * Build custom file information for attachments to support FTP downloads.
     */
    protected static function buildAttachmentFileInfo(
        FileUpload $component,
        string $file,
        string|array|null $storedFileNames,
        string $attachment
    ): ?array {
        $storedName = is_array($storedFileNames)
            ? ($storedFileNames[$file] ?? null)
            : $storedFileNames;

        $name = $storedName ?? basename($file);
        $url = null;

        $record = $component->getRecord();

        if ($record instanceof TransferRequest && filled($record->status_response_id)) {
            $fileIndex = null;
            $attribute = match ($attachment) {
                'invoice'            => 'invoice_path',
                'account-attachment' => 'account_attachment_path',
                'realization-proof'  => 'realization_proof_path',
                default              => null,
            };

            if ($attribute) {
                $paths = TransferRequest::normalizeAttachmentPaths($record->{$attribute});
                $fileIndex = static::resolveAttachmentIndex($paths, $file);
            }

            $routeParameters = [
                'statusResponseId' => $record->status_response_id,
                'attachment'       => $attachment,
            ];

            if ($fileIndex !== null) {
                $routeParameters['file'] = $fileIndex;
            }

            try {
                $url = URL::route(
                    'form-transfer.public.attachments.download',
                    $routeParameters,
                );
            } catch (\Throwable $exception) {
                $url = null;
            }
        }

        return [
            'name' => $name,
            'size' => 0,
            'type' => null,
            'url'  => $url,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListTransferRequests::route('/'),
            'create' => CreateTransferRequest::route('/create'),
            'view'   => ViewTransferRequest::route('/{record}'),
            'edit'   => EditTransferRequest::route('/{record}/edit'),
        ];
    }

    protected static function getSubmissionStatusOptions(): array
    {
        return TransferRequestSubmissionStatus::getOptions();
    }

    protected static function getApprovalStatusOptions(): array
    {
        return TransferRequestApprovalStatus::getOptions();
    }

    protected static function getApproverStatusOptions(): array
    {
        return ApprovalStatus::getOptions();
    }

    protected static function getRealizationStatusOptions(): array
    {
        return TransferRequestRealizationStatus::getOptions();
    }

    protected static function resolveDefaultWorkflow(?int $formTransferId, ?int $divisionId): ?TransferApprovalWorkflow
    {
        if (! $formTransferId) {
            return null;
        }

        return TransferApprovalWorkflow::query()
            ->where('form_transfer_id', $formTransferId)
            ->where('is_active', true)
            ->when(
                $divisionId,
                fn ($query): mixed => $query->where(function ($query) use ($divisionId): void {
                    $query->whereNull('division_id')
                        ->orWhere('division_id', $divisionId);
                }),
                fn ($query): mixed => $query->whereNull('division_id')
            )
            ->orderByRaw('division_id is null asc')
            ->orderBy('id')
            ->first();
    }

    protected static function requestService(): TransferRequestService
    {
        return app(TransferRequestService::class);
    }

    /**
     * Get accessible form transfer IDs for the current user.
     * Returns null if user has full access (default user, admin role, or has_all_form_transfer_access).
     * Returns array of IDs if user has restricted access.
     */
    protected static function getAccessibleFormTransferIds(): ?array
    {
        $user = Auth::user();

        if (! $user) {
            return [];
        }

        if (! SchemaFacade::hasTable('form_transfer_user_accesses')) {
            return null;
        }

        // Default users, admin-role users, and manual overrides bypass access control.
        if (method_exists($user, 'hasAllFormTransferAccess') && $user->hasAllFormTransferAccess()) {
            return null;
        }

        $accessibleIds = static::getOpenAccessFormTransferIds();

        if (method_exists($user, 'getAccessibleFormTransferIds')) {
            $accessibleIds = array_values(array_unique([
                ...$accessibleIds,
                ...$user->getAccessibleFormTransferIds(),
            ]));
        }

        return $accessibleIds;
    }

    /**
     * @return array<int, int>
     */
    protected static function getOpenAccessFormTransferIds(): array
    {
        return FormTransfer::query()
            ->doesntHave('allowedUsers')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    protected static function getDivisionOptions(?int $formTransferId): array
    {
        return static::requestService()->getDivisionOptions(
            $formTransferId ? (int) $formTransferId : null
        );
    }

    protected static function resolveDivisionName(?string $divisionId): ?string
    {
        return static::requestService()->resolveDivisionName($divisionId);
    }

    protected static function getBankOptions(): array
    {
        return static::requestService()->getBankOptions();
    }

    protected static function getReferenceNoteOptions(?int $formTransferId): array
    {
        return static::requestService()->getReferenceNoteOptions(
            $formTransferId ? (int) $formTransferId : null
        );
    }

    protected static function getWorkflowOptions(?int $formTransferId, ?int $divisionId): array
    {
        return static::requestService()->getWorkflowOptions(
            $formTransferId ? (int) $formTransferId : null,
            $divisionId ? (int) $divisionId : null
        );
    }

    protected static function prepareApprovalsFromWorkflow(int $workflowId, array $currentApprovals = []): array
    {
        return static::requestService()->prepareApprovalsFromWorkflow($workflowId, $currentApprovals);
    }

    protected static function formatSubmissionStatusLabel(mixed $state): string
    {
        $status = static::resolveSubmissionStatusEnum($state);

        if ($status) {
            return $status->getLabel();
        }

        return $state !== null ? Str::headline((string) $state) : '—';
    }

    protected static function resolveSubmissionStatusColor(mixed $state): string
    {
        return static::resolveSubmissionStatusEnum($state)?->getColor() ?? 'gray';
    }

    protected static function resolveSubmissionStatusEnum(mixed $state): ?TransferRequestSubmissionStatus
    {
        if ($state instanceof TransferRequestSubmissionStatus) {
            return $state;
        }

        if ($state === null) {
            return null;
        }

        return TransferRequestSubmissionStatus::tryFrom((string) $state);
    }

    protected static function formatApprovalStatusLabel(mixed $state): string
    {
        $status = static::resolveApprovalStatusEnum($state);

        if ($status) {
            return $status->getLabel();
        }

        return $state !== null ? Str::headline((string) $state) : '—';
    }

    protected static function resolveApprovalStatusColor(mixed $state): string
    {
        return static::resolveApprovalStatusEnum($state)?->getColor() ?? 'gray';
    }

    protected static function resolveApprovalStatusEnum(mixed $state): ?TransferRequestApprovalStatus
    {
        if ($state instanceof TransferRequestApprovalStatus) {
            return $state;
        }

        if ($state === null) {
            return null;
        }

        return TransferRequestApprovalStatus::tryFrom((string) $state);
    }

    protected static function formatRealizationStatusLabel(mixed $state): string
    {
        $status = static::resolveRealizationStatusEnum($state);

        if ($status) {
            return $status->getLabel();
        }

        return $state !== null ? Str::headline((string) $state) : '—';
    }

    protected static function resolveRealizationStatusColor(mixed $state): string
    {
        return static::resolveRealizationStatusEnum($state)?->getColor() ?? 'gray';
    }

    protected static function resolveRealizationStatusEnum(mixed $state): ?TransferRequestRealizationStatus
    {
        if ($state instanceof TransferRequestRealizationStatus) {
            return $state;
        }

        if ($state === null) {
            return null;
        }

        return TransferRequestRealizationStatus::tryFrom((string) $state);
    }

    protected static function formatApproverStatusLabel(mixed $state): string
    {
        $status = static::resolveApproverStatusEnum($state);

        if ($status) {
            return $status->getLabel();
        }

        return $state !== null ? Str::headline((string) $state) : '—';
    }

    protected static function resolveApproverStatusColor(mixed $state): string
    {
        return static::resolveApproverStatusEnum($state)?->getColor() ?? 'gray';
    }

    protected static function resolveApproverStatusEnum(mixed $state): ?ApprovalStatus
    {
        if ($state instanceof ApprovalStatus) {
            return $state;
        }

        if ($state === null) {
            return null;
        }

        return ApprovalStatus::tryFrom((string) $state);
    }

    protected static function formatCurrency(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return 'Rp '.number_format((float) $value, 2, ',', '.');
    }

    /**
     * @param  array<int, string>  $paths
     */
    protected static function resolveAttachmentIndex(array $paths, ?string $path): ?int
    {
        if ($path === null) {
            return $paths !== [] ? 0 : null;
        }

        $index = array_search($path, $paths, true);

        return is_int($index) ? $index : null;
    }

    protected static function formatAttachmentLabel(TransferRequest $record, string $attribute, ?string $path): string
    {
        if (! $path) {
            return '';
        }

        $paths = TransferRequest::normalizeAttachmentPaths($record->{$attribute});
        $index = static::resolveAttachmentIndex($paths, $path);

        $labelBase = match ($attribute) {
            'invoice_path'            => __('form-transfer::filament/resources/transfer-request/fields.invoice'),
            'account_attachment_path' => __('form-transfer::filament/resources/transfer-request/fields.account_attachment'),
            default                   => __('form-transfer::filament/resources/transfer-request/notifications.view_attachment'),
        };

        if ($index === null) {
            return $labelBase;
        }

        return sprintf('%s %d', $labelBase, $index + 1);
    }

    protected static function getAttachmentUrlFor(?TransferRequest $record, string $attribute, ?string $path = null): ?string
    {
        if (! $record instanceof TransferRequest) {
            return null;
        }

        $paths = TransferRequest::normalizeAttachmentPaths($record->{$attribute});
        $fileIndex = static::resolveAttachmentIndex($paths, $path);

        if ($fileIndex === null || blank($record->status_response_id)) {
            return null;
        }

        $attachmentType = match ($attribute) {
            'invoice_path'            => 'invoice',
            'account_attachment_path' => 'account-attachment',
            'realization_proof_path'  => 'realization-proof',
            default                   => null,
        };

        if (! $attachmentType) {
            return null;
        }

        try {
            return URL::route(
                'form-transfer.public.attachments.download',
                [
                    'statusResponseId' => $record->status_response_id,
                    'attachment'       => $attachmentType,
                    'file'             => $fileIndex,
                ],
            );
        } catch (\Throwable $exception) {
            return null;
        }
    }

    protected static function getRealizationProofUrlFor(?TransferRequestRealization $realization): ?string
    {
        if (! $realization instanceof TransferRequestRealization || blank($realization->proof_path)) {
            return null;
        }

        $transferRequest = $realization->transferRequest;

        if (! $transferRequest instanceof TransferRequest || blank($transferRequest->status_response_id)) {
            return null;
        }

        try {
            return URL::route(
                'form-transfer.public.attachments.download',
                [
                    'statusResponseId' => $transferRequest->status_response_id,
                    'attachment'       => 'realization-proof',
                    'realization'      => $realization->getKey(),
                ],
            );
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public static function getPublicApprovalUrlFor(mixed $taskId): ?string
    {
        $taskId = trim((string) $taskId);

        if ($taskId === '') {
            return null;
        }

        $baseUrl = rtrim((string) config('app.url'), '/');
        $path = '/transfer-requests/approval/'.rawurlencode($taskId);

        return $baseUrl !== '' ? $baseUrl.$path : $path;
    }

    public static function getPendingPublicApprovalUrlFor(mixed $taskId, mixed $status): ?string
    {
        if ((string) $status !== ApprovalStatus::PENDING->value) {
            return null;
        }

        return static::getPublicApprovalUrlFor($taskId);
    }

    public static function getPublicProgressUrlFor(mixed $statusResponseId): ?string
    {
        $statusResponseId = trim((string) $statusResponseId);

        if ($statusResponseId === '') {
            return null;
        }

        $baseUrl = rtrim((string) config('app.url'), '/');
        $path = '/transfer-requests/progress/'.rawurlencode($statusResponseId);

        return $baseUrl !== '' ? $baseUrl.$path : $path;
    }
}
