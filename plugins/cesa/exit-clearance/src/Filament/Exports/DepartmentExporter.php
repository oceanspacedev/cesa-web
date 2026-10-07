<?php

namespace Cesa\ExitClearance\Filament\Exports;

use Cesa\ExitClearance\Models\Approver;
use Cesa\ExitClearance\Models\Department;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class DepartmentExporter extends Exporter
{
    protected static ?string $model = Department::class;

    public function getJobQueue(): ?string
    {
        return 'cesa-exports';
    }

    public static function modifyQuery(Builder $query): Builder
    {
        // Export both active and archived rows, independent of the current tab filter.
        return Department::query()
            ->applyPermissionScope()
            ->withTrashed()
            ->with([
                'approvers' => fn ($approvers) => $approvers->orderBy('name'),
            ]);
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('code')
                ->label(__('exit-clearance::filament/resources/department.fields.code')),
            ExportColumn::make('name')
                ->label(__('exit-clearance::filament/resources/department.fields.name')),
            ExportColumn::make('description')
                ->label(__('exit-clearance::filament/resources/department.fields.description'))
                ->state(fn (Department $record): string => (string) ($record->description ?? '')),
            ExportColumn::make('approvers_count')
                ->label(__('exit-clearance::filament/resources/department.fields.approvers_count'))
                ->state(fn (Department $record): int => $record->approvers->count()),
            ExportColumn::make('approvers')
                ->label(__('exit-clearance::filament/resources/department.fields.approvers'))
                ->state(fn (Department $record): string => static::formatApprovers($record)),
            ExportColumn::make('deleted_at')
                ->label(__('exit-clearance::filament/resources/department.fields.archived'))
                ->state(fn (Department $record): string => $record->trashed()
                    ? __('exit-clearance::filament/resources/department.fields.archived_yes')
                    : __('exit-clearance::filament/resources/department.fields.archived_no')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return __('exit-clearance::filament/resources/department.exports.notifications.completed_body', [
            'success' => number_format($export->successful_rows),
            'failed'  => number_format($export->getFailedRowsCount()),
        ]);
    }

    public static function formatApprovers(Department $record): string
    {
        return $record->approvers
            ->map(fn (Approver $approver): string => static::formatApprover($approver))
            ->filter()
            ->implode(' | ');
    }

    public static function formatApprover(Approver $approver): string
    {
        $name = $approver->trashed()
            ? $approver->name.' ('.__('exit-clearance::filament/resources/department.fields.deleted_suffix').')'
            : (string) $approver->name;

        $parts = [$name];

        if (filled($approver->email)) {
            $parts[] = (string) $approver->email;
        }

        if (filled($approver->phone)) {
            $parts[] = (string) $approver->phone;
        }

        if (filled($approver->title)) {
            $parts[] = (string) $approver->title;
        }

        return implode(' - ', $parts);
    }
}
