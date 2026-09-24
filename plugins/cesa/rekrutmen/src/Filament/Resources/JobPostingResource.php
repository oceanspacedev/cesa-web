<?php

namespace Cesa\Rekrutmen\Filament\Resources;

use Cesa\Rekrutmen\Enums\JobApplicationStatus;
use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Cesa\Rekrutmen\Filament\Resources\JobPostingResource\Pages;
use Cesa\Rekrutmen\Filament\Resources\JobPostingResource\RelationManagers\RequestManPowersRelationManager;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Services\RekrutmenStorage;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;
use Webkul\Security\Traits\HasResourcePermissionQuery;

class JobPostingResource extends Resource
{
    use HasResourcePermissionQuery;

    public const LINKED_REQUEST_MAN_POWER_IDS_FIELD = 'linked_request_man_power_ids';

    protected static ?string $model = JobPosting::class;

    protected static \BackedEnum|string|null $navigationIcon = null;

    protected static ?string $slug = 'rekrutmen-shield/job-postings';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): string
    {
        return __('admin.navigation.rekrutmen');
    }

    public static function getNavigationLabel(): string
    {
        return __('rekrutmen::filament/resources/job-posting.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('rekrutmen::filament/resources/job-posting.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('rekrutmen::filament/resources/job-posting.model.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Grid::make(3)->schema([
                    Group::make([
                        Section::make(__('rekrutmen::filament/resources/job-posting.form.sections.job_information'))
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->label(__('rekrutmen::filament/resources/job-posting.form.fields.title'))
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (string $operation, $state, Set $set) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),
                                Forms\Components\TextInput::make('slug')
                                    ->label(__('rekrutmen::filament/resources/job-posting.form.fields.slug'))
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(JobPosting::class, 'slug', ignoreRecord: true),
                                Forms\Components\TextInput::make('location')
                                    ->label(__('rekrutmen::filament/resources/job-posting.form.fields.location'))
                                    ->maxLength(255),
                            ])->columns(2),

                        Section::make(__('rekrutmen::filament/resources/job-posting.form.sections.details'))
                            ->schema([
                                Forms\Components\Textarea::make('description')
                                    ->label(__('rekrutmen::filament/resources/job-posting.form.fields.description'))
                                    ->required()
                                    ->rows(5)
                                    ->columnSpanFull(),
                                Forms\Components\Textarea::make('requirements')
                                    ->label(__('rekrutmen::filament/resources/job-posting.form.fields.requirements'))
                                    ->required()
                                    ->rows(5)
                                    ->columnSpanFull(),
                            ]),
                    ])->columnSpan(2),

                    Group::make([
                        Section::make(__('rekrutmen::filament/resources/job-posting.form.sections.settings'))
                            ->schema([
                                Forms\Components\Placeholder::make('linked_request_man_powers_overview')
                                    ->label(__('rekrutmen::filament/resources/job-posting.form.fields.linked_request_man_powers_overview'))
                                    ->content(fn (?JobPosting $record): string => $record
                                        ? self::formatLinkedRequestManPowersOverview($record)
                                        : '-')
                                    ->visible(fn (string $operation): bool => $operation === 'edit'),
                                Forms\Components\Select::make(self::LINKED_REQUEST_MAN_POWER_IDS_FIELD)
                                    ->label(__('rekrutmen::filament/resources/job-posting.form.fields.linked_request_man_power_ids'))
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->options(fn (?JobPosting $record): array => self::resolveEditableRequestManPowerOptions($record))
                                    ->afterStateHydrated(function (Forms\Components\Select $component, ?JobPosting $record): void {
                                        if (! $record) {
                                            return;
                                        }

                                        $component->state(self::resolveEditableLinkedRequestManPowerIds($record));
                                    })
                                    ->helperText(__('rekrutmen::filament/resources/job-posting.form.helper_texts.linked_request_man_power_ids'))
                                    ->visible(fn (string $operation): bool => $operation === 'edit')
                                    ->dehydrated(fn (string $operation): bool => $operation === 'edit'),
                                Forms\Components\Select::make('request_man_power_id')
                                    ->relationship(
                                        name: 'requestManPower',
                                        titleAttribute: 'posisi_dibutuhkan',
                                        modifyQueryUsing: fn (Builder $query) => $query
                                            ->with('jobPosting')
                                            ->orderByDesc('created_at')
                                    )
                                    ->getOptionLabelFromRecordUsing(fn (RequestManPower $record): string => self::formatRequestManPowerOptionLabel($record))
                                    ->searchable()
                                    ->preload()
                                    ->nullable()
                                    ->live()
                                    ->afterStateUpdated(function (mixed $state, Set $set, Get $get, string $operation): void {
                                        if (! $state || $operation !== 'create') {
                                            return;
                                        }

                                        $requestManPower = RequestManPower::query()->find($state);

                                        if (! $requestManPower) {
                                            return;
                                        }

                                        foreach (self::resolveAutofillDataFromRequestManPower($requestManPower) as $field => $value) {
                                            if ($value === null && blank($get($field))) {
                                                continue;
                                            }

                                            $set($field, $value);
                                        }
                                    })
                                    ->label(__('rekrutmen::filament/resources/job-posting.form.fields.request_man_power_id'))
                                    ->visible(fn (string $operation): bool => $operation === 'create'),
                                Forms\Components\Select::make('rekrutmen_pipeline_id')
                                    ->relationship('rekrutmenPipeline', 'name')
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->label(__('rekrutmen::filament/resources/job-posting.form.fields.rekrutmen_pipeline_id')),
                                Forms\Components\DatePicker::make('closing_date')
                                    ->label(__('rekrutmen::filament/resources/job-posting.form.fields.closing_date')),
                                Forms\Components\Toggle::make('is_published')
                                    ->label(__('rekrutmen::filament/resources/job-posting.form.fields.is_published'))
                                    ->default(false),
                                Forms\Components\FileUpload::make('thumbnail_path')
                                    ->label(__('rekrutmen::filament/resources/job-posting.form.fields.thumbnail_path'))
                                    ->disk(fn (?JobPosting $record): string => $record?->resolveThumbnailDisk() ?? JobPosting::thumbnailDisk())
                                    ->directory(JobPosting::THUMBNAIL_DIRECTORY)
                                    ->image()
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                    ->maxSize(5120)
                                    ->visibility(fn (Forms\Components\FileUpload $component): string => app(RekrutmenStorage::class)->visibility($component->getDiskName()))
                                    ->fetchFileInformation(false)
                                    ->imagePreviewHeight('160')
                                    ->openable()
                                    ->getUploadedFileUsing(fn (?JobPosting $record, string $file): ?array => self::resolveThumbnailMetadata($record, $file))
                                    ->saveUploadedFileUsing(function (TemporaryUploadedFile $file, ?JobPosting $record): string {
                                        $disk = JobPosting::thumbnailDisk();
                                        $path = app(RekrutmenStorage::class)->storeUploadedFile($file, JobPosting::THUMBNAIL_DIRECTORY, $disk);
                                        $record?->setAttribute('thumbnail_disk', $disk);

                                        return $path;
                                    }),
                            ])->columns(1),
                    ])->columnSpan(1),
                ])->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['requestManPower', 'requestManPowers', 'rekrutmenPipeline'])
                ->withCount(['applications', 'requestManPowers'])
                ->withSum([
                    'requestManPowers as requested_headcount_sum' => fn (Builder $query) => $query
                        ->whereNull((new RequestManPower)->qualifyColumn('deleted_at'))
                        ->whereIn('status', [
                            RequestManPowerStatus::APPROVED->value,
                            RequestManPowerStatus::HOLD->value,
                        ]),
                ], 'jumlah_karyawan_dibutuhkan'))
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.columns.id'))
                    ->formatStateUsing(fn (int|string|null $state): string => filled($state) ? 'Lowongan #'.$state : '-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.columns.title'))
                    ->description(fn (JobPosting $record): string => self::formatJobPostingContext($record))
                    ->searchable()
                    ->wrap()
                    ->lineClamp(2)
                    ->sortable(),
                Tables\Columns\TextColumn::make('rekrutmenPipeline.name')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.columns.rekrutmen_pipeline'))
                    ->badge()
                    ->color('gray')
                    ->hidden(),
                Tables\Columns\TextColumn::make('requestManPower.posisi_dibutuhkan')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.columns.request_man_power'))
                    ->placeholder(__('rekrutmen::filament/resources/job-posting.table.placeholders.request_man_power'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('request_man_powers_summary')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.columns.request_man_powers_summary'))
                    ->state(fn (JobPosting $record): array => $record->requestManPowers
                        ->sortBy('id')
                        ->map(fn (RequestManPower $requestManPower): string => self::formatRequestManPowerTableSummary($requestManPower))
                        ->values()
                        ->all())
                    ->placeholder(__('rekrutmen::filament/resources/job-posting.table.placeholders.request_man_powers_summary'))
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->wrap()
                    ->lineClamp(2)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('request_man_powers_count')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.columns.request_man_powers_count'))
                    ->numeric()
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('requested_headcount_sum')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.columns.requested_headcount_sum'))
                    ->state(fn (JobPosting $record): int => self::resolveRequestedHeadcount($record))
                    ->numeric()
                    ->suffix(' orang')
                    ->badge()
                    ->sortable(),
                Tables\Columns\ImageColumn::make('thumbnail_path')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.columns.thumbnail_path'))
                    ->state(fn (JobPosting $record): ?string => $record->thumbnail_url)
                    ->circular()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('location')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.columns.location'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('applications_count')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.columns.applications_count'))
                    ->numeric()
                    ->badge()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_published')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.columns.is_published'))
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('closing_date')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.columns.closing_date'))
                    ->date()
                    ->sortable(),
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
                Tables\Filters\TernaryFilter::make('is_published')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.filters.is_published')),
                Tables\Filters\SelectFilter::make('rekrutmen_pipeline_id')
                    ->relationship('rekrutmenPipeline', 'name')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.filters.rekrutmen_pipeline_id'))
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('request_man_power_link_id')
                    ->options(fn (): array => RequestManPower::query()
                        ->with('jobPosting')
                        ->orderByDesc('created_at')
                        ->get()
                        ->mapWithKeys(fn (RequestManPower $requestManPower): array => [
                            $requestManPower->getKey() => self::formatRequestManPowerOptionLabel($requestManPower),
                        ])
                        ->all())
                    ->label(__('rekrutmen::filament/resources/job-posting.table.filters.request_man_power_id'))
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas(
                            'requestManPowers',
                            fn (Builder $query): Builder => $query->whereKey((int) $data['value'])
                        )
                        : $query)
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('availability')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.filters.availability'))
                    ->form([
                        Forms\Components\Select::make('state')
                            ->label(__('rekrutmen::filament/resources/job-posting.table.filters.availability'))
                            ->options([
                                'open'    => __('rekrutmen::filament/resources/job-posting.table.filter_options.availability.open'),
                                'expired' => __('rekrutmen::filament/resources/job-posting.table.filter_options.availability.expired'),
                            ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['state'] ?? null) {
                            'open' => $query->where(function (Builder $query): void {
                                $query->whereNull('closing_date')
                                    ->orWhereDate('closing_date', '>=', today());
                            }),
                            'expired' => $query->whereDate('closing_date', '<', today()),
                            default   => $query,
                        };
                    }),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistSortInSession()
            ->columnToggleFormColumns(2)
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([
                EditAction::make(),
                Action::make('open_pipeline')
                    ->label(__('rekrutmen::filament/resources/job-posting.table.actions.open_pipeline'))
                    ->icon('heroicon-o-view-columns')
                    ->color('gray')
                    ->url(fn (JobPosting $record): string => JobApplicationResource::getUrl('board', ['job_posting_id' => $record->id])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->recordUrl(fn (JobPosting $record): string => static::getUrl('edit', ['record' => $record]));
    }

    private static function resolveThumbnailMetadata(?JobPosting $record, string $file): ?array
    {
        $storage = app(RekrutmenStorage::class);
        $disk = $file === $record?->thumbnail_path
            ? $record->resolveThumbnailDisk()
            : $storage->resolveDisk($file, JobPosting::thumbnailDisk());

        if ($disk === null) {
            $url = filter_var($file, FILTER_VALIDATE_URL) ? $storage->url($file) : null;

            return $url === null ? null : ['name' => basename($file), 'size' => 0, 'type' => null, 'url' => $url];
        }

        try {
            $filesystem = Storage::disk($disk);

            return [
                'name' => basename($file),
                'size' => $filesystem->size($file),
                'type' => $filesystem->mimeType($file),
                'url'  => $storage->url($file, $disk),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public static function getRelations(): array
    {
        return [
            RequestManPowersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListJobPostings::route('/'),
            'create' => Pages\CreateJobPosting::route('/create'),
            'edit'   => Pages\EditJobPosting::route('/{record}/edit'),
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     slug: string,
     *     location: ?string,
     *     description: ?string,
     *     requirements: ?string,
     *     closing_date: ?string,
     *     rekrutmen_pipeline_id: ?int
     * }
     */
    public static function resolveAutofillDataFromRequestManPower(RequestManPower $requestManPower): array
    {
        $title = trim(implode(' ', array_filter([
            is_string($requestManPower->posisi_dibutuhkan) ? trim($requestManPower->posisi_dibutuhkan) : $requestManPower->posisi_dibutuhkan,
            is_string($requestManPower->lokasi_penempatan) ? trim($requestManPower->lokasi_penempatan) : $requestManPower->lokasi_penempatan,
        ])));

        return [
            'title'                 => $title,
            'slug'                  => Str::slug($title),
            'location'              => $requestManPower->lokasi_penempatan,
            'description'           => $requestManPower->job_description,
            'requirements'          => $requestManPower->requirements_kualifikasi,
            'closing_date'          => $requestManPower->estimasi_tanggal_join?->toDateString(),
            'rekrutmen_pipeline_id' => $requestManPower->jobPosting?->rekrutmen_pipeline_id,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function resolveEditableRequestManPowerOptions(?JobPosting $jobPosting): array
    {
        if (! $jobPosting || ! $jobPosting->exists) {
            return [];
        }

        $jobPostingId = (int) $jobPosting->getKey();
        $sourceRequestManPowerId = is_numeric($jobPosting->request_man_power_id)
            ? (int) $jobPosting->request_man_power_id
            : null;

        return RequestManPower::query()
            ->withTrashed()
            ->with('jobPosting')
            ->where(function (Builder $query) use ($jobPostingId, $sourceRequestManPowerId): void {
                $query->where(function (Builder $query) use ($jobPostingId): void {
                    $query
                        ->whereNull((new RequestManPower)->qualifyColumn('deleted_at'))
                        ->where(function (Builder $query) use ($jobPostingId): void {
                            $query
                                ->whereNull('job_posting_id')
                                ->orWhere('job_posting_id', $jobPostingId);
                        });
                })->orWhere('job_posting_id', $jobPostingId);

                if ($sourceRequestManPowerId) {
                    $query->orWhere('id', $sourceRequestManPowerId);
                }
            })
            ->orderByDesc('created_at')
            ->get()
            ->mapWithKeys(fn (RequestManPower $requestManPower): array => [
                (int) $requestManPower->getKey() => self::formatRequestManPowerOptionLabel($requestManPower),
            ])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    public static function resolveEditableLinkedRequestManPowerIds(JobPosting $jobPosting): array
    {
        $jobPosting->loadMissing(['requestManPowers', 'requestManPower']);

        $ids = $jobPosting->requestManPowers
            ->pluck('id')
            ->push($jobPosting->requestManPower?->getKey())
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        sort($ids);

        return $ids;
    }

    /**
     * @return array<int, int>
     */
    public static function normalizeLinkedRequestManPowerIds(mixed $requestManPowerIds): array
    {
        if (! is_array($requestManPowerIds)) {
            return [];
        }

        return collect($requestManPowerIds)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int|string>  $requestManPowerIds
     */
    public static function syncLinkedRequestManPowers(JobPosting $jobPosting, array $requestManPowerIds): void
    {
        $requestManPowerIds = self::normalizeLinkedRequestManPowerIds($requestManPowerIds);

        self::validateLinkedRequestManPowerSelection($jobPosting, $requestManPowerIds);

        DB::transaction(function () use ($jobPosting, $requestManPowerIds): void {
            $detachQuery = RequestManPower::query()
                ->where('job_posting_id', $jobPosting->getKey());

            if ($requestManPowerIds !== []) {
                $detachQuery->whereNotIn('id', $requestManPowerIds);
            }

            $detachQuery->update([
                'job_posting_id' => null,
                'updated_at'     => now(),
            ]);

            if ($requestManPowerIds !== []) {
                RequestManPower::query()
                    ->whereKey($requestManPowerIds)
                    ->update([
                        'job_posting_id' => $jobPosting->getKey(),
                        'updated_at'     => now(),
                    ]);
            }

            $jobPosting
                ->forceFill([
                    'request_man_power_id' => $requestManPowerIds[0] ?? null,
                ])
                ->saveQuietly();
        });
    }

    public static function formatRequestManPowerOptionLabel(RequestManPower $requestManPower): string
    {
        $parts = [
            'MPP #'.$requestManPower->getKey(),
            trim((string) $requestManPower->posisi_dibutuhkan) ?: '-',
            trim((string) $requestManPower->lokasi_penempatan) ?: '-',
            ((int) ($requestManPower->jumlah_karyawan_dibutuhkan ?? 0)).' orang',
            $requestManPower->status?->getLabel() ?? (string) ($requestManPower->status ?? '-'),
            trim((string) $requestManPower->nama_pengaju) ?: '-',
        ];

        if (is_numeric($requestManPower->job_posting_id)) {
            $parts[] = 'Lowongan #'.$requestManPower->job_posting_id;
        }

        return implode(' | ', $parts);
    }

    public static function formatRequestManPowerTableSummary(RequestManPower $requestManPower): string
    {
        return implode(' - ', array_filter([
            'MPP #'.$requestManPower->getKey(),
            trim((string) $requestManPower->posisi_dibutuhkan) ?: null,
            trim(implode(' | ', array_filter([
                trim((string) $requestManPower->lokasi_penempatan) ?: null,
                ((int) ($requestManPower->jumlah_karyawan_dibutuhkan ?? 0)).' orang',
                $requestManPower->status?->getLabel() ?? (string) ($requestManPower->status ?? '-'),
            ]))) ?: null,
        ]));
    }

    public static function formatJobPostingContext(JobPosting $jobPosting): string
    {
        return implode(' | ', array_filter([
            'Lowongan #'.$jobPosting->getKey(),
            trim((string) $jobPosting->location) ?: null,
            self::resolveRequestManPowersCount($jobPosting).' MPP',
            'Total kebutuhan '.self::resolveRequestedHeadcount($jobPosting).' orang',
        ]));
    }

    public static function formatLinkedRequestManPowersOverview(JobPosting $jobPosting): string
    {
        $jobPosting->loadMissing('requestManPowers');

        $requestSummaries = $jobPosting->requestManPowers
            ->sortBy('id')
            ->map(fn (RequestManPower $requestManPower): string => self::formatRequestManPowerTableSummary($requestManPower))
            ->values();

        return implode(' | ', array_filter([
            self::resolveRequestManPowersCount($jobPosting).' MPP',
            __('rekrutmen::filament/resources/job-posting.form.summaries.total_needed', [
                'count' => self::resolveRequestedHeadcount($jobPosting),
            ]),
            $requestSummaries->isNotEmpty() ? $requestSummaries->implode('; ') : null,
        ]));
    }

    private static function resolveRequestManPowersCount(JobPosting $jobPosting): int
    {
        if (is_numeric($jobPosting->request_man_powers_count ?? null)) {
            return (int) $jobPosting->request_man_powers_count;
        }

        if ($jobPosting->relationLoaded('requestManPowers')) {
            return $jobPosting->requestManPowers->count();
        }

        return $jobPosting->requestManPowers()->count();
    }

    private static function resolveRequestedHeadcount(JobPosting $jobPosting): int
    {
        if (is_numeric($jobPosting->requested_headcount_sum ?? null) && (int) $jobPosting->requested_headcount_sum > 0) {
            return (int) $jobPosting->requested_headcount_sum;
        }

        if ($jobPosting->relationLoaded('requestManPowers')) {
            return (int) $jobPosting->requestManPowers
                ->filter(fn (RequestManPower $requestManPower): bool => $requestManPower->deleted_at === null
                    && in_array($requestManPower->status, [
                        RequestManPowerStatus::PENDING,
                        RequestManPowerStatus::APPROVED,
                        RequestManPowerStatus::HOLD,
                    ], true))
                ->sum('jumlah_karyawan_dibutuhkan');
        }

        return (int) $jobPosting->requestManPowers()
            ->whereNull((new RequestManPower)->qualifyColumn('deleted_at'))
            ->whereIn('status', [
                RequestManPowerStatus::PENDING->value,
                RequestManPowerStatus::APPROVED->value,
                RequestManPowerStatus::HOLD->value,
            ])
            ->sum('jumlah_karyawan_dibutuhkan');
    }

    /**
     * @param  array<int, int>  $requestManPowerIds
     *
     * @throws ValidationException
     */
    public static function validateLinkedRequestManPowerSelection(JobPosting $jobPosting, array $requestManPowerIds): void
    {
        $requestManPowerIds = self::normalizeLinkedRequestManPowerIds($requestManPowerIds);
        $availableIds = array_keys(self::resolveEditableRequestManPowerOptions($jobPosting));
        $invalidIds = array_diff($requestManPowerIds, array_map('intval', $availableIds));

        if ($invalidIds !== []) {
            throw ValidationException::withMessages([
                self::LINKED_REQUEST_MAN_POWER_IDS_FIELD => __('rekrutmen::filament/resources/job-posting.form.errors.invalid_request_man_power_selection'),
            ]);
        }

        $neededHeadcount = self::resolveHeadcountForLinkedRequestManPowerIds($requestManPowerIds);
        $hiredCandidates = $jobPosting->applications()
            ->where('status', JobApplicationStatus::HIRED->value)
            ->count();

        if ($hiredCandidates <= $neededHeadcount) {
            return;
        }

        throw ValidationException::withMessages([
            self::LINKED_REQUEST_MAN_POWER_IDS_FIELD => __('rekrutmen::filament/resources/job-posting.form.errors.headcount_below_hired', [
                'needed' => $neededHeadcount,
                'hired'  => $hiredCandidates,
            ]),
        ]);
    }

    /**
     * @param  array<int, int>  $requestManPowerIds
     */
    private static function resolveHeadcountForLinkedRequestManPowerIds(array $requestManPowerIds): int
    {
        if ($requestManPowerIds === []) {
            return 0;
        }

        $requests = RequestManPower::query()
            ->withTrashed()
            ->whereKey($requestManPowerIds)
            ->get()
            ->keyBy(fn (RequestManPower $requestManPower): int => (int) $requestManPower->getKey());

        $linkedHeadcount = (int) $requests
            ->filter(fn (RequestManPower $requestManPower): bool => in_array($requestManPower->status, [
                RequestManPowerStatus::PENDING,
                RequestManPowerStatus::APPROVED,
                RequestManPowerStatus::HOLD,
            ], true))
            ->sum('jumlah_karyawan_dibutuhkan');

        if ($linkedHeadcount > 0) {
            return $linkedHeadcount;
        }

        $sourceRequestManPower = $requests->get($requestManPowerIds[0]);

        if (! $sourceRequestManPower) {
            return 0;
        }

        if (! in_array($sourceRequestManPower->status, [
            RequestManPowerStatus::PENDING,
            RequestManPowerStatus::APPROVED,
            RequestManPowerStatus::HOLD,
        ], true)) {
            return 0;
        }

        return max(1, (int) ($sourceRequestManPower->jumlah_karyawan_dibutuhkan ?? 1));
    }
}
