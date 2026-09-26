<?php

use Cesa\Waste\Database\Seeders\DatabaseSeeder;
use Cesa\Waste\Enums\WasteAlternateUnitCandidateStatus;
use Cesa\Waste\Filament\Resources\WasteItemResource\Pages\ManageWasteItems;
use Cesa\Waste\Filament\Resources\WasteItemUnitCandidateResource;
use Cesa\Waste\Filament\Resources\WasteItemUnitCandidateResource\Pages\ManageWasteItemUnitCandidates;
use Cesa\Waste\Livewire\PublicWasteReportForm;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteItemUnitCandidate;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteUnit;
use Cesa\Waste\Services\WasteAlternateUnitCandidateService;
use Cesa\Waste\WastePlugin;
use Database\Factories\UserFactory;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Panel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function (): void {
    if (! Route::has('filament.admin.waste.configurations')) {
        Route::get('/_test/waste/configurations', static fn (): string => '')
            ->name('filament.admin.waste.configurations');
    }
});

it('stages all eleven September pairs without enabling any public unit', function (): void {
    [$brand, $items] = wasteCandidateMaster();
    $workbookPath = wasteCandidateWorkbook([
        8   => ['P004-031', 'GR'],
        15  => ['P004-036', 'GR'],
        22  => ['B001-167', 'GR'],
        24  => ['P004-022', 'ML'],
        25  => ['P004-023', 'ML'],
        26  => ['B001-166', 'GR'],
        27  => ['B001-165', 'GR'],
        41  => ['B001-162', 'GR'],
        51  => ['B001-165', 'GR'],
        54  => ['P004-025', 'ML'],
        55  => ['P004-022', 'ML'],
        70  => ['B001-166', 'GR'],
        73  => ['P004-025', 'ML:'],
        81  => ['B001-162', 'GR'],
        90  => ['P004-043', 'GR'],
        92  => ['B001-162', 'GR'],
        120 => ['S001-002', 'PRS'],
        121 => ['P004-023', 'ML'],
        122 => ['P004-022', 'ML'],
        123 => ['P004-020', 'GR'],
        124 => ['P004-022', 'ML'],
    ]);

    try {
        $this->artisan('waste:stage-alternate-units', ['file' => $workbookPath, '--sheet' => 'SEPTEMBER 26'])
            ->expectsOutputToContain('11 pasangan kandidat dari 20 baris')
            ->assertSuccessful();

        expect(WasteItemUnitCandidate::query()->count())->toBe(11)
            ->and(WasteItemUnitCandidate::query()->where('status', 'pending')->count())->toBe(11)
            ->and($items['P004-025']->alternateUnits()->count())->toBe(0);

        $candidate = WasteItemUnitCandidate::query()->where('item_id', $items['P004-025']->id)->firstOrFail();
        expect($candidate->unit->code)->toBe('ML')
            ->and($candidate->source_row_count)->toBe(2)
            ->and($candidate->example_row)->toBe(54)
            ->and($candidate->source_sheet)->toBe('SEPTEMBER 26')
            ->and($candidate->source_unit_labels)->toBe(['ML', 'ML:'])
            ->and($candidate->status)->toBe(WasteAlternateUnitCandidateStatus::Pending);

        $reviewer = UserFactory::new()->createQuietly();
        $brand->users()->attach($reviewer);
        app(WasteAlternateUnitCandidateService::class)->approve($candidate, $reviewer);

        $this->artisan('waste:stage-alternate-units', ['file' => $workbookPath, '--sheet' => 'SEPTEMBER 26'])
            ->expectsOutputToContain('0 baru, 11 diperbarui')
            ->assertSuccessful();

        expect(WasteItemUnitCandidate::query()->count())->toBe(11)
            ->and($candidate->fresh()->status)->toBe(WasteAlternateUnitCandidateStatus::Approved)
            ->and($items['P004-025']->alternateUnits()->pluck('code')->all())->toBe(['ML']);
    } finally {
        @unlink($workbookPath);
    }
});

it('matches the real Jchicken September workbook when the local reference is available', function (): void {
    $path = '/Users/apriansyahrs/Downloads/waste/Waste Form Adjustment Jchicken Ciledug .xlsx';
    if (! is_file($path)) {
        $this->markTestSkipped('Workbook contoh Jchicken tidak tersedia di mesin ini.');
    }

    $this->seed(DatabaseSeeder::class);
    $result = app(WasteAlternateUnitCandidateService::class)->stageJchickenWorkbook($path);

    expect($result)->toMatchArray(['found' => 11, 'created' => 11, 'source_rows' => 20])
        ->and(WasteItemUnitCandidate::query()->where('status', 'pending')->count())->toBe(11)
        ->and(WasteItemUnitCandidate::query()->whereHas('item', fn ($query) => $query->where('code', 'P004-031'))->exists())->toBeFalse()
        ->and(WasteItem::query()->whereHas('alternateUnits')->count())->toBe(0);
});

it('lets an assigned admin decide each candidate and reflects approval in the public form', function (): void {
    [$brand, $items, $units, $outlet] = wasteCandidateMaster();
    $user = UserFactory::new()->createQuietly();
    $brand->users()->attach($user);
    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $pluginPanel = Panel::make()->id('admin')->plugin(WastePlugin::make());
    expect($pluginPanel->getResources())->toContain(WasteItemUnitCandidateResource::class)
        ->and(array_keys(WasteItemUnitCandidateResource::getPages()))->toBe(['index']);

    $approved = wasteCandidate($items['P004-025'], $units['ML']);
    $rejected = wasteCandidate($items['B001-162'], $units['GR']);

    $page = Livewire::test(ManageWasteItemUnitCandidates::class)
        ->assertCanSeeTableRecords([$approved, $rejected]);

    expect(Livewire::test(PublicWasteReportForm::class, ['brand' => 'jchicken', 'outlet' => $outlet->slug])
        ->get('itemUnitOptions')[$items['P004-025']->id])->toBe(['PRS']);

    $page->callAction(TestAction::make('approve')->table($approved))->assertNotified();
    $page->callAction(TestAction::make('reject')->table($rejected), ['review_note' => 'Satuan GR pada kemasan PCS perlu diverifikasi ulang.'])
        ->assertNotified();

    expect($approved->fresh()->status)->toBe(WasteAlternateUnitCandidateStatus::Approved)
        ->and($approved->fresh()->reviewed_by)->toBe($user->id)
        ->and($rejected->fresh()->status)->toBe(WasteAlternateUnitCandidateStatus::Rejected)
        ->and($rejected->fresh()->review_note)->toContain('perlu diverifikasi')
        ->and($items['P004-025']->alternateUnits()->pluck('code')->all())->toBe(['ML'])
        ->and($items['B001-162']->alternateUnits()->count())->toBe(0)
        ->and(Livewire::test(PublicWasteReportForm::class, ['brand' => 'jchicken', 'outlet' => $outlet->slug])
            ->get('itemUnitOptions')[$items['P004-025']->id])->toBe(['PRS', 'ML']);

    $page->callAction(TestAction::make('reopen')->table($approved))->assertNotified();

    expect($approved->fresh()->status)->toBe(WasteAlternateUnitCandidateStatus::Pending)
        ->and($approved->fresh()->reviewed_by)->toBeNull()
        ->and($items['P004-025']->alternateUnits()->count())->toBe(0);
});

it('keeps spreadsheet provenance in the database without showing it in master tables', function (): void {
    [$brand, $items, $units] = wasteCandidateMaster();
    $user = UserFactory::new()->createQuietly();
    $brand->users()->attach($user);
    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $candidate = wasteCandidate($items['P004-025'], $units['ML']);

    Livewire::test(ManageWasteItemUnitCandidates::class)
        ->assertCanSeeTableRecords([$candidate])
        ->assertTableColumnExists('item.code')
        ->assertTableColumnExists('unit.code')
        ->assertTableColumnExists('source_row_count')
        ->assertTableColumnDoesNotExist('source_reference')
        ->assertTableColumnDoesNotExist('source_unit_labels')
        ->assertTableColumnDoesNotExist('reviewer.name')
        ->assertTableColumnDoesNotExist('reviewed_at')
        ->assertTableColumnDoesNotExist('review_note');

    Livewire::test(ManageWasteItems::class)
        ->assertCanSeeTableRecords([$items['P004-025']])
        ->assertTableColumnDoesNotExist('source_status');

    expect($candidate->fresh()->source_file)->toBe('Waste Form Adjustment Jchicken Ciledug .xlsx')
        ->and($candidate->fresh()->source_sheet)->toBe('SEPTEMBER 26')
        ->and($candidate->fresh()->example_row)->toBe(54)
        ->and($candidate->fresh()->source_unit_labels)->toBe(['ML']);
});

it('blocks Master Barang from activating pending or rejected candidates while keeping approved and ordinary units editable', function (): void {
    [$brand, $items, $units] = wasteCandidateMaster();
    $user = UserFactory::new()->createQuietly();
    $brand->users()->attach($user);
    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $candidate = wasteCandidate($items['P004-025'], $units['ML']);
    $editCandidateItem = TestAction::make(EditAction::class)->table($items['P004-025']);

    Livewire::test(ManageWasteItems::class)
        ->callAction($editCandidateItem, data: ['alternateUnits' => [$units['ML']->id]])
        ->assertHasFormErrors(['alternateUnits.0']);

    expect($items['P004-025']->alternateUnits()->count())->toBe(0)
        ->and($candidate->fresh()->status)->toBe(WasteAlternateUnitCandidateStatus::Pending);

    $service = app(WasteAlternateUnitCandidateService::class);
    $service->reject($candidate, $user, 'Tidak sesuai ukuran barang.');

    Livewire::test(ManageWasteItems::class)
        ->callAction($editCandidateItem, data: ['alternateUnits' => [$units['ML']->id]])
        ->assertHasFormErrors(['alternateUnits.0']);

    expect($items['P004-025']->alternateUnits()->count())->toBe(0)
        ->and($candidate->fresh()->status)->toBe(WasteAlternateUnitCandidateStatus::Rejected);

    $service->reopen($candidate, $user);
    $service->approve($candidate, $user);

    Livewire::test(ManageWasteItems::class)
        ->callAction($editCandidateItem, data: ['alternateUnits' => [$units['ML']->id]])
        ->assertHasNoFormErrors();

    expect($candidate->fresh()->status)->toBe(WasteAlternateUnitCandidateStatus::Approved)
        ->and($items['P004-025']->alternateUnits()->pluck('code')->all())->toBe(['ML']);

    Livewire::test(ManageWasteItems::class)
        ->callAction(TestAction::make(EditAction::class)->table($items['P004-036']), data: [
            'alternateUnits' => [$units['ML']->id],
        ])
        ->assertHasNoFormErrors();

    expect($items['P004-036']->alternateUnits()->pluck('code')->all())->toBe(['ML']);
});

it('scopes review to assigned brands and rejects inactive units', function (): void {
    [$brand, $items, $units] = wasteCandidateMaster();
    $candidate = wasteCandidate($items['P004-025'], $units['ML']);
    $otherBrand = WasteBrand::query()->create(['code' => 'LUUCA', 'name' => 'Luuca', 'is_active' => true]);
    $otherManager = UserFactory::new()->createQuietly();
    $otherBrand->users()->attach($otherManager);

    $this->actingAs($otherManager);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    Livewire::test(ManageWasteItemUnitCandidates::class)->assertCanNotSeeTableRecords([$candidate]);

    expect(fn () => app(WasteAlternateUnitCandidateService::class)->approve($candidate, $otherManager))
        ->toThrow(AuthorizationException::class);

    $manager = UserFactory::new()->createQuietly();
    $brand->users()->attach($manager);
    $units['ML']->update(['is_active' => false]);

    expect(fn () => app(WasteAlternateUnitCandidateService::class)->approve($candidate, $manager))
        ->toThrow(ValidationException::class)
        ->and($candidate->fresh()->status)->toBe(WasteAlternateUnitCandidateStatus::Pending)
        ->and($items['P004-025']->alternateUnits()->count())->toBe(0);
});

it('aborts staging when a source line has no matching item master', function (): void {
    wasteCandidateMaster();
    $workbookPath = wasteCandidateWorkbook([
        7 => ['P004-025', 'ML'],
        8 => ['UNKNOWN', 'GR'],
    ]);

    try {
        $this->artisan('waste:stage-alternate-units', ['file' => $workbookPath])->assertFailed();
        expect(WasteItemUnitCandidate::query()->count())->toBe(0);
    } finally {
        @unlink($workbookPath);
    }
});

/**
 * @return array{WasteBrand, array<string, WasteItem>, array<string, WasteUnit>, WasteOutlet}
 */
function wasteCandidateMaster(): array
{
    $brand = WasteBrand::query()->create(['code' => 'JCHICKEN', 'name' => 'Jchicken', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG',
        'slug'     => 'jchicken-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $units = [];
    foreach (['GR', 'ML', 'PRS', 'PCS', 'LBR'] as $code) {
        $units[$code] = WasteUnit::query()->create(['code' => $code, 'name' => $code, 'is_active' => true]);
    }

    $primaryUnits = [
        'B001-162' => 'PCS', 'B001-165' => 'LBR', 'B001-166' => 'PCS',
        'B001-167' => 'PCS', 'P004-020' => 'PCS', 'P004-022' => 'GR',
        'P004-023' => 'GR', 'P004-025' => 'PRS', 'P004-031' => 'GR',
        'P004-036' => 'PRS', 'P004-043' => 'PRS', 'S001-002' => 'PCS',
    ];
    $items = [];
    foreach ($primaryUnits as $code => $unit) {
        $items[$code] = WasteItem::query()->create([
            'brand_id' => $brand->id, 'code' => $code, 'name' => $code,
            'unit'     => $unit, 'is_active' => true,
        ]);
    }

    return [$brand, $items, $units, $outlet];
}

/**
 * @param  array<int, array{string, string}>  $rows
 */
function wasteCandidateWorkbook(array $rows): string
{
    $workbook = new Spreadsheet;
    $sheet = $workbook->getActiveSheet();
    $sheet->setTitle('SEPTEMBER 26');
    $sheet->setCellValue('C6', 'KODE CSA');
    $sheet->setCellValue('F6', 'SATUAN CSA');
    foreach ($rows as $row => [$code, $unit]) {
        $sheet->setCellValue("C{$row}", $code);
        $sheet->setCellValue("E{$row}", 1);
        $sheet->setCellValue("F{$row}", $unit);
    }

    $path = sys_get_temp_dir().'/waste-candidates-'.bin2hex(random_bytes(8)).'.xlsx';
    (new Xlsx($workbook))->save($path);
    $workbook->disconnectWorksheets();

    return $path;
}

function wasteCandidate(WasteItem $item, WasteUnit $unit): WasteItemUnitCandidate
{
    return WasteItemUnitCandidate::query()->create([
        'item_id'            => $item->id,
        'unit_id'            => $unit->id,
        'source_file'        => 'Waste Form Adjustment Jchicken Ciledug .xlsx',
        'source_sheet'       => 'SEPTEMBER 26',
        'example_row'        => 54,
        'source_row_count'   => 2,
        'source_unit_labels' => [$unit->code],
        'status'             => WasteAlternateUnitCandidateStatus::Pending,
    ]);
}
