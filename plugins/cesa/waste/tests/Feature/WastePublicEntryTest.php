<?php

use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteWorkflow;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
});

it('shows the waste launcher only while the plugin is installed', function (bool $installed): void {
    Plugin::query()->where('name', 'waste')->update(['is_installed' => $installed]);
    Package::$plugins = [];

    $response = $this->get('/')->assertSuccessful();
    $response->assertViewHas('apps', function (array $apps) use ($installed): bool {
        $waste = collect($apps)->firstWhere('key', 'waste');

        return $installed
            ? $waste !== null && $waste['url'] === route('waste.public.index')
            : $waste === null;
    });

    if ($installed) {
        $response->assertSee('href="'.route('waste.public.index').'"', false);
    } else {
        $response->assertDontSee('href="'.route('waste.public.index').'"', false);
    }
})->with([true, false]);

it('presents the public waste launcher with the same card chrome as form transfer', function (): void {
    [$brand, $outlet] = wastePublicEntryOutlet();
    wastePublicEntryWorkflow($brand);

    $this->get(route('waste.public.index'))
        ->assertSuccessful()
        ->assertSee('bg-[#EFF6FF]', false)
        ->assertSee('border-t-[10px]', false)
        ->assertSee('cesa-primary-border', false)
        ->assertSee('waste-square', false)
        ->assertSee('hover:border-primary-600', false)
        ->assertDontSee('aria-label="CESA"', false)
        ->assertSee('text-transform: uppercase', false)
        ->assertSeeText($brand->name)
        ->assertSeeText($outlet->name)
        ->assertDontSeeText(__('waste::waste.entry.start'));
});

it('lets guests choose ready outlets grouped by brand without exposing approvers', function (): void {
    [$brand, $outlet] = wastePublicEntryOutlet();
    wastePublicEntryWorkflow($brand);
    [$otherBrand, $otherOutlet] = wastePublicEntryOutlet('MOMOYO', 'Momoyo');
    wastePublicEntryWorkflow($otherBrand);

    $response = $this->get(route('waste.public.index'))
        ->assertSuccessful()
        ->assertViewIs('waste::public.index')
        ->assertSeeText($brand->name)
        ->assertSeeText($otherBrand->name)
        ->assertSeeText($outlet->name)
        ->assertSee('href="'.wastePublicEntryFormUrl($brand, $outlet).'"', false)
        ->assertSee('href="'.wastePublicEntryFormUrl($otherBrand, $otherOutlet).'"', false)
        ->assertDontSee('Private Approver')
        ->assertDontSee('private-approver@example.test')
        ->assertDontSee('081111223344');

    $response->assertViewHas('brands', fn (array $brands): bool => collect($brands)->pluck('code')->sort()->values()->all() === ['JCHICKEN', 'MOMOYO']);
    $this->assertGuest();
});

it('shows outlet search only when at least fifteen visible outlets are listed', function (int $visibleCount, bool $showSearch, bool $includeHiddenOutlets): void {
    [$brand, $outlet] = wastePublicEntryOutlet();
    wastePublicEntryWorkflow($brand);
    [$otherBrand, $otherOutlet] = wastePublicEntryOutlet('MOMOYO', 'Momoyo');
    $otherOutlet->update(['name' => 'Unavailable outlet']);
    $outlets = [$outlet, $otherOutlet];

    for ($number = 3; $number <= $visibleCount; $number++) {
        $outlets[] = WasteOutlet::query()->create([
            'brand_id'  => $number % 2 === 0 ? $brand->id : $otherBrand->id,
            'name'      => 'Outlet '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            'code'      => 'OUTLET-'.$number,
            'slug'      => 'outlet-'.$number,
            'is_active' => true,
        ]);
    }

    if ($includeHiddenOutlets) {
        WasteOutlet::query()->create([
            'brand_id'  => $brand->id,
            'name'      => 'Inactive outlet secret',
            'code'      => 'INACTIVE',
            'slug'      => 'inactive-outlet',
            'is_active' => false,
        ]);
        [$inactiveBrand, $hiddenOutlet] = wastePublicEntryOutlet('HIDDEN', 'Inactive brand secret');
        $inactiveBrand->update(['is_active' => false]);
        $hiddenOutlet->update(['name' => 'Hidden brand outlet']);
        wastePublicEntryWorkflow($inactiveBrand);
    }

    $response = $this->get(route('waste.public.index'))
        ->assertSuccessful()
        ->assertViewHas('showSearch', $showSearch)
        ->assertViewHas('brands', fn (array $brands): bool => collect($brands)->sum(fn (array $brand): int => count($brand['outlets'])) === $visibleCount)
        ->assertSee('href="'.wastePublicEntryFormUrl($brand, $outlet).'"', false)
        ->assertSee('href="'.wastePublicEntryFormUrl($otherBrand, $otherOutlet).'"', false)
        ->assertDontSeeText('Belum siap')
        ->assertDontSeeText('Inactive outlet secret')
        ->assertDontSeeText('Inactive brand secret')
        ->assertDontSeeText('Hidden brand outlet');

    foreach ($outlets as $visibleOutlet) {
        $response->assertSeeText($visibleOutlet->name);
    }

    if ($showSearch) {
        $response->assertSee('id="outlet-search"', false);
    } else {
        $response->assertDontSee('id="outlet-search"', false);
    }
})->with([
    'fourteen visible outlets'                          => [14, false, false],
    'fifteen visible outlets including unavailable'     => [15, true, false],
    'sixteen visible outlets'                           => [16, true, false],
    'fourteen visible outlets and two excluded outlets' => [14, false, true],
]);

it('omits inactive brands and outlets from public choices', function (): void {
    [$brand, $outlet] = wastePublicEntryOutlet();
    wastePublicEntryWorkflow($brand);
    $inactiveOutlet = WasteOutlet::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'Inactive outlet secret',
        'code'      => 'INACTIVE',
        'slug'      => 'inactive',
        'is_active' => false,
    ]);
    [$inactiveBrand, $inactiveBrandOutlet] = wastePublicEntryOutlet('HIDDEN', 'Inactive brand secret');
    $inactiveBrand->update(['is_active' => false]);
    $inactiveBrandOutlet->update(['name' => 'Hidden brand outlet']);
    wastePublicEntryWorkflow($inactiveBrand);

    $this->get(route('waste.public.index'))
        ->assertSuccessful()
        ->assertSee('href="'.wastePublicEntryFormUrl($brand, $outlet).'"', false)
        ->assertDontSeeText($inactiveOutlet->name)
        ->assertDontSeeText($inactiveBrand->name)
        ->assertDontSeeText($inactiveBrandOutlet->name)
        ->assertDontSee(wastePublicEntryFormUrl($brand, $inactiveOutlet), false)
        ->assertDontSee(wastePublicEntryFormUrl($inactiveBrand, $inactiveBrandOutlet), false);
});

it('lets guests open outlets even when approval is missing or incomplete', function (string $configuration): void {
    [$brand, $outlet] = wastePublicEntryOutlet();

    if ($configuration !== 'missing') {
        wastePublicEntryWorkflow($brand, null, [
            'is_active' => $configuration !== 'inactive',
            'steps'     => match ($configuration) {
                'empty'            => [],
                'missing approver' => [['label' => 'Supervisor', 'phone' => '081111223344']],
                'missing contact'  => [['label' => 'Supervisor', 'name' => 'Private Approver']],
                default            => wastePublicEntrySteps(),
            },
        ]);
    }

    $this->get(route('waste.public.index'))
        ->assertSuccessful()
        ->assertSeeText($outlet->name)
        ->assertDontSeeText('Belum siap')
        ->assertSee('href="'.wastePublicEntryFormUrl($brand, $outlet).'"', false)
        ->assertViewHas('brands', fn (array $brands): bool => $brands[0]['outlets'][0]['url'] === wastePublicEntryFormUrl($brand, $outlet));

    $this->get(wastePublicEntryFormUrl($brand, $outlet))->assertSuccessful();
})->with(['missing', 'empty', 'missing approver', 'missing contact', 'inactive']);

it('uses a valid outlet override when the brand default is incomplete', function (): void {
    [$brand, $outlet] = wastePublicEntryOutlet();
    wastePublicEntryWorkflow($brand, null, ['steps' => []]);
    wastePublicEntryWorkflow($brand, $outlet);

    $this->get(route('waste.public.index'))
        ->assertSuccessful()
        ->assertSee('href="'.wastePublicEntryFormUrl($brand, $outlet).'"', false);
});

it('keeps an incomplete newest outlet override open for input', function (): void {
    [$brand, $outlet] = wastePublicEntryOutlet();
    $siblingOutlet = WasteOutlet::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'Pamulang',
        'code'      => 'PAMULANG',
        'slug'      => 'jchicken-pamulang',
        'is_active' => true,
    ]);
    wastePublicEntryWorkflow($brand);
    wastePublicEntryWorkflow($brand, $outlet);
    wastePublicEntryWorkflow($brand, $outlet, ['steps' => []]);

    $this->get(route('waste.public.index'))
        ->assertSuccessful()
        ->assertDontSeeText('Belum siap')
        ->assertSee('href="'.wastePublicEntryFormUrl($brand, $siblingOutlet).'"', false)
        ->assertSee('href="'.wastePublicEntryFormUrl($brand, $outlet).'"', false)
        ->assertViewHas('brands', fn (array $brands): bool => $brands[0]['outlets'][0]['url'] === wastePublicEntryFormUrl($brand, $outlet));
});

it('uses the newest active brand workflow and ignores inactive outlet overrides', function (): void {
    [$brand, $outlet] = wastePublicEntryOutlet();
    wastePublicEntryWorkflow($brand, null, ['steps' => []]);
    wastePublicEntryWorkflow($brand);
    wastePublicEntryWorkflow($brand, $outlet, ['is_active' => false, 'steps' => []]);

    $this->get(route('waste.public.index'))
        ->assertSuccessful()
        ->assertSee('href="'.wastePublicEntryFormUrl($brand, $outlet).'"', false);

    wastePublicEntryWorkflow($brand, null, ['steps' => []]);

    $this->get(route('waste.public.index'))
        ->assertSuccessful()
        ->assertDontSeeText('Belum siap')
        ->assertSee('href="'.wastePublicEntryFormUrl($brand, $outlet).'"', false);
});

/**
 * @return array{0: WasteBrand, 1: WasteOutlet}
 */
function wastePublicEntryOutlet(string $code = 'JCHICKEN', string $name = 'Jchicken'): array
{
    $brand = WasteBrand::query()->create(['name' => $name, 'code' => $code, 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'Ciledug',
        'code'      => 'CILEDUG',
        'slug'      => strtolower($code).'-ciledug',
        'timezone'  => 'Asia/Jakarta',
        'is_active' => true,
    ]);

    return [$brand, $outlet];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function wastePublicEntryWorkflow(WasteBrand $brand, ?WasteOutlet $outlet = null, array $attributes = []): WasteWorkflow
{
    return WasteWorkflow::query()->create(array_replace([
        'brand_id'  => $brand->id,
        'outlet_id' => $outlet?->id,
        'name'      => 'Public entry workflow',
        'is_active' => true,
        'steps'     => wastePublicEntrySteps(),
    ], $attributes));
}

/**
 * @return array<int, array{label: string, name: string, phone: string, email: string}>
 */
function wastePublicEntrySteps(): array
{
    return [[
        'label' => 'Supervisor',
        'name'  => 'Private Approver',
        'phone' => '081111223344',
        'email' => 'private-approver@example.test',
    ]];
}

function wastePublicEntryFormUrl(WasteBrand $brand, WasteOutlet $outlet): string
{
    return route('waste.public.form', ['brand' => strtolower($brand->code), 'outlet' => $outlet->slug]);
}
