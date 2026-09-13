<?php

namespace Cesa\ExitClearance\Filament\Resources;

use Cesa\ExitClearance\Enums\ApprovalStatus;
use Cesa\ExitClearance\Models\Approver;
use Cesa\ExitClearance\Models\Department;
use Cesa\ExitClearance\Models\Request;
use Cesa\ExitClearance\Services\ExitClearanceNotificationService;
use Cesa\ExitClearance\Services\ExitClearanceRequestPdfService;
use Cesa\ExitClearance\Services\ExitClearanceRequestService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use League\Flysystem\UnableToCheckFileExistence;
use Webkul\Support\Models\Company;

class RequestResource extends ExitClearanceResource
{
    protected static ?string $model = Request::class;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('company');
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function getNavigationIcon(): ?string
    {
        return null;
    }

    public static function getNavigationLabel(): string
    {
        return trans_choice('exit-clearance::filament/resources/request.label', 2);
    }

    public static function getPluralModelLabel(): string
    {
        return trans_choice('exit-clearance::filament/resources/request.label', 2);
    }

    public static function getModelLabel(): string
    {
        return trans_choice('exit-clearance::filament/resources/request.label', 1);
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        $response = parent::getEditAuthorizationResponse($record);

        if (! $response->allowed()) {
            return $response;
        }

        if (static::isRecordApproved($record)) {
            return Response::deny('Request sudah approved.');
        }

        return $response;
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        $response = parent::getDeleteAuthorizationResponse($record);

        if (! $response->allowed()) {
            return $response;
        }

        if (static::isRecordApproved($record)) {
            return Response::deny('Request sudah approved.');
        }

        return $response;
    }

    protected static function isRecordApproved(Model $record): bool
    {
        if (! $record instanceof Request) {
            return true;
        }

        $requestService = app(ExitClearanceRequestService::class);

        return $requestService->normalizeFormStatus($record->form_status) === 'approved';
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Grid::make(3)->schema([
                    Group::make()
                        ->schema([
                            Section::make(__('exit-clearance::filament/resources/request.steps.personal_data'))
                                ->schema([
                                    TextInput::make('name')
                                        ->label(__('exit-clearance::filament/resources/request.fields.name'))
                                        ->required()
                                        ->maxLength(255),
                                    TextInput::make('email')
                                        ->label(__('exit-clearance::filament/resources/request.fields.email'))
                                        ->email()
                                        ->required()
                                        ->maxLength(255),
                                    TextInput::make('phone')
                                        ->label(__('exit-clearance::filament/resources/request.fields.phone'))
                                        ->tel()
                                        ->required()
                                        ->maxLength(255),
                                    TextInput::make('position')
                                        ->label(__('exit-clearance::filament/resources/request.fields.position'))
                                        ->required()
                                        ->maxLength(255),
                                    TextInput::make('placement')
                                        ->label(__('exit-clearance::filament/resources/request.fields.placement'))
                                        ->required()
                                        ->maxLength(255),
                                    Select::make('company_id')
                                        ->label(__('exit-clearance::filament/resources/request.fields.company'))
                                        ->options(fn (?Request $record): array => Company::query()
                                            ->withTrashed()
                                            ->where(fn (Builder $query): Builder => $query
                                                ->whereNull('deleted_at')
                                                ->orWhere('id', $record?->getRawOriginal('company_id')))
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                            ->all())
                                        ->searchable()
                                        ->required(fn (?Request $record): bool => ! $record?->exists || $record->getRawOriginal('company_id') !== null),
                                    Select::make('department_id')
                                        ->label(__('exit-clearance::filament/resources/request.fields.department'))
                                        ->relationship('department', 'name')
                                        ->searchable()
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                                            if (! $state) {
                                                $set('approvers', []);

                                                return;
                                            }

                                            $approverIds = Department::query()
                                                ->find($state)?->approvers()
                                                ->whereNull('exit_clearance_approvers.deleted_at')
                                                ->pluck('id')
                                                ->all() ?? [];

                                            $set('approvers', $approverIds);
                                        })
                                        ->preload(),
                                    DatePicker::make('join_date')
                                        ->label(__('exit-clearance::filament/resources/request.fields.join_date'))
                                        ->required()
                                        ->displayFormat('Y-m-d'),
                                    DatePicker::make('departure_date')
                                        ->label(__('exit-clearance::filament/resources/request.fields.departure_date'))
                                        ->required()
                                        ->displayFormat('Y-m-d'),
                                ])
                                ->columns(2),

                            Section::make(__('exit-clearance::filament/resources/request.steps.resignation_letter'))
                                ->description(__('exit-clearance::filament/resources/request.resignation_letter.not_required'))
                                ->collapsible()
                                ->collapsed()
                                ->schema([
                                    FileUpload::make('resignation_letter_url')
                                        ->label(__('exit-clearance::filament/resources/request.file_upload.label'))
                                        ->helperText(__('exit-clearance::filament/resources/request.file_upload.helper_text'))
                                        ->directory('resignation-letters')
                                        ->downloadable()
                                        ->openable()
                                        ->maxSize(10240)
                                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'])
                                        ->visibility('private')
                                        ->getUploadedFileUsing(function (?Request $record, string $file, string|array|null $storedFileNames, $component): ?array {
                                            if (! $record?->form_response_id) {
                                                return null;
                                            }

                                            $storage = $component->getDisk();
                                            $shouldFetchFileInformation = $component->shouldFetchFileInformation();

                                            if ($shouldFetchFileInformation) {
                                                try {
                                                    if (! $storage->exists($file)) {
                                                        return null;
                                                    }
                                                } catch (UnableToCheckFileExistence $e) {
                                                    return null;
                                                }
                                            }

                                            return [
                                                'name' => ($component->isMultiple() ? ($storedFileNames[$file] ?? null) : $storedFileNames) ?? basename($file),
                                                'size' => $shouldFetchFileInformation ? $storage->size($file) : 0,
                                                'type' => $shouldFetchFileInformation ? $storage->mimeType($file) : null,
                                                'url'  => URL::temporarySignedRoute(
                                                    'exit-clearance.public.attachments.download',
                                                    now()->addMinutes(60),
                                                    [
                                                        'response'   => $record->form_response_id,
                                                        'attachment' => 'resignation-letter',
                                                    ],
                                                ),
                                            ];
                                        })
                                        ->columnSpanFull(),
                                ]),

                            Section::make(__('exit-clearance::filament/resources/request.steps.exit_interview'))
                                ->schema([
                                    Textarea::make('reason')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q1'))
                                        ->required()
                                        ->rows(3)
                                        ->maxLength(2000)
                                        ->columnSpanFull(),
                                    Textarea::make('workload_feedback')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q2'))
                                        ->required()
                                        ->rows(3)
                                        ->maxLength(2000)
                                        ->columnSpanFull(),
                                    Textarea::make('career_growth_feedback')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q3'))
                                        ->required()
                                        ->rows(3)
                                        ->maxLength(2000)
                                        ->columnSpanFull(),
                                    Textarea::make('facility_welfare_feedback')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q4'))
                                        ->required()
                                        ->rows(3)
                                        ->maxLength(2000)
                                        ->columnSpanFull(),
                                    Textarea::make('work_relationship_feedback')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q5'))
                                        ->required()
                                        ->rows(3)
                                        ->maxLength(2000)
                                        ->columnSpanFull(),
                                    Textarea::make('compensation_feedback')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q6'))
                                        ->required()
                                        ->rows(3)
                                        ->maxLength(2000)
                                        ->columnSpanFull(),
                                    Textarea::make('division_feedback')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q7'))
                                        ->required()
                                        ->rows(3)
                                        ->maxLength(2000)
                                        ->columnSpanFull(),
                                    Textarea::make('company_feedback')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q8'))
                                        ->required()
                                        ->rows(3)
                                        ->maxLength(2000)
                                        ->columnSpanFull(),
                                ])
                                ->collapsible()
                                ->collapsed(),

                            Section::make(__('exit-clearance::filament/resources/request.clearance.section_title'))
                                ->schema([
                                    TextInput::make('clearance_kartu_halo')
                                        ->label(__('exit-clearance::filament/resources/request.clearance.item_1'))
                                        ->required(),
                                    TextInput::make('clearance_employee_debt')
                                        ->label(__('exit-clearance::filament/resources/request.clearance.item_2'))
                                        ->required(),
                                    TextInput::make('clearance_uniform_return')
                                        ->label(__('exit-clearance::filament/resources/request.clearance.item_3'))
                                        ->required(),
                                    TextInput::make('clearance_vehicle_return')
                                        ->label(__('exit-clearance::filament/resources/request.clearance.item_4'))
                                        ->required(),
                                    TextInput::make('clearance_inventory_return')
                                        ->label(__('exit-clearance::filament/resources/request.clearance.item_5'))
                                        ->required(),
                                    TextInput::make('clearance_account_deactivation')
                                        ->label(__('exit-clearance::filament/resources/request.clearance.item_6'))
                                        ->required(),
                                    TextInput::make('clearance_receivable_data')
                                        ->label(__('exit-clearance::filament/resources/request.clearance.item_7'))
                                        ->required(),
                                    TextInput::make('clearance_promotor_internal')
                                        ->label(__('exit-clearance::filament/resources/request.clearance.item_8'))
                                        ->required(),
                                    TextInput::make('clearance_nota_pending')
                                        ->label(__('exit-clearance::filament/resources/request.clearance.item_9'))
                                        ->required(),
                                    TextInput::make('clearance_stock_opname')
                                        ->label(__('exit-clearance::filament/resources/request.clearance.item_10'))
                                        ->required(),
                                ])
                                ->columns(2)
                                ->collapsible()
                                ->collapsed(),
                        ])
                        ->columnSpan(2),

                    Group::make()
                        ->schema([
                            Section::make(__('exit-clearance::filament/resources/request.approvals.section_title'))
                                ->schema([
                                    Select::make('approvers')
                                        ->label(__('exit-clearance::filament/resources/request.approvals.section_title'))
                                        ->relationship('approvers', 'name')
                                        ->options(fn (Get $get): array => Department::query()
                                            ->find($get('department_id'))
                                            ?->approvers()
                                            ->whereNull('exit_clearance_approvers.deleted_at')
                                            ->pluck('name', 'id')
                                            ->all() ?? [])
                                        ->multiple()
                                        ->preload()
                                        ->searchable()
                                        ->disabled()
                                        ->saved()
                                        ->saveRelationshipsWhenDisabled()
                                        ->columnSpanFull(),
                                ])
                                ->collapsible(),

                            Section::make(__('exit-clearance::filament/resources/request.metadata.section_title'))
                                ->schema([
                                    TextInput::make('form_uid')
                                        ->label(__('exit-clearance::filament/resources/request.metadata.form_uid'))
                                        ->disabled()
                                        ->dehydrated(false),
                                    TextInput::make('form_status')
                                        ->label(__('exit-clearance::filament/resources/request.metadata.form_status'))
                                        ->disabled()
                                        ->dehydrated(false),
                                    TextInput::make('form_response_id')
                                        ->label(__('exit-clearance::filament/resources/request.metadata.form_response'))
                                        ->disabled()
                                        ->dehydrated(false),
                                ])
                                ->columns(1)
                                ->collapsible()
                                ->collapsed(),
                        ])
                        ->columnSpan(1),
                ]),
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
                            Section::make(__('exit-clearance::filament/resources/request.infolist.employee_info'))
                                ->schema([
                                    Grid::make(3)
                                        ->schema([
                                            TextEntry::make('name')->label(__('exit-clearance::filament/resources/request.infolist_fields.name'))->icon('heroicon-o-user'),
                                            TextEntry::make('email')->label(__('exit-clearance::filament/resources/request.infolist_fields.email'))->icon('heroicon-o-envelope'),
                                            TextEntry::make('phone')->label(__('exit-clearance::filament/resources/request.infolist_fields.phone'))->icon('heroicon-o-phone'),
                                            TextEntry::make('company.name')
                                                ->label(__('exit-clearance::filament/resources/request.infolist_fields.company'))
                                                ->icon('heroicon-o-building-office')
                                                ->placeholder('—'),
                                            TextEntry::make('department.name')->label(__('exit-clearance::filament/resources/request.infolist_fields.department'))->icon('heroicon-o-building-office-2')
                                                ->formatStateUsing(function ($state, Request $record): string {
                                                    $department = $record->department;
                                                    if (! $department) {
                                                        return '-';
                                                    }
                                                    if ($department->trashed()) {
                                                        return $department->name.' (Dihapus)';
                                                    }

                                                    return $department->name;
                                                })
                                                ->color(fn (Request $record): string => $record->department?->trashed() ? 'gray' : 'default'),
                                            TextEntry::make('position')->label(__('exit-clearance::filament/resources/request.infolist_fields.position'))->icon('heroicon-o-briefcase'),
                                            TextEntry::make('placement')->label(__('exit-clearance::filament/resources/request.infolist_fields.placement'))->icon('heroicon-o-map-pin'),
                                            TextEntry::make('join_date')->label(__('exit-clearance::filament/resources/request.infolist_fields.joined'))->date(),
                                            TextEntry::make('departure_date')->label(__('exit-clearance::filament/resources/request.infolist_fields.departing'))->date(),

                                            TextEntry::make('resignation_letter_url')
                                                ->label(__('exit-clearance::filament/resources/request.infolist_fields.surat_resign'))
                                                ->formatStateUsing(fn ($state) => $state ? basename($state) : __('exit-clearance::filament/resources/request.infolist_fields.no_file'))
                                                ->url(fn ($state, Request $record) => filled($state)
                                                    ? URL::temporarySignedRoute(
                                                        'exit-clearance.public.attachments.download',
                                                        now()->addMinutes(60),
                                                        [
                                                            'response'   => $record->form_response_id,
                                                            'attachment' => 'resignation-letter',
                                                        ],
                                                    )
                                                    : null, true)
                                                ->icon('heroicon-o-paper-clip'),
                                        ]),
                                ])
                                ->collapsible(),

                            Section::make(__('exit-clearance::filament/resources/request.steps.exit_interview'))
                                ->schema([
                                    TextEntry::make('reason')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q1'))
                                        ->markdown(),

                                    TextEntry::make('workload_feedback')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q2')),
                                    TextEntry::make('career_growth_feedback')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q3')),
                                    TextEntry::make('facility_welfare_feedback')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q4')),
                                    TextEntry::make('work_relationship_feedback')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q5')),
                                    TextEntry::make('compensation_feedback')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q6')),
                                    TextEntry::make('division_feedback')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q7')),
                                    TextEntry::make('company_feedback')
                                        ->label(__('exit-clearance::filament/resources/request.exit_interview.q8')),
                                ])
                                ->collapsible(),
                            Section::make(__('exit-clearance::filament/resources/request.clearance.section_title'))
                                ->schema([
                                    Grid::make(1)
                                        ->schema([
                                            TextEntry::make('clearance_kartu_halo')->label(__('exit-clearance::filament/resources/request.clearance.item_1')),
                                            TextEntry::make('clearance_employee_debt')->label(__('exit-clearance::filament/resources/request.clearance.item_2')),
                                            TextEntry::make('clearance_uniform_return')->label(__('exit-clearance::filament/resources/request.clearance.item_3')),
                                            TextEntry::make('clearance_vehicle_return')->label(__('exit-clearance::filament/resources/request.clearance.item_4')),
                                            TextEntry::make('clearance_inventory_return')->label(__('exit-clearance::filament/resources/request.clearance.item_5')),
                                            TextEntry::make('clearance_account_deactivation')->label(__('exit-clearance::filament/resources/request.clearance.item_6')),
                                            TextEntry::make('clearance_receivable_data')->label(__('exit-clearance::filament/resources/request.clearance.item_7')),
                                            TextEntry::make('clearance_promotor_internal')->label(__('exit-clearance::filament/resources/request.clearance.item_8')),
                                            TextEntry::make('clearance_nota_pending')->label(__('exit-clearance::filament/resources/request.clearance.item_9')),
                                            TextEntry::make('clearance_stock_opname')->label(__('exit-clearance::filament/resources/request.clearance.item_10')),
                                        ]),
                                ])
                                ->collapsible()
                                ->collapsed(),
                        ]),

                    Group::make()
                        ->columnSpan(1)
                        ->schema([
                            Section::make(__('exit-clearance::filament/resources/request.infolist.approval_chain'))
                                ->schema([
                                    RepeatableEntry::make('approval_flow')
                                        ->state(fn (Request $record): array => static::getApprovalFlowEntries($record))
                                        ->schema([
                                            Grid::make(2)
                                                ->schema([
                                                    TextEntry::make('title')
                                                        ->label(__('exit-clearance::filament/resources/request.infolist_fields.approval_step'))
                                                        ->icon('heroicon-o-queue-list')
                                                        ->placeholder('—'),
                                                    TextEntry::make('name')
                                                        ->label(__('exit-clearance::filament/resources/request.infolist_fields.approver_name'))
                                                        ->icon('heroicon-o-user')
                                                        ->placeholder('—')
                                                        ->color(fn (Get $get): string => $get('is_deleted') ? 'gray' : 'default'),
                                                    TextEntry::make('status')
                                                        ->label(__('exit-clearance::filament/resources/request.infolist_fields.approver_status'))
                                                        ->icon('heroicon-o-check-circle')
                                                        ->placeholder('—')
                                                        ->badge()
                                                        ->color(fn ($state) => static::resolveApproverStatusColor($state))
                                                        ->formatStateUsing(fn ($state) => static::formatApproverStatusLabel($state)),
                                                    TextEntry::make('approval_url')
                                                        ->label(__('exit-clearance::filament/resources/request.infolist_fields.approval_link'))
                                                        ->icon('heroicon-o-arrow-top-right-on-square')
                                                        ->formatStateUsing(fn (): string => __('exit-clearance::filament/resources/request.actions.open_approval_page'))
                                                        ->badge()
                                                        ->color('primary')
                                                        ->url(fn (?string $state): ?string => $state, true)
                                                        ->copyable()
                                                        ->copyableState(fn (?string $state): ?string => $state)
                                                        ->columnSpanFull(),
                                                ]),
                                        ])
                                        ->columns(1),
                                ])
                                ->collapsible(),
                            Section::make(__('exit-clearance::filament/resources/request.infolist.request_status'))
                                ->schema([
                                    TextEntry::make('form_uid')
                                        ->label(__('exit-clearance::filament/resources/request.infolist_fields.uid'))
                                        ->icon('heroicon-o-hashtag')
                                        ->copyable(),
                                    TextEntry::make('form_response_id')
                                        ->label(__('exit-clearance::filament/resources/request.infolist_fields.progress_url'))
                                        ->formatStateUsing(fn (): string => __('exit-clearance::filament/resources/request.actions.view_progress'))
                                        ->url(fn (Request $record): string => $record->getPublicProgressUrl(), true)
                                        ->copyable()
                                        ->copyableState(fn (Request $record): string => $record->getPublicProgressUrl())
                                        ->icon('heroicon-o-arrow-top-right-on-square'),
                                    TextEntry::make('form_status')
                                        ->label(__('exit-clearance::filament/resources/request.infolist_fields.status'))
                                        ->badge()
                                        ->color(fn (?string $state): string => static::resolveFormStatusColor($state))
                                        ->formatStateUsing(fn (?string $state): string => static::formatFormStatusLabel($state)),

                                    TextEntry::make('request_date')
                                        ->label(__('exit-clearance::filament/resources/request.infolist_fields.request_date'))
                                        ->date(),
                                ])
                                ->collapsible(),
                        ]),
                ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('form_uid')
                    ->label(__('exit-clearance::filament/resources/request.table.uid'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('exit-clearance::filament/resources/request.table.employee_name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('exit-clearance::filament/resources/request.table.email'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('position')
                    ->label(__('exit-clearance::filament/resources/request.table.position'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('placement')
                    ->label(__('exit-clearance::filament/resources/request.table.placement'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('form_status')
                    ->label(__('exit-clearance::filament/resources/request.table.status'))
                    ->badge()
                    ->color(fn (?string $state): string => match (strtolower((string) $state)) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default    => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('join_date')
                    ->label(__('exit-clearance::filament/resources/request.table.join_date'))
                    ->searchable()
                    ->sortable()
                    ->date(),
                Tables\Columns\TextColumn::make('request_date')
                    ->label(__('exit-clearance::filament/resources/request.table.request_date'))
                    ->searchable()
                    ->sortable()
                    ->date(),
                Tables\Columns\TextColumn::make('departure_date')
                    ->label(__('exit-clearance::filament/resources/request.table.departure_date'))
                    ->searchable()
                    ->sortable()
                    ->date(),
                Tables\Columns\TextColumn::make('reason')
                    ->label(__('exit-clearance::filament/resources/request.table.reason'))
                    ->searchable()
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('resignation_letter_url')
                    ->label(__('exit-clearance::filament/resources/request.table.resignation_letter'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->url(fn (?string $state, Request $record): ?string => filled($state)
                        ? URL::temporarySignedRoute(
                            'exit-clearance.public.attachments.download',
                            now()->addMinutes(60),
                            [
                                'response'   => $record->form_response_id,
                                'attachment' => 'resignation-letter',
                            ],
                        )
                        : null),
                Tables\Columns\TextColumn::make('company.name')
                    ->label(__('exit-clearance::filament/resources/request.table.company'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('department.name')
                    ->label(__('exit-clearance::filament/resources/request.table.department'))
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(function ($state, Request $record): string {
                        $department = $record->department;
                        if (! $department) {
                            return '-';
                        }
                        if ($department->trashed()) {
                            return $department->name.' (Dihapus)';
                        }

                        return $department->name;
                    })
                    ->color(fn (Request $record): string => $record->department?->trashed() ? 'gray' : 'default'),
                Tables\Columns\TextColumn::make('approvers.name')
                    ->label(__('exit-clearance::filament/resources/request.table.approvers'))
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('view_progress')
                    ->label(__('exit-clearance::filament/resources/request.actions.view_progress'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Request $record): string => $record->getPublicProgressUrl())
                    ->openUrlInNewTab(),
                Action::make('download-pdf')
                    ->label(__('exit-clearance::filament/resources/request.actions.download_pdf'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn (Request $record) => app(ExitClearanceRequestPdfService::class)->download($record)),
                ViewAction::make()->slideOver(),
                EditAction::make()->slideOver(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkAction::make('download-pdf-bulk')
                    ->label(__('exit-clearance::filament/resources/request.actions.download_selected_pdfs'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->authorizeIndividualRecords('view')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => app(ExitClearanceRequestPdfService::class)->downloadBulkArchive($records)),
                DeleteBulkAction::make()
                    ->authorizeIndividualRecords(),
            ])
            ->defaultSort('request_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('department_id')
                    ->label(__('exit-clearance::filament/resources/request.filters.department'))
                    ->relationship('department', 'name')
                    ->preload(),
                Tables\Filters\Filter::make('request_date')
                    ->label(__('exit-clearance::filament/resources/request.filters.request_date'))
                    ->form([
                        DatePicker::make('request_date')
                            ->label(__('exit-clearance::filament/resources/request.filters.request_date'))
                            ->displayFormat('Y-m-d'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $requestDate = $data['request_date'] ?? null;

                        if (blank($requestDate)) {
                            return $query;
                        }

                        return $query->whereDate('request_date', $requestDate);
                    })
                    ->indicateUsing(function (array $state): ?string {
                        $value = $state['request_date'] ?? null;

                        return $value ? Carbon::parse($value)->format('Y-m-d') : null;
                    }),
            ]);
    }

    protected static function formatFormStatusLabel(?string $status): string
    {
        return app(ExitClearanceRequestService::class)->formatFormStatus($status);
    }

    protected static function resolveFormStatusColor(?string $status): string
    {
        $normalized = app(ExitClearanceRequestService::class)->normalizeFormStatus($status);

        return match ($normalized) {
            'approved' => 'success',
            'rejected' => 'danger',
            default    => 'gray',
        };
    }

    /**
     * @return array<int, array{title: string|null, name: string|null, status: string|null, approval_url: string, is_deleted: bool}>
     */
    protected static function getApprovalFlowEntries(Request $record): array
    {
        $record->loadMissing('approvers');

        $notificationService = app(ExitClearanceNotificationService::class);

        return $record->approvers
            ->values()
            ->map(fn (Approver $approver): array => [
                'title'        => $approver->title,
                'name'         => $approver->trashed() ? $approver->name.' (Dihapus)' : $approver->name,
                'status'       => $approver->pivot?->status,
                'approval_url' => $notificationService->buildApprovalUrl($record, $approver),
                'is_deleted'   => $approver->trashed(),
            ])
            ->all();
    }

    protected static function formatApproverStatusLabel(?string $status): string
    {
        $normalized = app(ExitClearanceRequestService::class)->normalizeApprovalStatus($status);
        $enum = ApprovalStatus::tryFrom($normalized);

        return $enum ? $enum->getLabel() : ucfirst($normalized);
    }

    protected static function resolveApproverStatusColor(?string $status): string
    {
        $normalized = app(ExitClearanceRequestService::class)->normalizeApprovalStatus($status);

        return ApprovalStatus::tryFrom($normalized)?->getColor() ?? 'gray';
    }

    public static function getPages(): array
    {
        return [
            'index'  => RequestResource\Pages\ListRequests::route('/'),
            'create' => RequestResource\Pages\CreateRequest::route('/create'),
            'view'   => RequestResource\Pages\ViewRequest::route('/{record}'),
            'edit'   => RequestResource\Pages\EditRequest::route('/{record}/edit'),
        ];
    }
}
