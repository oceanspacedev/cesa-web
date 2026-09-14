<?php

namespace Cesa\ExitClearance\Filament\Exports;

use Carbon\CarbonInterface;
use Cesa\ExitClearance\Enums\ApprovalStatus;
use Cesa\ExitClearance\Models\Approver;
use Cesa\ExitClearance\Models\Request;
use Cesa\ExitClearance\Services\ExitClearanceRequestService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class RequestExporter extends Exporter
{
    protected static ?string $model = Request::class;

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with([
            'company',
            'department',
            'approvers',
        ]);
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('form_uid')
                ->label(__('exit-clearance::filament/resources/request.table.uid')),
            ExportColumn::make('form_status')
                ->label(__('exit-clearance::filament/resources/request.table.status'))
                ->formatStateUsing(fn (mixed $state): string => static::formatFormStatus($state)),
            ExportColumn::make('name')
                ->label(__('exit-clearance::filament/resources/request.table.employee_name')),
            ExportColumn::make('email')
                ->label(__('exit-clearance::filament/resources/request.table.email')),
            ExportColumn::make('phone')
                ->label(__('exit-clearance::filament/resources/request.fields.phone')),
            ExportColumn::make('position')
                ->label(__('exit-clearance::filament/resources/request.table.position')),
            ExportColumn::make('placement')
                ->label(__('exit-clearance::filament/resources/request.table.placement')),
            ExportColumn::make('company.name')
                ->label(__('exit-clearance::filament/resources/request.table.company'))
                ->state(fn (Request $record): string => $record->company?->name ?? '—'),
            ExportColumn::make('department.name')
                ->label(__('exit-clearance::filament/resources/request.table.department'))
                ->state(fn (Request $record): string => static::formatDepartmentName($record)),
            ExportColumn::make('join_date')
                ->label(__('exit-clearance::filament/resources/request.table.join_date'))
                ->formatStateUsing(fn (mixed $state): string => static::formatDate($state)),
            ExportColumn::make('request_date')
                ->label(__('exit-clearance::filament/resources/request.table.request_date'))
                ->formatStateUsing(fn (mixed $state): string => static::formatDate($state)),
            ExportColumn::make('departure_date')
                ->label(__('exit-clearance::filament/resources/request.table.departure_date'))
                ->formatStateUsing(fn (mixed $state): string => static::formatDate($state)),
            ExportColumn::make('reason')
                ->label(__('exit-clearance::filament/resources/request.exit_interview.q1')),
            ExportColumn::make('workload_feedback')
                ->label(__('exit-clearance::filament/resources/request.exit_interview.q2')),
            ExportColumn::make('career_growth_feedback')
                ->label(__('exit-clearance::filament/resources/request.exit_interview.q3')),
            ExportColumn::make('facility_welfare_feedback')
                ->label(__('exit-clearance::filament/resources/request.exit_interview.q4')),
            ExportColumn::make('work_relationship_feedback')
                ->label(__('exit-clearance::filament/resources/request.exit_interview.q5')),
            ExportColumn::make('compensation_feedback')
                ->label(__('exit-clearance::filament/resources/request.exit_interview.q6')),
            ExportColumn::make('division_feedback')
                ->label(__('exit-clearance::filament/resources/request.exit_interview.q7')),
            ExportColumn::make('company_feedback')
                ->label(__('exit-clearance::filament/resources/request.exit_interview.q8')),
            ExportColumn::make('clearance_kartu_halo')
                ->label(__('exit-clearance::filament/resources/request.clearance.item_1')),
            ExportColumn::make('clearance_employee_debt')
                ->label(__('exit-clearance::filament/resources/request.clearance.item_2')),
            ExportColumn::make('clearance_uniform_return')
                ->label(__('exit-clearance::filament/resources/request.clearance.item_3')),
            ExportColumn::make('clearance_vehicle_return')
                ->label(__('exit-clearance::filament/resources/request.clearance.item_4')),
            ExportColumn::make('clearance_inventory_return')
                ->label(__('exit-clearance::filament/resources/request.clearance.item_5')),
            ExportColumn::make('clearance_account_deactivation')
                ->label(__('exit-clearance::filament/resources/request.clearance.item_6')),
            ExportColumn::make('clearance_receivable_data')
                ->label(__('exit-clearance::filament/resources/request.clearance.item_7')),
            ExportColumn::make('clearance_promotor_internal')
                ->label(__('exit-clearance::filament/resources/request.clearance.item_8')),
            ExportColumn::make('clearance_nota_pending')
                ->label(__('exit-clearance::filament/resources/request.clearance.item_9')),
            ExportColumn::make('clearance_stock_opname')
                ->label(__('exit-clearance::filament/resources/request.clearance.item_10')),
            ExportColumn::make('resignation_letter_url')
                ->label(__('exit-clearance::filament/resources/request.table.resignation_letter'))
                ->state(fn (Request $record): string => static::formatResignationLetterUrl($record)),
            ExportColumn::make('approvers')
                ->label(__('exit-clearance::filament/resources/request.table.approvers'))
                ->state(fn (Request $record): string => static::formatApprovers($record)),
            ExportColumn::make('pending_approvers')
                ->label(__('exit-clearance::filament/resources/request.table.pending_approvers'))
                ->state(fn (Request $record): string => static::formatPendingApprovers($record)),
            ExportColumn::make('progress_url')
                ->label(__('exit-clearance::filament/resources/request.infolist_fields.progress_url'))
                ->state(fn (Request $record): string => $record->getPublicProgressUrl()),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return __('exit-clearance::filament/resources/request.exports.notifications.completed_body', [
            'success' => number_format($export->successful_rows),
            'failed'  => number_format($export->getFailedRowsCount()),
        ]);
    }

    public static function formatFormStatus(mixed $state): string
    {
        return app(ExitClearanceRequestService::class)->formatFormStatus(
            is_string($state) ? $state : null
        );
    }

    public static function formatDate(mixed $state): string
    {
        if ($state instanceof CarbonInterface) {
            return $state->format('Y-m-d');
        }

        if ($state instanceof \DateTimeInterface) {
            return $state->format('Y-m-d');
        }

        if ($state === null || $state === '') {
            return '';
        }

        return (string) $state;
    }

    public static function formatDepartmentName(Request $record): string
    {
        $department = $record->department;

        if (! $department) {
            return '';
        }

        if ($department->trashed()) {
            return $department->name.' (Dihapus)';
        }

        return (string) $department->name;
    }

    public static function formatApprovers(Request $record): string
    {
        $requestService = app(ExitClearanceRequestService::class);

        return $record->approvers
            ->map(function (Approver $approver) use ($requestService): string {
                $status = $requestService->normalizeApprovalStatus($approver->pivot?->status);
                $statusLabel = ApprovalStatus::tryFrom($status)?->getLabel() ?? $status;

                return static::formatApproverName($approver).' - '.$statusLabel;
            })
            ->filter()
            ->implode(', ');
    }

    public static function formatPendingApprovers(Request $record): string
    {
        $requestService = app(ExitClearanceRequestService::class);

        return $record->approvers
            ->filter(function (Approver $approver) use ($requestService): bool {
                return $requestService->normalizeApprovalStatus($approver->pivot?->status) === ExitClearanceRequestService::APPROVAL_PENDING;
            })
            ->map(fn (Approver $approver): string => static::formatApproverName($approver))
            ->filter()
            ->implode(', ');
    }

    public static function formatApproverName(Approver $approver): string
    {
        $name = $approver->trashed() ? $approver->name.' (Dihapus)' : $approver->name;
        $title = is_string($approver->title) ? trim($approver->title) : '';

        if ($title !== '') {
            return $name.' ('.$title.')';
        }

        return (string) $name;
    }

    public static function formatResignationLetterUrl(Request $record): string
    {
        return app(ExitClearanceRequestService::class)->resolveResignationLetterUrl($record) ?? '';
    }
}
