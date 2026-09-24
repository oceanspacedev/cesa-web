<?php

use Cesa\Waste\Livewire\PublicWasteReportForm;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Services\WasteReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    config(['waste.submissions.max_attempts' => 100]);
    app()->setLocale('id');
});

it('records Jchicken September items, grouped lines, sections, and the corrected adjustment unit', function (): void {
    [$brand, $outlet, $items, $categories] = excelWasteSetup('JCHICKEN', [
        'B001-036' => ['Daun Mint', 'GR', 'bahan baku'],
        'B001-008' => ['Beras 25KG', 'GR', 'bahan baku'],
        'B001-056' => ['Minyak Makan', 'ML', 'bahan baku'],
        'P004-031' => ['WIP PREP. VANILLA ICE CREAM', 'GR', 'barang jadi'],
    ], ['Waste', 'Spoil'], ['BAR', 'ASSEMBLY']);

    Livewire::test(PublicWasteReportForm::class, ['brand' => 'jchicken', 'outlet' => $outlet->slug])
        ->set('data.event_date', '2026-09-01')
        ->set('data.reporter_name', 'Petugas Ciledug')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep')
        ->set('data.events.0.section', 'BAR')
        ->set('data.events.0.category_id', $categories['Waste']->id)
        ->set('data.events.0.reason', 'layu')
        ->set('data.events.0.lines.0.item_id', $items['B001-036']->id)
        ->set('data.events.0.lines.0.quantity', '58')
        ->set('photos.0.0', UploadedFile::fake()->image('daun-mint.jpg'))
        ->call('addEvent')
        ->set('data.events.1.section', 'ASSEMBLY')
        ->set('data.events.1.category_id', $categories['Spoil']->id)
        ->set('data.events.1.reason', 'nasi kering menguning')
        ->set('data.events.1.lines.0.item_id', $items['B001-008']->id)
        ->set('data.events.1.lines.0.quantity', '304')
        ->call('addLine', 1)
        ->set('data.events.1.lines.1.item_id', $items['B001-056']->id)
        ->set('data.events.1.lines.1.quantity', '3')
        ->set('photos.1.0', UploadedFile::fake()->image('nasi-kering.jpg'))
        ->call('addEvent')
        ->set('data.events.2.section', 'BAR')
        ->set('data.events.2.category_id', $categories['Spoil']->id)
        ->set('data.events.2.reason', 'Produk tidak layak')
        ->set('data.events.2.lines.0.item_id', $items['P004-031']->id)
        ->set('data.events.2.lines.0.quantity', '944')
        ->set('photos.2.0', UploadedFile::fake()->image('vanilla-ice-cream.jpg'))
        ->call('submit')
        ->assertRedirect();

    $report = WasteReport::query()->with('latestVersion.events.lines', 'latestVersion.events.evidences')->sole();
    $events = $report->latestVersion->events->sortBy('sequence')->values();

    expect($report->brand_id)->toBe($brand->id)
        ->and($report->outlet_id)->toBe($outlet->id)
        ->and($report->event_date->format('Y-m-d'))->toBe('2026-09-01')
        ->and($events)->toHaveCount(3)
        ->and($events[0]->section)->toBe('BAR')
        ->and($events[0]->lines->sole()->quantity)->toBe('58.0000')
        ->and($events[1]->section)->toBe('ASSEMBLY')
        ->and($events[1]->category_name)->toBe('Spoil')
        ->and($events[1]->lines->pluck('item_code')->all())->toBe(['B001-008', 'B001-056'])
        ->and($events[1]->lines->pluck('unit')->all())->toBe(['GR', 'ML'])
        ->and($events[2]->lines->sole()->unit)->toBe('GR')
        ->and($events->flatMap->evidences)->toHaveCount(3);
});

it('accepts Luuca comma decimals from representative Excel rows', function (): void {
    [$brand, $outlet, $items, $categories] = excelWasteSetup('LUUCA', [
        'BB-100022' => ['BUAH LONGAN 565GR', 'GR'],
        'BB-100023' => ['NATA DE COCO KARA 1KGX6', 'GR'],
        'BB-100500' => ['BUAH STRAWBERRY', 'GR'],
        'BB-100501' => ['BUAH ANGGUR', 'GR'],
    ], ['Waste', 'Spoil']);

    Livewire::test(PublicWasteReportForm::class, ['brand' => 'luuca', 'outlet' => $outlet->slug])
        ->set('data.event_date', '2026-09-02')
        ->set('data.reporter_name', 'Petugas Ciledug')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep')
        ->set('data.events.0.category_id', $categories['Waste']->id)
        ->set('data.events.0.reason', 'Air')
        ->set('data.events.0.lines.0.item_id', $items['BB-100022']->id)
        ->set('data.events.0.lines.0.quantity', '494,1')
        ->call('addLine', 0)
        ->set('data.events.0.lines.1.item_id', $items['BB-100023']->id)
        ->set('data.events.0.lines.1.quantity', '584,5')
        ->set('photos.0.0', UploadedFile::fake()->image('buah-dan-nata.jpg'))
        ->call('addEvent')
        ->set('data.events.1.category_id', $categories['Waste']->id)
        ->set('data.events.1.reason', 'Daun')
        ->set('data.events.1.lines.0.item_id', $items['BB-100500']->id)
        ->set('data.events.1.lines.0.quantity', '72,6')
        ->set('photos.1.0', UploadedFile::fake()->image('strawberry.jpg'))
        ->call('addEvent')
        ->set('data.events.2.category_id', $categories['Spoil']->id)
        ->set('data.events.2.reason', 'Busuk')
        ->set('data.events.2.lines.0.item_id', $items['BB-100501']->id)
        ->set('data.events.2.lines.0.quantity', '120,8')
        ->set('photos.2.0', UploadedFile::fake()->image('anggur.jpg'))
        ->call('submit')
        ->assertRedirect();

    $report = WasteReport::query()->with('latestVersion.events.lines')->sole();
    $events = $report->latestVersion->events->sortBy('sequence')->values();

    expect($report->brand_id)->toBe($brand->id)
        ->and($events)->toHaveCount(3)
        ->and($events[0]->section)->toBeNull()
        ->and($events[0]->lines->pluck('quantity')->all())->toBe(['494.1000', '584.5000'])
        ->and($events[0]->lines->pluck('unit')->all())->toBe(['GR', 'GR'])
        ->and($events[1]->lines->sole()->quantity)->toBe('72.6000')
        ->and($events[2]->lines->sole()->quantity)->toBe('120.8000');
});

it('records Momoyo reference, PIP, and NON PIP quantities from source rows', function (): void {
    [$brand, $outlet, $items, $categories] = excelWasteSetup('MOMOYO', [
        'BB-000075'  => ['Oolong Tea', 'Gram', 'Bahan Baku'],
        'PIP-000010' => ['Black Tea PIP', 'gram', 'PIP'],
        'BB-000001'  => ['Black Tea', 'Gram', 'Bahan Baku'],
        'BB-000077'  => ['Non Dairy Creamer', 'Gram', 'Bahan Baku'],
    ], ['Waste']);

    $component = Livewire::test(PublicWasteReportForm::class, ['brand' => 'momoyo', 'outlet' => $outlet->slug])
        ->set('data.event_date', '2026-09-02')
        ->set('data.reporter_name', 'Petugas Ciledug')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep');

    expect($component->get('referenceItems'))->toHaveKeys([$items['BB-000075']->id, $items['PIP-000010']->id]);

    $component
        ->set('data.events.0.category_id', $categories['Waste']->id)
        ->set('data.events.0.reason', 'Oolong Tea dari contoh Excel')
        ->set('data.events.0.pip_item_id', $items['BB-000075']->id)
        ->set('data.events.0.pip_quantity', '2796')
        ->set('data.events.0.lines.0.item_id', $items['BB-000075']->id)
        ->set('data.events.0.lines.0.quantity', '136.39')
        ->set('photos.0.0', UploadedFile::fake()->image('oolong-tea.jpg'))
        ->call('addEvent')
        ->set('data.events.1.category_id', $categories['Waste']->id)
        ->set('data.events.1.reason', 'Bahan terbuang saat produksi')
        ->set('data.events.1.pip_item_id', $items['PIP-000010']->id)
        ->set('data.events.1.pip_quantity', '353')
        ->set('data.events.1.lines.0.item_id', $items['BB-000001']->id)
        ->set('data.events.1.lines.0.quantity', '5.88')
        ->set('photos.1.0', UploadedFile::fake()->image('black-tea.jpg'))
        ->call('addEvent')
        ->set('data.events.2.category_id', $categories['Waste']->id)
        ->set('data.events.2.reason', 'NON PIP dari contoh Excel')
        ->set('data.events.2.pip_quantity', '99')
        ->set('data.events.2.lines.0.item_id', $items['BB-000077']->id)
        ->set('data.events.2.lines.0.quantity', '7.82')
        ->set('photos.2.0', UploadedFile::fake()->image('creamer.jpg'))
        ->call('submit')
        ->assertRedirect();

    $report = WasteReport::query()->with('latestVersion.events.lines', 'latestVersion.events.evidences')->sole();
    $events = $report->latestVersion->events->sortBy('sequence')->values();

    expect($report->brand_id)->toBe($brand->id)
        ->and($report->event_date->format('Y-m-d'))->toBe('2026-09-02')
        ->and($events)->toHaveCount(3)
        ->and($events[0]->pip_item_code)->toBe('BB-000075')
        ->and($events[0]->pip_item_name)->toBe('Oolong Tea')
        ->and($events[0]->pip_quantity)->toBe('2796.0000')
        ->and($events[0]->lines->sole()->line_role)->toBe('direct')
        ->and($events[0]->lines->sole()->quantity)->toBe('136.3900')
        ->and($events[1]->pip_item_code)->toBe('PIP-000010')
        ->and($events[1]->pip_unit)->toBe('gram')
        ->and($events[1]->pip_quantity)->toBe('353.0000')
        ->and($events[1]->lines->sole()->line_role)->toBe('component')
        ->and($events[1]->lines->sole()->unit)->toBe('Gram')
        ->and($events[1]->lines->sole()->quantity)->toBe('5.8800')
        ->and($events[2]->pip_item_id)->toBeNull()
        ->and($events[2]->pip_quantity)->toBe('99.0000')
        ->and($events[2]->lines->sole()->line_role)->toBe('direct')
        ->and($events[2]->lines->sole()->unit)->toBe('Gram')
        ->and($events[2]->lines->sole()->quantity)->toBe('7.8200')
        ->and($events->flatMap->evidences)->toHaveCount(3);
});

it('requires a photo for each event in a multi-row submission', function (): void {
    [, $outlet, $items, $categories] = excelWasteSetup('LUUCA', [
        'BB-100022' => ['BUAH LONGAN 565GR', 'GR'],
        'BB-100023' => ['NATA DE COCO KARA 1KGX6', 'GR'],
    ], ['Waste']);

    Livewire::test(PublicWasteReportForm::class, ['brand' => 'luuca', 'outlet' => $outlet->slug])
        ->set('data.event_date', '2026-09-02')
        ->set('data.reporter_name', 'Petugas Ciledug')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep')
        ->set('data.events.0.category_id', $categories['Waste']->id)
        ->set('data.events.0.reason', 'Air')
        ->set('data.events.0.lines.0.item_id', $items['BB-100022']->id)
        ->set('data.events.0.lines.0.quantity', '494,1')
        ->set('photos.0.0', UploadedFile::fake()->image('longan.jpg'))
        ->call('addEvent')
        ->set('data.events.1.category_id', $categories['Waste']->id)
        ->set('data.events.1.reason', 'Air')
        ->set('data.events.1.lines.0.item_id', $items['BB-100023']->id)
        ->set('data.events.1.lines.0.quantity', '584,5')
        ->call('submit')
        ->assertHasErrors(['photos.1']);

    expect(WasteReport::query()->count())->toBe(0);
});

it('rejects formula text and invalid quantities without creating a report', function (string $quantity): void {
    [$brand, $outlet, $items, $categories] = excelWasteSetup('LUUCA', [
        'BB-100500' => ['BUAH STRAWBERRY', 'GR'],
    ], ['Waste']);

    $data = excelWasteData($items['BB-100500'], $categories['Waste'], $quantity);

    expect(fn () => app(WasteReportService::class)->submit($brand, $outlet, $data, [0 => [UploadedFile::fake()->image('strawberry.jpg')]]))
        ->toThrow(ValidationException::class);
    expect(WasteReport::query()->count())->toBe(0);
})->with([
    'Excel formula'           => '=9+13',
    'empty quantity'          => '',
    'zero'                    => '0',
    'negative quantity'       => '-2',
    'over four decimals'      => '0.12345',
    'over database precision' => '100000000000000',
]);

it('shows an inline error when an Excel formula is pasted into the form', function (): void {
    [, $outlet, $items, $categories] = excelWasteSetup('LUUCA', [
        'BB-100500' => ['BUAH STRAWBERRY', 'GR'],
    ], ['Waste']);

    $component = Livewire::test(PublicWasteReportForm::class, ['brand' => 'luuca', 'outlet' => $outlet->slug])
        ->set('data.event_date', '2026-09-02')
        ->set('data.reporter_name', 'Petugas Ciledug')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep')
        ->set('data.events.0.category_id', $categories['Waste']->id)
        ->set('data.events.0.reason', 'Daun')
        ->set('data.events.0.lines.0.item_id', $items['BB-100500']->id)
        ->set('data.events.0.lines.0.quantity', '=9+13')
        ->set('photos.0.0', UploadedFile::fake()->image('strawberry.jpg'))
        ->call('submit')
        ->assertHasErrors(['data.events.0.lines.0.quantity']);

    $component->assertSeeText($component->errors()->first('data.events.0.lines.0.quantity'));
    expect(WasteReport::query()->count())->toBe(0);
});

it('rejects an item or category from another brand master', function (string $foreignField): void {
    [$luuca, $outlet, $luucaItems, $luucaCategories] = excelWasteSetup('LUUCA', [
        'BB-100500' => ['BUAH STRAWBERRY', 'GR'],
    ], ['Waste']);
    [, , $momoyoItems, $momoyoCategories] = excelWasteSetup('MOMOYO', [
        'BB-000001' => ['Black Tea', 'GR', 'Bahan Baku'],
    ], ['Waste']);

    $data = excelWasteData($luucaItems['BB-100500'], $luucaCategories['Waste'], '72,6');
    if ($foreignField === 'item') {
        $data['events'][0]['lines'][0]['item_id'] = $momoyoItems['BB-000001']->id;
    } else {
        $data['events'][0]['category_id'] = $momoyoCategories['Waste']->id;
    }

    expect(fn () => app(WasteReportService::class)->submit($luuca, $outlet, $data, [0 => [UploadedFile::fake()->image('foreign-brand.jpg')]]))
        ->toThrow(ValidationException::class);
    expect(WasteReport::query()->count())->toBe(0);
})->with(['item', 'category']);

it('accepts Momoyo PIP waste recorded against the same or another PIP reference', function (string $wasteItemCode): void {
    [$brand, $outlet, $items, $categories] = excelWasteSetup('MOMOYO', [
        'PIP-000010' => ['Black Tea PIP', 'GR', 'PIP'],
        'PIP-000020' => ['Ice Cream Original PIP', 'GR', 'PIP'],
        'BB-000001'  => ['Black Tea', 'GR', 'Bahan Baku'],
    ], ['Waste']);

    $data = excelWasteData($items['BB-000001'], $categories['Waste'], '5.88');
    $data['events'][0]['pip_item_id'] = $items['PIP-000010']->id;
    $data['events'][0]['pip_quantity'] = '353';
    $data['events'][0]['lines'][0]['item_id'] = $items[$wasteItemCode]->id;

    $result = app(WasteReportService::class)->submit($brand, $outlet, $data, [0 => [UploadedFile::fake()->image('black-tea.jpg')]]);
    $event = $result['report']->latestVersion->events()->with('lines')->sole();

    expect($event->pip_item_code)->toBe('PIP-000010')
        ->and($event->lines->sole()->item_code)->toBe($wasteItemCode)
        ->and($event->lines->sole()->line_role)->toBe('component');
})->with(['same PIP' => 'PIP-000010', 'another PIP' => 'PIP-000020']);

it('rejects a Momoyo reference from another brand even when its item is active', function (): void {
    [$brand, $outlet, $items, $categories] = excelWasteSetup('MOMOYO', [
        'BB-000001' => ['Black Tea', 'GR', 'Bahan Baku'],
    ], ['Waste']);
    [, , $foreignItems] = excelWasteSetup('LUUCA', [
        'BB-100500' => ['Buah Strawberry', 'GR', 'Bahan Baku'],
    ], ['Waste']);

    $data = excelWasteData($items['BB-000001'], $categories['Waste'], '5.88');
    $data['events'][0]['pip_item_id'] = $foreignItems['BB-100500']->id;
    $data['events'][0]['pip_quantity'] = '99';

    expect(fn () => app(WasteReportService::class)->submit($brand, $outlet, $data, [0 => [UploadedFile::fake()->image('black-tea.jpg')]]))
        ->toThrow(ValidationException::class);
    expect(WasteReport::query()->count())->toBe(0);
});

it('requires a chosen category when the source Excel has a misspelled label', function (): void {
    [$brand, $outlet, $items, $categories] = excelWasteSetup('MOMOYO', [
        'BB-000001' => ['Black Tea', 'GR', 'Bahan Baku'],
    ], ['Training/Trial']);

    $data = excelWasteData($items['BB-000001'], $categories['Training/Trial'], '5.88');
    $data['events'][0]['category_id'] = null;
    $data['events'][0]['category_name'] = 'Tarining/Trial';

    expect(fn () => app(WasteReportService::class)->submit($brand, $outlet, $data, [0 => [UploadedFile::fake()->image('training.jpg')]]))
        ->toThrow(ValidationException::class);
    expect(WasteReport::query()->count())->toBe(0);
});

it('locks the brand and outlet resolved from the public URL', function (string $property): void {
    [, $luucaOutlet] = excelWasteSetup('LUUCA', [
        'BB-100500' => ['BUAH STRAWBERRY', 'GR'],
    ], ['Waste']);
    [$momoyoBrand, $momoyoOutlet] = excelWasteSetup('MOMOYO', [
        'BB-000001' => ['Black Tea', 'GR', 'Bahan Baku'],
    ], ['Waste']);

    $foreignId = $property === 'brandData.id' ? $momoyoBrand->id : $momoyoOutlet->id;

    expect(fn () => Livewire::test(PublicWasteReportForm::class, ['brand' => 'luuca', 'outlet' => $luucaOutlet->slug])
        ->set($property, $foreignId))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect(WasteReport::query()->count())->toBe(0);
})->with(['brandData.id', 'outletData.id']);

it('renders both Momoyo quantities as locale friendly decimal inputs', function (): void {
    [, $outlet] = excelWasteSetup('MOMOYO', [
        'PIP-000010' => ['Black Tea PIP', 'GR', 'PIP'],
        'BB-000001'  => ['Black Tea', 'GR', 'Bahan Baku'],
    ], ['Waste']);

    $html = Livewire::test(PublicWasteReportForm::class, ['brand' => 'momoyo', 'outlet' => $outlet->slug])
        ->set('data.reporter_name', 'Petugas Ciledug')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep')
        ->html();

    foreach (['data.events.0.lines.0.quantity', 'data.events.0.pip_quantity'] as $model) {
        $input = excelWasteInputTag($html, $model);
        expect($input)->toContain('type="text"')->toContain('inputmode="decimal"');
    }
});

/**
 * @param  array<string, array{0: string, 1: string, 2?: string}>  $itemRows
 * @param  array<int, string>  $categoryNames
 * @param  array<int, string>  $sectionNames
 * @return array{0: WasteBrand, 1: WasteOutlet, 2: array<string, WasteItem>, 3: array<string, WasteCategory>}
 */
function excelWasteSetup(string $brandCode, array $itemRows, array $categoryNames, array $sectionNames = []): array
{
    $brand = WasteBrand::query()->create(['name' => ucfirst(strtolower($brandCode)), 'code' => $brandCode, 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'Ciledug',
        'code'      => 'CILEDUG',
        'slug'      => strtolower($brandCode).'-ciledug',
        'timezone'  => 'Asia/Jakarta',
        'is_active' => true,
    ]);

    $items = [];
    foreach ($itemRows as $code => $row) {
        [$name, $unit] = $row;
        $items[$code] = WasteItem::query()->create([
            'brand_id'  => $brand->id,
            'code'      => $code,
            'name'      => $name,
            'unit'      => $unit,
            'item_type' => $row[2] ?? null,
            'is_active' => true,
        ]);
    }

    $categories = [];
    foreach ($categoryNames as $name) {
        $categories[$name] = WasteCategory::query()->create([
            'brand_id'  => $brand->id,
            'code'      => strtoupper(str_replace(' ', '_', $name)),
            'name'      => $name,
            'is_active' => true,
        ]);
    }

    foreach ($sectionNames as $name) {
        WasteSection::query()->create([
            'brand_id'  => $brand->id,
            'code'      => $name,
            'name'      => $name,
            'is_active' => true,
        ]);
    }

    return [$brand, $outlet, $items, $categories];
}

/** @return array<string, mixed> */
function excelWasteData(WasteItem $item, WasteCategory $category, string $quantity): array
{
    return [
        'event_date'     => '2026-09-02',
        'reporter_name'  => 'Petugas Ciledug',
        'reporter_phone' => '081234567890',
        'events'         => [[
            'category_id' => $category->id,
            'reason'      => 'Daun',
            'lines'       => [['item_id' => $item->id, 'quantity' => $quantity]],
        ]],
    ];
}

function excelWasteInputTag(string $html, string $model): string
{
    preg_match_all('/<input\b[^>]*>/i', $html, $matches);

    foreach ($matches[0] as $tag) {
        if (str_contains($tag, 'wire:model="'.$model.'"')) {
            return $tag;
        }
    }

    return '';
}
