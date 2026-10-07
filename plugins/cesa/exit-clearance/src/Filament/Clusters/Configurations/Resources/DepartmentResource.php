<?php

namespace Cesa\ExitClearance\Filament\Clusters\Configurations\Resources;

use BackedEnum;
use Cesa\ExitClearance\Filament\Clusters\Configurations;
use Cesa\ExitClearance\Filament\Clusters\Configurations\Resources\DepartmentResource\Pages\ListDepartments;
use Cesa\ExitClearance\Models\Department;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DepartmentResource extends ExitClearanceConfigurationResource
{
    protected static ?string $model = Department::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $cluster = Configurations::class;

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getNavigationLabel(): string
    {
        return trans_choice('exit-clearance::filament/resources/department.label', 2);
    }

    public static function getPluralModelLabel(): string
    {
        return trans_choice('exit-clearance::filament/resources/department.label', 2);
    }

    public static function getModelLabel(): string
    {
        return trans_choice('exit-clearance::filament/resources/department.label', 1);
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label(__('exit-clearance::filament/resources/department.fields.code'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(50),
                TextInput::make('name')
                    ->label(__('exit-clearance::filament/resources/department.fields.name'))
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label(__('exit-clearance::filament/resources/department.fields.description'))
                    ->rows(3)
                    ->maxLength(1000),
                Select::make('approvers')
                    ->label(__('exit-clearance::filament/resources/department.fields.approvers'))
                    ->relationship('approvers', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->applyPermissionScope())
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->getOptionLabelFromRecordUsing(function (Model $record): string {
                        $name = $record->name ?? '';
                        $email = $record->email ?? '';
                        $title = $record->title ?? '';

                        $label = $name;
                        if ($email) {
                            $label .= " ({$email})";
                        }
                        if ($title) {
                            $label .= " - {$title}";
                        }

                        return $label;
                    }),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('exit-clearance::filament/resources/department.fields.code'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('exit-clearance::filament/resources/department.fields.name'))
                    ->searchable()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->slideOver()
                    ->modalWidth('md')
                    ->visible(fn (Department $record): bool => ! $record->trashed()),
                DeleteAction::make()
                    ->visible(fn (Department $record): bool => ! $record->trashed()),
                RestoreAction::make()
                    ->visible(fn (Department $record): bool => $record->trashed()),
                ForceDeleteAction::make()
                    ->visible(fn (Department $record): bool => $record->trashed()),
            ])
            ->bulkActions([
                DeleteBulkAction::make()
                    ->visible(fn ($livewire = null): bool => ! static::isArchivedTab($livewire)),
                RestoreBulkAction::make()
                    ->visible(fn ($livewire = null): bool => static::isArchivedTab($livewire)),
                ForceDeleteBulkAction::make()
                    ->visible(fn ($livewire = null): bool => static::isArchivedTab($livewire)),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListDepartments::route('/'),
        ];
    }
}
