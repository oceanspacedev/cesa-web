<?php

namespace Cesa\Rekrutmen\Filament\Resources;

use Cesa\Rekrutmen\Enums\RequestManPowerApprovalStatus;
use Cesa\Rekrutmen\Enums\RequestManPowerFulfillmentStatus;
use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Cesa\Rekrutmen\Enums\StatusKebutuhan;
use Cesa\Rekrutmen\Filament\Resources\RequestManPowerResource\Pages;
use Cesa\Rekrutmen\Filament\Resources\RequestManPowerResource\RelationManagers\ApprovalsRelationManager;
use Cesa\Rekrutmen\Models\Division;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Models\RequestManPowerApproval;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Webkul\Security\Traits\HasResourcePermissionQuery;

class RequestManPowerResource extends Resource
{
    use HasResourcePermissionQuery;

    protected static ?string $model = RequestManPower::class;

    protected static \BackedEnum|string|null $navigationIcon = null;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string
    {
        return __('admin.navigation.rekrutmen');
    }

    public static function getNavigationLabel(): string
    {
        return __('rekrutmen::filament/resources/request-man-power.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('rekrutmen::filament/resources/request-man-power.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('rekrutmen::filament/resources/request-man-power.model.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Grid::make(3)->schema([
                    Group::make([
                        Section::make(__('rekrutmen::filament/resources/request-man-power.form.sections.applicant_information'))
                            ->schema([
                                TextInput::make('nama_pengaju')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.nama_pengaju'))
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('posisi_pengaju')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.posisi_pengaju'))
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('email_address')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.email_address'))
                                    ->email()
                                    ->required()
                                    ->maxLength(255),
                                DatePicker::make('tanggal_pengajuan')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.tanggal_pengajuan'))
                                    ->required()
                                    ->default(now()),
                                Select::make('company_id')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.company_id'))
                                    ->relationship(
                                        name: 'company',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn (Builder $query) => $query
                                            ->where('is_active', true)
                                            ->orderBy('name')
                                    )
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->default(fn () => Auth::user()?->default_company_id)
                                    ->afterStateUpdated(function (Set $set): void {
                                        $set('division_id', null);
                                    })
                                    ->live(),
                                Select::make('division_id')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.division_id'))
                                    ->options(function (Get $get): array {
                                        $companyId = $get('company_id');

                                        if (! $companyId) {
                                            return [];
                                        }

                                        return Division::query()
                                            ->where('company_id', $companyId)
                                            ->where('is_active', true)
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                            ->all();
                                    })
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, ?int $state): void {
                                        if (! $state) {
                                            return;
                                        }

                                        $division = Division::query()->find($state);

                                        if (! $division) {
                                            return;
                                        }

                                        $set('company_id', $division->company_id);
                                    }),
                            ])->columns(2),

                        Section::make(__('rekrutmen::filament/resources/request-man-power.form.sections.requirement_details'))
                            ->schema([
                                TextInput::make('posisi_dibutuhkan')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.posisi_dibutuhkan'))
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('lokasi_penempatan')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.lokasi_penempatan'))
                                    ->required()
                                    ->maxLength(255),
                                Select::make('status_kebutuhan')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.status_kebutuhan'))
                                    ->required()
                                    ->options(StatusKebutuhan::class)
                                    ->default(StatusKebutuhan::NEW_HIRING)
                                    ->live(),
                                Select::make('level_pekerjaan')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.level_pekerjaan'))
                                    ->required()
                                    ->options(RequestManPower::getTranslatedLevelPekerjaanOptions()),
                                TextInput::make('jumlah_karyawan_dibutuhkan')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.jumlah_karyawan_dibutuhkan'))
                                    ->required()
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(1),
                                DatePicker::make('estimasi_tanggal_join')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.estimasi_tanggal_join'))
                                    ->required(),
                                TextInput::make('nama_karyawan_replacement')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.nama_karyawan_replacement'))
                                    ->maxLength(255)
                                    ->nullable()
                                    ->required(fn (callable $get) => self::isReplacementStatus($get('status_kebutuhan')))
                                    ->helperText(__('rekrutmen::filament/resources/request-man-power.form.helper_texts.nama_karyawan_replacement'))
                                    ->visible(fn (callable $get) => self::isReplacementStatus($get('status_kebutuhan'))),
                            ])->columns(2),

                        Section::make(__('rekrutmen::filament/resources/request-man-power.form.sections.qualifications'))
                            ->schema([
                                Textarea::make('requirements_kualifikasi')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.requirements_kualifikasi'))
                                    ->required()
                                    ->rows(6)
                                    ->columnSpanFull(),
                                Textarea::make('job_description')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.job_description'))
                                    ->required()
                                    ->rows(6)
                                    ->columnSpanFull(),
                                Textarea::make('keterangan')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.keterangan'))
                                    ->nullable()
                                    ->columnSpanFull(),
                            ]),
                    ])->columnSpan(2),

                    Group::make([
                        Section::make(__('rekrutmen::filament/resources/request-man-power.form.sections.approval_status'))
                            ->schema([
                                Select::make('status')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.status'))
                                    ->required()
                                    ->options(RequestManPowerStatus::class)
                                    ->default(RequestManPowerStatus::PENDING)
                                    ->disabled(),
                                Select::make('approved_by')
                                    ->relationship('approver', 'name')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.approved_by'))
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->visible(fn ($record) => $record && $record->approved_by),
                            ])->columns(1),
                    ])->columnSpan(1),
                ])->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3)->schema([
                    Group::make([
                        Section::make(__('rekrutmen::filament/resources/request-man-power.form.sections.applicant_information'))
                            ->schema([
                                TextEntry::make('nama_pengaju')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.nama_pengaju')),
                                TextEntry::make('posisi_pengaju')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.posisi_pengaju')),
                                TextEntry::make('email_address')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.email_address')),
                                TextEntry::make('tanggal_pengajuan')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.tanggal_pengajuan'))
                                    ->date('d F Y'),
                                TextEntry::make('company.name')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.company_id')),
                                TextEntry::make('division.name')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.division_id')),
                            ])->columns(2),

                        Section::make(__('rekrutmen::filament/resources/request-man-power.form.sections.requirement_details'))
                            ->schema([
                                TextEntry::make('posisi_dibutuhkan')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.posisi_dibutuhkan')),
                                TextEntry::make('lokasi_penempatan')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.lokasi_penempatan')),
                                TextEntry::make('status_kebutuhan')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.status_kebutuhan'))
                                    ->badge(),
                                TextEntry::make('level_pekerjaan')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.level_pekerjaan'))
                                    ->formatStateUsing(fn ($state) => RequestManPower::getTranslatedLevelPekerjaanOptions()[$state->value ?? $state] ?? ($state->value ?? $state)),
                                TextEntry::make('jumlah_karyawan_dibutuhkan')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.jumlah_karyawan_dibutuhkan')),
                                TextEntry::make('estimasi_tanggal_join')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.estimasi_tanggal_join'))
                                    ->date('d F Y'),
                                TextEntry::make('nama_karyawan_replacement')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.nama_karyawan_replacement'))
                                    ->visible(fn ($record) => self::isReplacementStatus($record->status_kebutuhan)),
                            ])->columns(2),

                        Section::make(__('rekrutmen::filament/resources/request-man-power.form.sections.qualifications'))
                            ->schema([
                                TextEntry::make('requirements_kualifikasi')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.requirements_kualifikasi'))
                                    ->columnSpanFull(),
                                TextEntry::make('job_description')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.job_description'))
                                    ->columnSpanFull(),
                                TextEntry::make('keterangan')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.keterangan'))
                                    ->columnSpanFull(),
                            ]),
                    ])->columnSpan(2),

                    Group::make([
                        Section::make(__('rekrutmen::filament/resources/request-man-power.form.sections.approval_status'))
                            ->schema([
                                TextEntry::make('status')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.status'))
                                    ->badge(),
                                TextEntry::make('approver.name')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.approved_by'))
                                    ->visible(fn ($record) => $record && $record->approved_by),
                                TextEntry::make('hold_reason')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.hold_reason'))
                                    ->visible(fn (RequestManPower $record): bool => filled($record->hold_reason))
                                    ->columnSpanFull(),
                                TextEntry::make('heldBy.name')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.held_by'))
                                    ->visible(fn (RequestManPower $record): bool => filled($record->held_by)),
                                TextEntry::make('held_at')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.held_at'))
                                    ->dateTime('d F Y H:i')
                                    ->visible(fn (RequestManPower $record): bool => filled($record->held_at)),
                                TextEntry::make('resumedBy.name')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.resumed_by'))
                                    ->visible(fn (RequestManPower $record): bool => filled($record->resumed_by)),
                                TextEntry::make('resumed_at')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.resumed_at'))
                                    ->dateTime('d F Y H:i')
                                    ->visible(fn (RequestManPower $record): bool => filled($record->resumed_at)),
                            ])->columns(1),
                        Section::make(__('rekrutmen::filament/resources/request-man-power.form.sections.status_history'))
                            ->schema([
                                RepeatableEntry::make('statusHistories')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.sections.status_history'))
                                    ->visible(fn (RequestManPower $record): bool => $record->statusHistories()->exists())
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                TextEntry::make('created_at')
                                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.acted_at'))
                                                    ->dateTime('d F Y H:i'),
                                                TextEntry::make('actor.name')
                                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.actor'))
                                                    ->placeholder('—'),
                                                TextEntry::make('from_status')
                                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.previous_status'))
                                                    ->formatStateUsing(fn (RequestManPowerStatus|string|null $state): string => $state instanceof RequestManPowerStatus ? $state->getLabel() : (string) ($state ?? '—'))
                                                    ->badge(),
                                                TextEntry::make('to_status')
                                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.latest_status'))
                                                    ->formatStateUsing(fn (RequestManPowerStatus|string|null $state): string => $state instanceof RequestManPowerStatus ? $state->getLabel() : (string) ($state ?? '—'))
                                                    ->badge()
                                                    ->color(fn ($state): string|array|null => $state instanceof RequestManPowerStatus ? $state->getColor() : null),
                                                TextEntry::make('reason')
                                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.reason'))
                                                    ->placeholder('—')
                                                    ->columnSpanFull(),
                                            ]),
                                    ])
                                    ->columns(1),
                            ])
                            ->visible(fn (RequestManPower $record): bool => $record->statusHistories()->exists())
                            ->collapsible(),
                        Section::make(__('rekrutmen::filament/resources/request-man-power.form.sections.approval_flow'))
                            ->schema([
                                TextEntry::make('approval_configuration_status')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.approval_configuration'))
                                    ->state(fn (): string => __('rekrutmen::filament/resources/request-man-power.table.descriptions.approval_workflow_missing'))
                                    ->badge()
                                    ->color('warning')
                                    ->visible(fn (RequestManPower $record): bool => $record->hasMissingApprovalWorkflow()),
                                RepeatableEntry::make('approvals')
                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.sections.approval_flow'))
                                    ->visible(fn (RequestManPower $record): bool => $record->approvals()->exists())
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                TextEntry::make('approver_name')
                                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.approver_name'))
                                                    ->icon('heroicon-o-user')
                                                    ->placeholder('—'),
                                                TextEntry::make('status')
                                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.approver_status'))
                                                    ->icon('heroicon-o-check-circle')
                                                    ->formatStateUsing(fn (RequestManPowerApprovalStatus|string|null $state): string => $state instanceof RequestManPowerApprovalStatus ? $state->getLabel() : (string) $state)
                                                    ->badge()
                                                    ->color(fn (RequestManPowerApproval $record): string|array|null => $record->status?->getColor()),
                                                TextEntry::make('action_token')
                                                    ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.approval_link'))
                                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                                    ->formatStateUsing(fn (): string => __('rekrutmen::filament/resources/request-man-power.table.actions.open_approval_page'))
                                                    ->badge()
                                                    ->color('primary')
                                                    ->url(
                                                        fn (RequestManPowerApproval $record): ?string => $record->status === RequestManPowerApprovalStatus::PENDING
                                                            && filled($record->action_token)
                                                            && ! $record->hasExpiredActionLink()
                                                            ? $record->buildApprovalUrl()
                                                            : null,
                                                        true,
                                                    )
                                                    ->visible(
                                                        fn (RequestManPowerApproval $record): bool => $record->status === RequestManPowerApprovalStatus::PENDING
                                                            && filled($record->action_token)
                                                            && ! $record->hasExpiredActionLink(),
                                                    )
                                                    ->columnSpanFull(),
                                            ]),
                                    ])
                                    ->columns(1),
                            ])
                            ->collapsible(),
                    ])->columnSpan(1),
                ])->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with([
                'approver',
                'currentPendingApproval',
                'company',
                'division.company',
                'jobPosting.applications:id,job_posting_id,status',
                'jobPosting.requestManPowers',
                'jobPosting.rekrutmenPipeline',
            ])->withCount('approvals'))
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->columns([
                Tables\Columns\TextColumn::make('posisi_dibutuhkan')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.columns.posisi_dibutuhkan'))
                    ->description(fn (RequestManPower $record): string => self::formatTablePositionDescription($record))
                    ->searchable()
                    ->wrap()
                    ->lineClamp(2)
                    ->sortable(),
                Tables\Columns\TextColumn::make('jobPosting.title')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.columns.job_posting'))
                    ->description(fn (RequestManPower $record): ?string => $record->jobPosting
                        ? JobPostingResource::formatJobPostingContext($record->jobPosting)
                        : null)
                    ->placeholder(__('rekrutmen::filament/resources/request-man-power.table.placeholders.job_posting'))
                    ->wrap()
                    ->lineClamp(2)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('division.name')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.columns.division_id'))
                    ->description(fn (RequestManPower $record): ?string => $record->company?->name ?: $record->division?->company?->name)
                    ->placeholder(__('rekrutmen::filament/resources/request-man-power.table.placeholders.division_id'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status_kebutuhan')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.columns.status_kebutuhan'))
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('jumlah_karyawan_dibutuhkan')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.columns.jumlah_karyawan_dibutuhkan'))
                    ->numeric()
                    ->suffix(' orang')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('fulfillment_status')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.columns.fulfillment_status'))
                    ->state(fn (RequestManPower $record): RequestManPowerFulfillmentStatus => $record->fulfillmentStatus())
                    ->formatStateUsing(fn (RequestManPowerFulfillmentStatus|string|null $state): string => $state instanceof RequestManPowerFulfillmentStatus
                        ? (string) $state->getLabel()
                        : (string) ($state ?? '-'))
                    ->description(fn (RequestManPower $record): string => $record->fulfillmentSummary())
                    ->badge()
                    ->color(fn (RequestManPowerFulfillmentStatus|string|null $state): string|array|null => $state instanceof RequestManPowerFulfillmentStatus
                        ? $state->getColor()
                        : null),
                Tables\Columns\TextColumn::make('tanggal_pengajuan')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.columns.tanggal_pengajuan'))
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.columns.status'))
                    ->description(fn (RequestManPower $record): ?string => self::formatApprovalDescription($record))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('id')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.columns.id'))
                    ->formatStateUsing(fn (int|string|null $state): string => filled($state) ? 'MPP #'.$state : '-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('nama_pengaju')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.columns.nama_pengaju'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('nama_karyawan_replacement')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.columns.nama_karyawan_replacement'))
                    ->placeholder(__('rekrutmen::filament/resources/request-man-power.table.placeholders.nama_karyawan_replacement'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('currentPendingApproval.approver_name')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.columns.current_pending_approval'))
                    ->placeholder(__('rekrutmen::filament/resources/request-man-power.table.placeholders.current_pending_approval'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('approver.name')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.columns.approved_by'))
                    ->placeholder(__('rekrutmen::filament/resources/request-man-power.table.placeholders.approved_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('email_address')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.columns.email_address'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.filters.status'))
                    ->options(RequestManPowerStatus::class),
                Tables\Filters\SelectFilter::make('status_kebutuhan')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.filters.status_kebutuhan'))
                    ->options(StatusKebutuhan::class),
                Tables\Filters\SelectFilter::make('fulfillment_status')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.filters.fulfillment_status'))
                    ->options(RequestManPowerFulfillmentStatus::class)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->whereFulfillmentStatus($data['value'] ?? null)),
                Tables\Filters\SelectFilter::make('division_id')
                    ->relationship(
                        name: 'division',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->with('company')->orderBy('name'),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Division $record): string => $record->nameWithCompany())
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.filters.division_id'))
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('approved_by')
                    ->relationship('approver', 'name')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.filters.approved_by'))
                    ->searchable(),
                Tables\Filters\TernaryFilter::make('has_job_posting')
                    ->label(__('rekrutmen::filament/resources/request-man-power.table.filters.has_job_posting'))
                    ->queries(
                        true: fn ($query) => $query->whereHas('jobPosting'),
                        false: fn ($query) => $query->whereDoesntHave('jobPosting'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistSortInSession()
            ->columnToggleFormColumns(2)
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([
                ActionGroup::make([
                    Action::make('view_progress')
                        ->label(__('rekrutmen::filament/resources/request-man-power.table.actions.view_progress'))
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->color('gray')
                        ->url(fn (RequestManPower $record): string => $record->getPublicProgressUrl())
                        ->openUrlInNewTab(),
                    ViewAction::make(),
                    EditAction::make(),
                    Action::make('start_pending_approval')
                        ->label(__('rekrutmen::filament/resources/request-man-power.table.actions.start_pending_approval'))
                        ->icon('heroicon-o-paper-airplane')
                        ->color('warning')
                        ->visible(fn (RequestManPower $record): bool => self::canStartPendingApproval($record))
                        ->action(function (RequestManPower $record): void {
                            $approval = $record->initializeAndNotifyApprovalWorkflow(replaceExisting: true, rotateToken: true);

                            if (! $approval) {
                                Notification::make()
                                    ->title(__('rekrutmen::filament/resources/request-man-power.notifications.pending_approval_not_configured'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title(__('rekrutmen::filament/resources/request-man-power.notifications.pending_approval_started'))
                                ->success()
                                ->send();
                        }),
                    Action::make('approve')
                        ->label(__('rekrutmen::filament/resources/request-man-power.table.actions.approve'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (RequestManPower $record) => self::canManualApproveOrReject($record))
                        ->action(function (RequestManPower $record): void {
                            try {
                                $record->approveBy(Auth::id());

                                Notification::make()
                                    ->title(__('rekrutmen::filament/resources/request-man-power.notifications.approved'))
                                    ->success()
                                    ->send();
                            } catch (\Throwable $exception) {
                                Log::error('Failed to approve manpower request.', [
                                    'request_man_power_id' => $record->getKey(),
                                    'exception'            => $exception,
                                ]);

                                Notification::make()
                                    ->title(__('rekrutmen::filament/resources/request-man-power.errors.approval_failed'))
                                    ->danger()
                                    ->send();
                            }
                        }),
                    Action::make('reject')
                        ->label(__('rekrutmen::filament/resources/request-man-power.table.actions.reject'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (RequestManPower $record) => self::canManualApproveOrReject($record))
                        ->requiresConfirmation()
                        ->action(function (RequestManPower $record): void {
                            $record->rejectBy(Auth::id());

                            Notification::make()
                                ->title(__('rekrutmen::filament/resources/request-man-power.notifications.rejected'))
                                ->success()
                                ->send();
                        }),
                    Action::make('hold')
                        ->label(__('rekrutmen::filament/resources/request-man-power.table.actions.hold'))
                        ->icon('heroicon-o-pause-circle')
                        ->color('gray')
                        ->visible(fn (RequestManPower $record): bool => self::canHold($record))
                        ->requiresConfirmation()
                        ->modalHeading(__('rekrutmen::filament/resources/request-man-power.table.actions.hold_modal_heading'))
                        ->modalDescription(__('rekrutmen::filament/resources/request-man-power.table.actions.hold_modal_description'))
                        ->modalSubmitActionLabel(__('rekrutmen::filament/resources/request-man-power.table.actions.hold_modal_submit'))
                        ->schema([
                            Textarea::make('hold_reason')
                                ->label(__('rekrutmen::filament/resources/request-man-power.form.fields.hold_reason'))
                                ->required()
                                ->minLength(5)
                                ->maxLength(2000)
                                ->rows(4),
                        ])
                        ->action(function (RequestManPower $record, array $data): void {
                            $record->markOnHold(Auth::id(), (string) ($data['hold_reason'] ?? ''));

                            Notification::make()
                                ->title(__('rekrutmen::filament/resources/request-man-power.notifications.hold'))
                                ->success()
                                ->send();
                        }),
                    Action::make('resume_hold')
                        ->label(__('rekrutmen::filament/resources/request-man-power.table.actions.resume_hold'))
                        ->icon('heroicon-o-play-circle')
                        ->color('success')
                        ->visible(fn (RequestManPower $record): bool => self::canResumeHold($record))
                        ->requiresConfirmation()
                        ->action(function (RequestManPower $record): void {
                            $record->resumeFromHold(Auth::id());

                            Notification::make()
                                ->title(__('rekrutmen::filament/resources/request-man-power.notifications.resume_hold'))
                                ->success()
                                ->send();
                        }),
                    Action::make('resend_pending_approval')
                        ->label(__('rekrutmen::filament/resources/request-man-power.table.actions.resend_pending_approval'))
                        ->icon('heroicon-o-paper-airplane')
                        ->color('info')
                        ->visible(fn (RequestManPower $record): bool => self::canResendPendingApproval($record))
                        ->action(function (RequestManPower $record): void {
                            $record->notifyCurrentPendingApproval(true);

                            Notification::make()
                                ->title(__('rekrutmen::filament/resources/request-man-power.notifications.pending_approval_resent'))
                                ->success()
                                ->send();
                        }),
                    Action::make('set_pending')
                        ->label(__('rekrutmen::filament/resources/request-man-power.table.actions.set_pending'))
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->visible(fn (RequestManPower $record) => self::canSetPending($record))
                        ->requiresConfirmation()
                        ->action(function (RequestManPower $record): void {
                            $record->markPending(Auth::id());

                            Notification::make()
                                ->title(__('rekrutmen::filament/resources/request-man-power.notifications.set_pending'))
                                ->success()
                                ->send();
                        }),
                ])->label(__('rekrutmen::filament/resources/request-man-power.table.actions.more')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->recordUrl(fn (RequestManPower $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function getRelations(): array
    {
        return [
            ApprovalsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRequestManPowers::route('/'),
            'create' => Pages\CreateRequestManPower::route('/create'),
            'view'   => Pages\ViewRequestManPower::route('/{record}'),
            'edit'   => Pages\EditRequestManPower::route('/{record}/edit'),
        ];
    }

    protected static function isReplacementStatus(mixed $statusKebutuhan): bool
    {
        if ($statusKebutuhan instanceof StatusKebutuhan) {
            return $statusKebutuhan === StatusKebutuhan::REPLACEMENT;
        }

        if (! is_string($statusKebutuhan)) {
            return false;
        }

        return in_array($statusKebutuhan, [StatusKebutuhan::REPLACEMENT->value, StatusKebutuhan::REPLACEMENT->name], true);
    }

    public static function formatTablePositionDescription(RequestManPower $requestManPower): string
    {
        return implode(' | ', array_filter([
            'MPP #'.$requestManPower->getKey(),
            trim((string) $requestManPower->lokasi_penempatan) ?: null,
            $requestManPower->division?->nameWithCompany() ?: null,
            $requestManPower->status_kebutuhan?->getLabel() ?? null,
            trim((string) $requestManPower->nama_pengaju) ?: null,
            is_numeric($requestManPower->job_posting_id) ? 'Lowongan #'.$requestManPower->job_posting_id : null,
        ]));
    }

    public static function formatApprovalDescription(RequestManPower $requestManPower): ?string
    {
        if ($requestManPower->hasMissingApprovalWorkflow()) {
            return __('rekrutmen::filament/resources/request-man-power.table.descriptions.approval_workflow_missing');
        }

        if ($requestManPower->currentPendingApproval?->approver_name) {
            return __('rekrutmen::filament/resources/request-man-power.table.columns.current_pending_approval').': '.$requestManPower->currentPendingApproval->approver_name;
        }

        if ($requestManPower->approver?->name) {
            return __('rekrutmen::filament/resources/request-man-power.table.columns.approved_by').': '.$requestManPower->approver->name;
        }

        return null;
    }

    public static function canApproveOrReject(RequestManPower $record): bool
    {
        return self::statusIsPending($record->status) && self::currentUserCanManageApproval($record);
    }

    public static function canManualApproveOrReject(RequestManPower $record): bool
    {
        return self::canApproveOrReject($record) && ! $record->approvals()->exists();
    }

    public static function canStartPendingApproval(RequestManPower $record): bool
    {
        return self::canApproveOrReject($record) && $record->hasMissingApprovalWorkflow();
    }

    public static function canSetPending(RequestManPower $record): bool
    {
        return self::statusIsRejected($record->status)
            && self::currentUserCanManageApproval($record);
    }

    public static function canHold(RequestManPower $record): bool
    {
        return self::statusIsApproved($record->status) && self::currentUserCanManageApproval($record);
    }

    public static function canResumeHold(RequestManPower $record): bool
    {
        return self::statusIsHold($record->status) && self::currentUserCanManageApproval($record);
    }

    public static function canResendPendingApproval(RequestManPower $record): bool
    {
        return self::statusIsPending($record->status)
            && self::currentUserCanManageApproval($record)
            && $record->currentPendingApproval()->exists();
    }

    protected static function statusIsPending(mixed $status): bool
    {
        if ($status instanceof RequestManPowerStatus) {
            return $status === RequestManPowerStatus::PENDING;
        }

        if (! is_string($status)) {
            return false;
        }

        return strtolower($status) === RequestManPowerStatus::PENDING->value;
    }

    protected static function statusIsApproved(mixed $status): bool
    {
        if ($status instanceof RequestManPowerStatus) {
            return $status === RequestManPowerStatus::APPROVED;
        }

        if (! is_string($status)) {
            return false;
        }

        return strtolower($status) === RequestManPowerStatus::APPROVED->value;
    }

    protected static function statusIsRejected(mixed $status): bool
    {
        if ($status instanceof RequestManPowerStatus) {
            return $status === RequestManPowerStatus::REJECTED;
        }

        if (! is_string($status)) {
            return false;
        }

        return strtolower($status) === RequestManPowerStatus::REJECTED->value;
    }

    protected static function statusIsHold(mixed $status): bool
    {
        if ($status instanceof RequestManPowerStatus) {
            return $status === RequestManPowerStatus::HOLD;
        }

        if (! is_string($status)) {
            return false;
        }

        return strtolower($status) === RequestManPowerStatus::HOLD->value;
    }

    protected static function currentUserCanManageApproval(?RequestManPower $record = null): bool
    {
        $user = Auth::user();

        if (! $user || ! method_exists($user, 'can')) {
            return false;
        }

        if ($record) {
            return $user->can('update', $record);
        }

        return $user->can('update_rekrutmen_request::man::power');
    }
}
