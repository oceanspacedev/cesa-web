<?php

namespace Cesa\Rekrutmen\Filament\Resources;

use Cesa\Rekrutmen\Enums\JobApplicationGender;
use Cesa\Rekrutmen\Enums\JobApplicationMaritalStatus;
use Cesa\Rekrutmen\Enums\JobApplicationStatus;
use Cesa\Rekrutmen\Filament\Resources\JobApplicationResource\Pages;
use Cesa\Rekrutmen\Filament\Resources\JobApplicationResource\RelationManagers\HistoriesRelationManager;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Services\RekrutmenStorage;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Js;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;
use Webkul\Security\Traits\HasResourcePermissionQuery;

class JobApplicationResource extends Resource
{
    use HasResourcePermissionQuery;

    protected static ?string $model = JobApplication::class;

    protected static \BackedEnum|string|null $navigationIcon = null;

    protected static ?string $slug = 'rekrutmen-shield/job-applications';

    protected static ?int $navigationSort = 3;

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
        return __('rekrutmen::filament/resources/job-application.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('rekrutmen::filament/resources/job-application.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('rekrutmen::filament/resources/job-application.model.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Grid::make(3)->schema([
                    Group::make([
                        Section::make(__('rekrutmen::filament/resources/job-application.form.sections.candidate_information'))
                            ->schema([
                                Forms\Components\TextInput::make('full_name')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.full_name'))
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('email')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.email'))
                                    ->email()
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\Select::make('gender')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.gender'))
                                    ->options(JobApplicationGender::class)
                                    ->required(),
                                Forms\Components\DatePicker::make('birth_date')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.birth_date'))
                                    ->required(),
                                Forms\Components\Select::make('marital_status')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.marital_status'))
                                    ->options(JobApplicationMaritalStatus::class)
                                    ->required(),
                                Forms\Components\TextInput::make('whatsapp_number')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.whatsapp_number'))
                                    ->tel()
                                    ->required()
                                    ->maxLength(30),
                                Forms\Components\TextInput::make('active_phone')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.active_phone'))
                                    ->tel()
                                    ->required()
                                    ->maxLength(30),
                                Forms\Components\TextInput::make('emergency_contact_name')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.emergency_contact_name'))
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('emergency_contact_relation')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.emergency_contact_relation'))
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('emergency_contact_phone')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.emergency_contact_phone'))
                                    ->tel()
                                    ->required()
                                    ->maxLength(30),
                                Forms\Components\Textarea::make('address_ktp')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.address_ktp'))
                                    ->required()
                                    ->columnSpanFull(),
                                Forms\Components\Textarea::make('address_domicile')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.address_domicile'))
                                    ->required()
                                    ->columnSpanFull(),
                            ])->columns(2),
                    ])->columnSpan(2),

                    Group::make([
                        Section::make(__('rekrutmen::filament/resources/job-application.form.sections.application_details'))
                            ->schema([
                                Forms\Components\Select::make('job_posting_id')
                                    ->relationship('jobPosting', 'title')
                                    ->required()
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                                        $set('current_stage_id', JobApplication::resolveInitialStageIdForJobPostingId($state));
                                    })
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.job_posting_id')),
                                Forms\Components\Select::make('current_stage_id')
                                    ->relationship('currentStage', 'name')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.current_stage_id'))
                                    ->disabled()
                                    ->dehydrated(),
                                Forms\Components\TextInput::make('source')
                                    ->label('Sumber Lamaran')
                                    ->placeholder('Misal: Glints, Jobstreet, dll')
                                    ->default('Manual Input')
                                    ->maxLength(100),
                                Forms\Components\Select::make('status')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.status'))
                                    ->required()
                                    ->options(JobApplicationStatus::class)
                                    ->default(JobApplicationStatus::IN_PROGRESS)
                                    ->disabled()
                                    ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                                Forms\Components\FileUpload::make('photo_path')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.photo_path'))
                                    ->disk(fn (?JobApplication $record): string => $record?->resolveAttachmentDisk('photo') ?? JobApplication::resumeDisk())
                                    ->directory(JobApplication::PHOTO_DIRECTORY)
                                    ->image()
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                    ->maxSize(5120)
                                    ->visibility(fn (Forms\Components\FileUpload $component): string => app(RekrutmenStorage::class)->visibility($component->getDiskName()))
                                    ->fetchFileInformation(false)
                                    ->downloadable()
                                    ->openable()
                                    ->getUploadedFileUsing(fn (?JobApplication $record, string $file): ?array => self::resolveUploadedFileMetadata($record, $file, 'photo'))
                                    ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file, ?JobApplication $record): string => self::saveAttachment($file, $record, 'photo')),
                                Forms\Components\FileUpload::make('resume_path')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.resume_path'))
                                    ->disk(fn (?JobApplication $record): string => $record?->resolveAttachmentDisk('resume') ?? JobApplication::resumeDisk())
                                    ->directory(JobApplication::RESUME_DIRECTORY)
                                    ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                                    ->maxSize(5120) // 5MB
                                    ->visibility(fn (Forms\Components\FileUpload $component): string => app(RekrutmenStorage::class)->visibility($component->getDiskName()))
                                    ->fetchFileInformation(false)
                                    ->downloadable()
                                    ->openable()
                                    ->getUploadedFileUsing(fn (?JobApplication $record, string $file): ?array => self::resolveUploadedFileMetadata($record, $file, 'resume'))
                                    ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file, ?JobApplication $record): string => self::saveAttachment($file, $record, 'resume')),
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
                        Section::make(__('rekrutmen::filament/resources/job-application.form.sections.candidate_information'))
                            ->schema([
                                TextEntry::make('full_name')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.full_name')),
                                TextEntry::make('email')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.email')),
                                TextEntry::make('gender')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.gender'))
                                    ->formatStateUsing(fn ($state) => $state instanceof JobApplicationGender ? $state->name : $state),
                                TextEntry::make('birth_date')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.birth_date'))
                                    ->date('d F Y'),
                                TextEntry::make('marital_status')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.marital_status'))
                                    ->formatStateUsing(fn ($state) => $state instanceof JobApplicationMaritalStatus ? $state->name : $state),
                                TextEntry::make('whatsapp_number')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.whatsapp_number')),
                                TextEntry::make('active_phone')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.active_phone')),
                                TextEntry::make('emergency_contact_name')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.emergency_contact_name')),
                                TextEntry::make('emergency_contact_relation')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.emergency_contact_relation')),
                                TextEntry::make('emergency_contact_phone')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.emergency_contact_phone')),
                                TextEntry::make('address_ktp')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.address_ktp'))
                                    ->columnSpanFull(),
                                TextEntry::make('address_domicile')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.address_domicile'))
                                    ->columnSpanFull(),
                            ])->columns(2),
                    ])->columnSpan(2),

                    Group::make([
                        Section::make(__('rekrutmen::filament/resources/job-application.form.sections.application_details'))
                            ->schema([
                                TextEntry::make('jobPosting.title')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.job_posting_id')),
                                TextEntry::make('currentStage.name')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.current_stage_id'))
                                    ->badge(),
                                TextEntry::make('source')
                                    ->label('Sumber Lamaran'),
                                TextEntry::make('status')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.status'))
                                    ->badge(),
                                ImageEntry::make('photo_path')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.photo_path'))
                                    ->state(fn (JobApplication $record): ?string => self::resolveAttachmentDownloadUrl($record, 'photo'))
                                    ->height(100)
                                    ->visible(fn ($record) => filled($record->photo_path)),
                                TextEntry::make('resume_path')
                                    ->label(__('rekrutmen::filament/resources/job-application.form.fields.resume_path'))
                                    ->formatStateUsing(fn () => 'Download Resume')
                                    ->color('primary')
                                    ->url(fn (JobApplication $record) => self::resolveAttachmentDownloadUrl($record, 'resume'))
                                    ->openUrlInNewTab()
                                    ->visible(fn ($record) => filled($record->resume_path)),
                            ])->columns(1),
                    ])->columnSpan(1),
                ])->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['jobPosting', 'currentStage'])->withCount('histories'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('full_name')
                    ->label(__('rekrutmen::filament/resources/job-application.table.columns.full_name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('jobPosting.title')
                    ->label(__('rekrutmen::filament/resources/job-application.table.columns.job_posting'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('source')
                    ->label('Sumber Lamaran')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('rekrutmen::filament/resources/job-application.table.columns.email'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('whatsapp_number')
                    ->label(__('rekrutmen::filament/resources/job-application.table.columns.whatsapp_number'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('active_phone')
                    ->label(__('rekrutmen::filament/resources/job-application.table.columns.active_phone'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('currentStage.name')
                    ->label(__('rekrutmen::filament/resources/job-application.table.columns.current_stage'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('rekrutmen::filament/resources/job-application.table.columns.status'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('histories_count')
                    ->label(__('rekrutmen::filament/resources/job-application.table.columns.histories_count'))
                    ->numeric()
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('job_posting_id')
                    ->relationship('jobPosting', 'title')
                    ->label(__('rekrutmen::filament/resources/job-application.table.filters.job_posting_id'))
                    ->searchable(),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('rekrutmen::filament/resources/job-application.table.filters.status'))
                    ->options(JobApplicationStatus::class),
                Tables\Filters\SelectFilter::make('current_stage_id')
                    ->relationship('currentStage', 'name')
                    ->label(__('rekrutmen::filament/resources/job-application.table.filters.current_stage_id'))
                    ->searchable(),
            ])
            ->recordActions([
                Action::make('copy_candidate_contact')
                    ->label(__('rekrutmen::filament/resources/job-application.table.actions.copy_candidate_contact'))
                    ->icon('heroicon-o-clipboard-document')
                    ->color('gray')
                    ->actionJs(fn (JobApplication $record): string => static::copyCandidateContactActionJs($record)),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    Action::make('mark_hired')
                        ->label(__('rekrutmen::filament/resources/job-application.table.actions.mark_hired'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (JobApplication $record): bool => $record->canMarkAsHired())
                        ->form([
                            Forms\Components\DatePicker::make('activity_date')
                                ->label(__('rekrutmen::filament/resources/activity-log.form.fields.activity_date'))
                                ->required()
                                ->default(now()->toDateString()),
                            Forms\Components\Textarea::make('notes')
                                ->label(__('rekrutmen::filament/resources/job-application.table.actions.notes'))
                                ->required()
                                ->maxLength(65535),
                        ])
                        ->action(function (JobApplication $record, array $data): void {
                            $record->markAsHired(
                                $data['notes'] ?? null,
                                auth()->id(),
                                (string) $data['activity_date'],
                            );

                            Notification::make()
                                ->title(__('rekrutmen::filament/resources/job-application.notifications.marked_hired'))
                                ->success()
                                ->send();
                        }),
                    Action::make('mark_withdrawn')
                        ->label(__('rekrutmen::filament/resources/job-application.table.actions.mark_withdrawn'))
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->visible(fn (JobApplication $record): bool => $record->canMarkAsWithdrawn())
                        ->form([
                            Forms\Components\DatePicker::make('activity_date')
                                ->label(__('rekrutmen::filament/resources/activity-log.form.fields.activity_date'))
                                ->required()
                                ->default(now()->toDateString()),
                            Forms\Components\Textarea::make('notes')
                                ->label(__('rekrutmen::filament/resources/job-application.table.actions.notes'))
                                ->required()
                                ->maxLength(65535),
                        ])
                        ->action(function (JobApplication $record, array $data): void {
                            $record->markAsWithdrawn(
                                $data['notes'] ?? null,
                                auth()->id(),
                                (string) $data['activity_date'],
                            );

                            Notification::make()
                                ->title(__('rekrutmen::filament/resources/job-application.notifications.marked_withdrawn'))
                                ->success()
                                ->send();
                        }),
                    Action::make('download_resume')
                        ->label(__('rekrutmen::filament/resources/job-application.table.actions.download_resume'))
                        ->icon('heroicon-o-document-arrow-down')
                        ->url(fn (JobApplication $record) => self::resolveAttachmentDownloadUrl($record, 'resume'))
                        ->openUrlInNewTab()
                        ->visible(fn (JobApplication $record) => filled($record->resume_path)),
                    Action::make('download_photo')
                        ->label(__('rekrutmen::filament/resources/job-application.table.actions.download_photo'))
                        ->icon('heroicon-o-photo')
                        ->url(fn (JobApplication $record) => self::resolveAttachmentDownloadUrl($record, 'photo'))
                        ->openUrlInNewTab()
                        ->visible(fn (JobApplication $record) => filled($record->photo_path)),
                ])->label(__('rekrutmen::filament/resources/job-application.table.actions.more')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            HistoriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListJobApplications::route('/'),
            'create' => Pages\CreateJobApplication::route('/create'),
            'board'  => Pages\PipelineBoard::route('/board'),
            'view'   => Pages\ViewJobApplication::route('/{record}'),
            'edit'   => Pages\EditJobApplication::route('/{record}/edit'),
        ];
    }

    public static function formatCandidateContactClipboardText(JobApplication $record): string
    {
        $record->loadMissing('jobPosting');

        return implode(' ', [
            static::normalizeClipboardValue($record->full_name),
            static::normalizeClipboardValue($record->jobPosting?->title),
            static::normalizeClipboardValue($record->active_whatsapp ?: $record->whatsapp_number ?: $record->active_phone),
        ]);
    }

    public static function resolveAttachmentDownloadUrl(JobApplication $record, string $attachment): ?string
    {
        if (! auth()->user() || blank($record->resolveAttachmentPath($attachment))) {
            return null;
        }

        return URL::temporarySignedRoute(
            'rekrutmen.job-applications.attachments.download',
            now()->addMinutes(60),
            [
                'jobApplication' => $record,
                'attachment'     => $attachment,
            ],
        );
    }

    private static function copyCandidateContactActionJs(JobApplication $record): string
    {
        $clipboardText = Js::from(static::formatCandidateContactClipboardText($record));
        $copyMessage = Js::from(__('rekrutmen::filament/resources/job-application.table.actions.copy_candidate_contact_message'));

        return <<<JS
            const text = {$clipboardText};
            const fallbackCopy = (value) => {
                const textarea = document.createElement('textarea');
                textarea.value = value;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.focus();
                textarea.select();
                document.execCommand('copy');
                textarea.remove();
            };

            if (window.navigator.clipboard?.writeText) {
                window.navigator.clipboard.writeText(text).catch(() => fallbackCopy(text));
            } else {
                fallbackCopy(text);
            }

            \$tooltip({$copyMessage}, { timeout: 1500 });
        JS;
    }

    private static function normalizeClipboardValue(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : '-';
    }

    private static function saveAttachment(TemporaryUploadedFile $file, ?JobApplication $record, string $attachment): string
    {
        $disk = JobApplication::resumeDisk();
        $directory = $attachment === 'photo' ? JobApplication::PHOTO_DIRECTORY : JobApplication::RESUME_DIRECTORY;
        $path = app(RekrutmenStorage::class)->storeUploadedFile($file, $directory, $disk);
        $record?->setAttribute($attachment.'_disk', $disk);

        return $path;
    }

    private static function resolveUploadedFileMetadata(?JobApplication $record, string $file, string $attachment): ?array
    {
        $isSavedAttachment = $record?->getKey() && $record->resolveAttachmentPath($attachment) === $file;
        $disk = $isSavedAttachment
            ? $record->resolveAttachmentDisk($attachment)
            : app(RekrutmenStorage::class)->resolveDisk($file, JobApplication::resumeDisk());

        if ($disk === null) {
            return null;
        }

        try {
            $storage = Storage::disk($disk);

            return [
                'name' => basename($file),
                'size' => $storage->size($file),
                'type' => $storage->mimeType($file),
                'url'  => $isSavedAttachment
                    ? self::resolveAttachmentDownloadUrl($record, $attachment)
                    : app(RekrutmenStorage::class)->url($file, $disk),
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
