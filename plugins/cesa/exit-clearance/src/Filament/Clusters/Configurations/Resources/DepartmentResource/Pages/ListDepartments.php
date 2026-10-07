<?php

namespace Cesa\ExitClearance\Filament\Clusters\Configurations\Resources\DepartmentResource\Pages;

use Cesa\ExitClearance\Filament\Clusters\Configurations\Resources\DepartmentResource;
use Cesa\ExitClearance\Filament\Exports\DepartmentExporter;
use Cesa\ExitClearance\Models\Department;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDepartments extends ManageRecords
{
    protected static string $resource = DepartmentResource::class;

    public function getTabs(): array
    {
        $deletedAt = (new Department)->getQualifiedDeletedAtColumn();

        return [
            'active' => Tab::make(__('exit-clearance::filament/resources/department/pages/list-department.tabs.active'))
                ->badge(DepartmentResource::getEloquentQuery()->whereNull($deletedAt)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull($deletedAt)),
            'archived' => Tab::make(__('exit-clearance::filament/resources/department/pages/list-department.tabs.archived'))
                ->badge(DepartmentResource::getEloquentQuery()->whereNotNull($deletedAt)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull($deletedAt)),
        ];
    }

    public function mount(): void
    {
        if (request()->query('tab') === 'all') {
            $this->activeTab = 'active';
        }

        parent::mount();

        if (! in_array($this->activeTab, ['active', 'archived'], true)) {
            $this->activeTab = 'active';
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->icon('heroicon-o-plus-circle')->slideOver()->modalWidth('md'),
            ExportAction::make()
                ->exporter(DepartmentExporter::class)
                ->label(__('exit-clearance::filament/resources/department.actions.export'))
                ->icon('heroicon-o-arrow-down-tray'),
        ];
    }
}
