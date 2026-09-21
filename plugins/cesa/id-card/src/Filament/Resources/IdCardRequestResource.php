<?php

namespace Cesa\IdCard\Filament\Resources;

use BackedEnum;
use Cesa\IdCard\Enums\BusinessEntity;
use Cesa\IdCard\Enums\Position;
use Cesa\IdCard\Filament\Resources\IdCardRequestResource\Pages\CreateIdCardRequest;
use Cesa\IdCard\Filament\Resources\IdCardRequestResource\Pages\EditIdCardRequest;
use Cesa\IdCard\Filament\Resources\IdCardRequestResource\Pages\ListIdCardRequests;
use Cesa\IdCard\Filament\Resources\IdCardRequestResource\Pages\ViewIdCardRequest;
use Cesa\IdCard\Models\IdCardRequest;
use Cesa\IdCard\Support\IdCardRequestForm;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Webkul\PluginManager\Package;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Traits\HasResourcePermissionQuery;

class IdCardRequestResource extends Resource
{
    use HasResourcePermissionQuery {
        getEloquentQuery as protected getPermissionScopedEloquentQuery;
    }

    protected static ?string $model = IdCardRequest::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function shouldRegisterNavigation(): bool
    {
        return Package::isPluginInstalled('id-card');
    }

    public static function getNavigationLabel(): string
    {
        return __('id-card::id-card.navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.id-card');
    }

    public static function getModelLabel(): string
    {
        return __('id-card::id-card.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('id-card::id-card.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = static::getPermissionScopedEloquentQuery();
        $user = filament()->auth()->user();

        if ($user?->resource_permission === PermissionType::GROUP && empty(bouncer()->getAuthorizedUserIds())) {
            $query->where('creator_id', $user->getKey());
        }

        return match ($user?->resource_permission) {
            PermissionType::GROUP      => $query->with('creator.teams'),
            PermissionType::INDIVIDUAL => $query->with('creator'),
            default                    => $query,
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('id-card::id-card.title'))
                    ->schema(IdCardRequestForm::components())
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('id-card::id-card.title'))
                    ->schema([
                        TextEntry::make('full_name')
                            ->label(__('id-card::id-card.fields.full_name')),
                        TextEntry::make('phone')
                            ->label(__('id-card::id-card.fields.phone'))
                            ->copyable(),
                        TextEntry::make('business_entity')
                            ->label(__('id-card::id-card.fields.business_entity'))
                            ->badge(),
                        TextEntry::make('position')
                            ->label(__('id-card::id-card.fields.position'))
                            ->badge(),
                        TextEntry::make('shipping_address')
                            ->label(__('id-card::id-card.fields.shipping_address'))
                            ->columnSpanFull(),
                        ImageEntry::make('photo')
                            ->label(__('id-card::id-card.fields.photo'))
                            ->state(fn (IdCardRequest $record): string => route('id-card.photos.show', ['idCardRequest' => $record]))
                            ->url(fn (IdCardRequest $record): string => route('id-card.photos.show', ['idCardRequest' => $record]))
                            ->openUrlInNewTab()
                            ->imageHeight(240)
                            ->columnSpanFull(),
                        TextEntry::make('created_at')
                            ->label(__('id-card::id-card.fields.created_at'))
                            ->dateTime('d M Y H:i'),
                        TextEntry::make('updated_at')
                            ->label(__('id-card::id-card.fields.updated_at'))
                            ->dateTime('d M Y H:i'),
                        TextEntry::make('deleted_at')
                            ->label(__('id-card::id-card.fields.deleted_at'))
                            ->dateTime('d M Y H:i')
                            ->visible(fn (IdCardRequest $record): bool => $record->trashed()),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('full_name')
                    ->label(__('id-card::id-card.fields.full_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('business_entity')
                    ->label(__('id-card::id-card.fields.business_entity'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('position')
                    ->label(__('id-card::id-card.fields.position'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label(__('id-card::id-card.fields.phone'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('shipping_address')
                    ->label(__('id-card::id-card.fields.shipping_address'))
                    ->searchable()
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('id-card::id-card.fields.created_at'))
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                TextColumn::make('deleted_at')
                    ->label(__('id-card::id-card.fields.deleted_at'))
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('business_entity')
                    ->label(__('id-card::id-card.fields.business_entity'))
                    ->options(BusinessEntity::class),
                SelectFilter::make('position')
                    ->label(__('id-card::id-card.fields.position'))
                    ->options(Position::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords(),
                    RestoreBulkAction::make()
                        ->authorizeIndividualRecords(),
                    ForceDeleteBulkAction::make()
                        ->authorizeIndividualRecords(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListIdCardRequests::route('/'),
            'create' => CreateIdCardRequest::route('/create'),
            'view'   => ViewIdCardRequest::route('/{record}'),
            'edit'   => EditIdCardRequest::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
