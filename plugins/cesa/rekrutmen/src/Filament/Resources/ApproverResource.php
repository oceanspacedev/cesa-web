<?php

namespace Cesa\Rekrutmen\Filament\Resources;

use Cesa\Rekrutmen\Filament\Clusters\Configurations;
use Cesa\Rekrutmen\Filament\Resources\ApproverResource\Pages;
use Cesa\Rekrutmen\Models\Approver;
use Cesa\Rekrutmen\Models\Division;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ApproverResource extends RekrutmenConfigurationResource
{
    protected static ?string $model = Approver::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $cluster = Configurations::class;

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('rekrutmen::filament/resources/approver.navigation.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('rekrutmen::filament/resources/approver.model.plural');
    }

    public static function getModelLabel(): string
    {
        return __('rekrutmen::filament/resources/approver.model.singular');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->label(__('rekrutmen::filament/resources/approver.form.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label(__('rekrutmen::filament/resources/approver.form.fields.email'))
                    ->email()
                    ->required()
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label(__('rekrutmen::filament/resources/approver.form.fields.phone'))
                    ->tel()
                    ->maxLength(255),
                TextInput::make('title')
                    ->label(__('rekrutmen::filament/resources/approver.form.fields.title'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('approval_order')
                    ->label(__('rekrutmen::filament/resources/approver.form.fields.approval_order'))
                    ->numeric()
                    ->required()
                    ->default(1)
                    ->minValue(1)
                    ->helperText(__('rekrutmen::filament/resources/approver.form.helpers.approval_order')),
                Select::make('company_id')
                    ->label(__('rekrutmen::filament/resources/approver.form.fields.company_id'))
                    ->relationship(
                        name: 'company',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query
                            ->where('is_active', true)
                            ->orderBy('name')
                    )
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('division_id', null);
                    })
                    ->helperText(__('rekrutmen::filament/resources/approver.form.helpers.company_id')),
                Select::make('division_id')
                    ->label(__('rekrutmen::filament/resources/approver.form.fields.division_id'))
                    ->options(function (Get $get): array {
                        $companyId = $get('company_id');

                        if (! $companyId) {
                            return [];
                        }

                        return Division::query()
                            ->where('company_id', $companyId)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
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
                    })
                    ->helperText(__('rekrutmen::filament/resources/approver.form.helpers.division_id')),
                Toggle::make('is_active')
                    ->label(__('rekrutmen::filament/resources/approver.form.fields.is_active'))
                    ->default(true),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['company', 'division.company']))
            ->defaultSort('approval_order')
            ->columns([
                Tables\Columns\TextColumn::make('approval_order')
                    ->label(__('rekrutmen::filament/resources/approver.table.columns.approval_order'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('rekrutmen::filament/resources/approver.table.columns.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('rekrutmen::filament/resources/approver.table.columns.email'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('rekrutmen::filament/resources/approver.table.columns.title'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('company.name')
                    ->label(__('rekrutmen::filament/resources/approver.table.columns.company_id'))
                    ->placeholder(__('rekrutmen::filament/resources/approver.table.placeholders.company_id')),
                Tables\Columns\TextColumn::make('division.name')
                    ->label(__('rekrutmen::filament/resources/approver.table.columns.division_id'))
                    ->description(fn (Approver $record): ?string => $record->company?->name ?: $record->division?->company?->name)
                    ->placeholder(__('rekrutmen::filament/resources/approver.table.placeholders.division_id')),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('rekrutmen::filament/resources/approver.table.columns.is_active'))
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('company_id')
                    ->relationship('company', 'name')
                    ->label(__('rekrutmen::filament/resources/approver.table.filters.company_id'))
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('division_id')
                    ->relationship(
                        name: 'division',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->with('company')->orderBy('name'),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Division $record): string => $record->nameWithCompany())
                    ->label(__('rekrutmen::filament/resources/approver.table.filters.division_id'))
                    ->searchable()
                    ->preload(),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('rekrutmen::filament/resources/approver.table.filters.is_active')),
            ])
            ->recordActions([
                EditAction::make()
                    ->slideOver()
                    ->modalWidth('md')
                    ->visible(fn ($record): bool => ! method_exists($record, 'trashed') || ! $record->trashed()),
                DeleteAction::make()
                    ->visible(fn ($record): bool => ! method_exists($record, 'trashed') || ! $record->trashed()),
                RestoreAction::make()
                    ->visible(fn ($record): bool => method_exists($record, 'trashed') && $record->trashed()),
                ForceDeleteAction::make()
                    ->visible(fn ($record): bool => method_exists($record, 'trashed') && $record->trashed()),
            ])
            ->bulkActions([
                DeleteBulkAction::make()
                    ->visible(fn ($livewire = null): bool => ! static::isArchivedTab($livewire)),
                RestoreBulkAction::make()
                    ->visible(fn ($livewire = null): bool => static::isArchivedTab($livewire)),
                ForceDeleteBulkAction::make()
                    ->visible(fn ($livewire = null): bool => static::isArchivedTab($livewire)),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovers::route('/'),
        ];
    }
}
