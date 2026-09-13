<?php

use Cesa\ExitClearance\Filament\Exports\RequestExporter;
use Cesa\ExitClearance\Filament\Resources\RequestResource\Pages\ListRequests;
use Cesa\ExitClearance\Models\Approver;
use Cesa\ExitClearance\Models\Department;
use Cesa\ExitClearance\Models\Request;
use Cesa\ExitClearance\Services\ExitClearanceRequestService;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Carbon;

test('exit clearance exporter defines expected columns', function () {
    expect(collect(RequestExporter::getColumns())->map->getName()->all())->toBe([
        'form_uid',
        'form_status',
        'name',
        'email',
        'phone',
        'position',
        'placement',
        'company.name',
        'department.name',
        'join_date',
        'request_date',
        'departure_date',
        'reason',
        'workload_feedback',
        'career_growth_feedback',
        'facility_welfare_feedback',
        'work_relationship_feedback',
        'compensation_feedback',
        'division_feedback',
        'company_feedback',
        'clearance_kartu_halo',
        'clearance_employee_debt',
        'clearance_uniform_return',
        'clearance_vehicle_return',
        'clearance_inventory_return',
        'clearance_account_deactivation',
        'clearance_receivable_data',
        'clearance_promotor_internal',
        'clearance_nota_pending',
        'clearance_stock_opname',
        'resignation_letter_url',
        'approvers',
        'pending_approvers',
        'progress_url',
    ]);
});

test('exit clearance exporter eager loads company department and approvers', function () {
    $eagerLoads = RequestExporter::modifyQuery(Request::query())->getEagerLoads();

    expect($eagerLoads)->toHaveKey('company')->toHaveKey('department')->toHaveKey('approvers');
});

test('exit clearance company labels are localized', function (string $locale, string $label) {
    app()->setLocale($locale);

    $companyColumn = collect(RequestExporter::getColumns())
        ->first(fn ($column): bool => $column->getName() === 'company.name');

    expect($companyColumn->getLabel())->toBe($label)
        ->and(__('exit-clearance::filament/resources/request.fields.company'))->toBe($label)
        ->and(__('exit-clearance::filament/resources/request.infolist_fields.company'))->toBe($label);
})->with([
    'English'    => ['en', 'Company'],
    'Indonesian' => ['id', 'Badan Usaha'],
]);

test('exit clearance exporter normalizes form status and dates', function () {
    $formStatusColumn = collect(RequestExporter::getColumns())
        ->first(fn ($column): bool => $column->getName() === 'form_status');

    expect($formStatusColumn)->not->toBeNull()
        ->and($formStatusColumn->formatState('approved'))->toBe('Approved')
        ->and($formStatusColumn->formatState('Pending'))->toBe('Pending')
        ->and($formStatusColumn->formatState('rejected'))->toBe('Rejected')
        ->and(RequestExporter::formatDate(Carbon::parse('2024-01-15')))->toBe('2024-01-15');
});

test('exit clearance exporter formats department approvers and resignation letter', function () {
    app()->setLocale('id');

    $department = Department::factory()->create([
        'name' => 'IT Export Test',
    ]);

    $request = Request::factory()->create([
        'department_id'          => $department->id,
        'form_response_id'       => 'exit-export-token-123',
        'resignation_letter_url' => 'resignation-letters/example.pdf',
    ]);

    $approver = Approver::query()->create([
        'name'  => 'Arik Cahya Hidayat',
        'email' => 'arik-export-test@example.com',
        'title' => 'IT Manager',
    ]);

    $request->approvers()->sync([
        $approver->getKey() => ['status' => ExitClearanceRequestService::APPROVAL_PENDING],
    ]);

    $request->load(['department', 'approvers']);

    expect(RequestExporter::formatDepartmentName($request))->toBe('IT Export Test')
        ->and(RequestExporter::formatApprovers($request))->toBe('Arik Cahya Hidayat (IT Manager) - Menunggu')
        ->and(RequestExporter::formatPendingApprovers($request))->toBe('Arik Cahya Hidayat (IT Manager)');

    $resignationUrl = RequestExporter::formatResignationLetterUrl($request);

    expect($resignationUrl)->not->toBe('')
        ->and($resignationUrl)->toContain('exit-export-token-123')
        ->and($resignationUrl)->toContain('resignation-letter');

    $department->delete();
    $request->load('department');

    expect(RequestExporter::formatDepartmentName($request))->toBe('IT Export Test (Dihapus)');
});

test('exit clearance exporter lists only pending approvers', function () {
    app()->setLocale('id');

    $department = Department::factory()->create();

    $request = Request::factory()->create([
        'department_id' => $department->id,
    ]);

    $pendingApprover = Approver::query()->create([
        'name'  => 'Sinta Pending',
        'email' => 'sinta-pending-export@example.com',
        'title' => 'Finance Manager',
    ]);

    $approvedApprover = Approver::query()->create([
        'name'  => 'Budi Approved',
        'email' => 'budi-approved-export@example.com',
        'title' => 'IT Manager',
    ]);

    $waitingApprover = Approver::query()->create([
        'name'  => 'Rina Waiting',
        'email' => 'rina-waiting-export@example.com',
        'title' => 'HR Manager',
    ]);

    $rejectedApprover = Approver::query()->create([
        'name'  => 'Andi Rejected',
        'email' => 'andi-rejected-export@example.com',
        'title' => 'GA Manager',
    ]);

    $request->approvers()->sync([
        $pendingApprover->getKey()  => ['status' => ExitClearanceRequestService::APPROVAL_PENDING],
        $approvedApprover->getKey() => ['status' => ExitClearanceRequestService::APPROVAL_APPROVED],
        $waitingApprover->getKey()  => ['status' => ExitClearanceRequestService::APPROVAL_WAITING],
        $rejectedApprover->getKey() => ['status' => ExitClearanceRequestService::APPROVAL_REJECTED],
    ]);

    $request->load('approvers');

    expect(RequestExporter::formatPendingApprovers($request))->toBe('Sinta Pending (Finance Manager)');

    $pendingApprover->delete();
    $request->load('approvers');

    expect(RequestExporter::formatPendingApprovers($request))->toBe('Sinta Pending (Dihapus) (Finance Manager)');

    $request->approvers()->sync([
        $approvedApprover->getKey() => ['status' => ExitClearanceRequestService::APPROVAL_APPROVED],
        $waitingApprover->getKey()  => ['status' => ExitClearanceRequestService::APPROVAL_WAITING],
    ]);

    $request->load('approvers');

    expect(RequestExporter::formatPendingApprovers($request))->toBe('');
});

test('exit clearance exporter completed notification body is localized', function () {
    $export = new Export;
    $export->successful_rows = 3;
    $export->total_rows = 4;

    app()->setLocale('en');
    expect(RequestExporter::getCompletedNotificationBody($export))
        ->toBe('The exit clearance request export finished with 3 exported row(s) and 1 failed row(s).');

    app()->setLocale('id');
    expect(RequestExporter::getCompletedNotificationBody($export))
        ->toBe('Ekspor pengajuan exit clearance selesai dengan 3 baris berhasil diekspor dan 1 baris gagal diekspor.');
});

test('exit clearance request list exposes the export action', function () {
    $contents = file_get_contents(base_path('plugins/cesa/exit-clearance/src/Filament/Resources/RequestResource/Pages/ListRequests.php'));

    expect($contents)->toBeString()
        ->toContain(RequestExporter::class)
        ->toContain('ExportAction::make()')
        ->toContain('->exporter(RequestExporter::class)')
        ->and(class_exists(ListRequests::class))->toBeTrue();
});
