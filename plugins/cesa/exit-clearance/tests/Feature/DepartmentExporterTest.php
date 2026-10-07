<?php

use Cesa\ExitClearance\Filament\Clusters\Configurations\Resources\DepartmentResource\Pages\ListDepartments;
use Cesa\ExitClearance\Filament\Exports\DepartmentExporter;
use Cesa\ExitClearance\Models\Approver;
use Cesa\ExitClearance\Models\Department;
use Filament\Actions\Exports\Models\Export;

test('department exporter uses the cesa-exports queue', function (): void {
    $exporter = new DepartmentExporter(new Export, [], []);

    expect($exporter->getJobQueue())->toBe('cesa-exports');
});

test('department exporter defines expected columns', function (): void {
    expect(collect(DepartmentExporter::getColumns())->map->getName()->all())->toBe([
        'code',
        'name',
        'description',
        'approvers_count',
        'approvers',
        'deleted_at',
    ]);
});

test('department exporter eager loads approvers', function (): void {
    $eagerLoads = DepartmentExporter::modifyQuery(Department::query())->getEagerLoads();

    expect($eagerLoads)->toHaveKey('approvers');
});

test('department exporter inlines approvers for cross-checking', function (): void {
    app()->setLocale('id');

    $department = Department::factory()->create([
        'code' => 'IT',
        'name' => 'Information Technology',
    ]);

    $active = Approver::query()->create([
        'name'  => 'Arik Cahya',
        'email' => 'arik@example.com',
        'phone' => '081111111111',
        'title' => 'IT Manager',
    ]);

    $deleted = Approver::query()->create([
        'name'  => 'Old Approver',
        'email' => 'old@example.com',
        'phone' => '082222222222',
        'title' => 'Ex Manager',
    ]);
    $deleted->delete();

    $department->approvers()->sync([$active->getKey(), $deleted->getKey()]);

    $record = DepartmentExporter::modifyQuery(Department::query())->findOrFail($department->getKey());

    expect(DepartmentExporter::formatApprovers($record))
        ->toContain('Arik Cahya - arik@example.com - 081111111111 - IT Manager')
        ->toContain('Old Approver (Dihapus) - old@example.com - 082222222222 - Ex Manager')
        ->toContain(' | ');
});

test('department list exposes active and archive tabs plus export of both', function (): void {
    $contents = file_get_contents(base_path(
        'plugins/cesa/exit-clearance/src/Filament/Clusters/Configurations/Resources/DepartmentResource/Pages/ListDepartments.php'
    ));

    expect($contents)->toBeString()
        ->toContain("'active'")
        ->toContain("'archived'")
        ->toContain('withTrashed()')
        ->toContain('ExportAction::make()');
});

test('department exporter completed notification body is localized', function (): void {
    $export = new Export;
    $export->successful_rows = 3;
    $export->total_rows = 4;

    app()->setLocale('en');
    expect(DepartmentExporter::getCompletedNotificationBody($export))
        ->toBe('The department export finished with 3 exported row(s) and 1 failed row(s).');

    app()->setLocale('id');
    expect(DepartmentExporter::getCompletedNotificationBody($export))
        ->toBe('Ekspor divisi selesai dengan 3 baris berhasil diekspor dan 1 baris gagal diekspor.');
});

test('department list exposes the export action', function (): void {
    $contents = file_get_contents(base_path(
        'plugins/cesa/exit-clearance/src/Filament/Clusters/Configurations/Resources/DepartmentResource/Pages/ListDepartments.php'
    ));

    expect($contents)->toBeString()
        ->toContain(DepartmentExporter::class)
        ->toContain('ExportAction::make()')
        ->toContain('->exporter(DepartmentExporter::class)')
        ->and(class_exists(ListDepartments::class))->toBeTrue();
});
