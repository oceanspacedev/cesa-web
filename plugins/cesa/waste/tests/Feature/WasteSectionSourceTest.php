<?php

use Cesa\Waste\Livewire\PublicWasteReportForm;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Services\WasteReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('reads section choices from the brand table and stores the chosen name on the event', function (): void {
    config(['waste.sections.LUUCA' => ['BAR', 'COOK']]);

    $brand = WasteBrand::query()->create(['name' => 'Luuca', 'code' => 'LUUCA', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'Ciledug',
        'code'      => 'CILEDUG',
        'slug'      => 'luuca-ciledug',
        'timezone'  => 'Asia/Jakarta',
        'is_active' => true,
    ]);
    $item = WasteItem::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'L1',
        'name'      => 'Tea',
        'unit'      => 'PCS',
        'is_active' => true,
    ]);
    $category = WasteCategory::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'WASTE',
        'name'      => 'Waste',
        'is_active' => true,
    ]);

    expect(fn () => app(WasteReportService::class)->submit($brand, $outlet, wasteSectionPayload($item, $category, 'BAR')))
        ->toThrow(ValidationException::class);

    Livewire::test(PublicWasteReportForm::class, [
        'brand'  => 'luuca',
        'outlet' => $outlet->slug,
    ])
        ->set('data.reporter_name', 'Sari')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep')
        ->assertDontSeeText(__('waste::waste.fields.section'));

    WasteSection::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'BAR',
        'name'      => 'BAR',
        'is_active' => true,
    ]);

    $report = app(WasteReportService::class)->submit($brand, $outlet, wasteSectionPayload($item, $category, 'BAR'), [
        0 => [UploadedFile::fake()->image('proof.jpg')],
    ])['report'];

    expect($report->latestVersion->events->first()->section)->toBe('BAR')
        ->and($brand->sections()->pluck('name')->all())->toBe(['BAR']);
});

/**
 * @return array<string, mixed>
 */
function wasteSectionPayload(WasteItem $item, WasteCategory $category, string $section): array
{
    return [
        'event_date'     => '2026-09-23',
        'reporter_name'  => 'Sari',
        'reporter_phone' => '081234567890',
        'events'         => [[
            'section'     => $section,
            'category_id' => $category->id,
            'reason'      => 'Layu',
            'lines'       => [['item_id' => $item->id, 'quantity' => '1']],
        ]],
    ];
}
