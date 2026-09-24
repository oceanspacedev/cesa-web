<?php

use Cesa\Waste\Livewire\PublicWasteReportForm;
use Cesa\Waste\Livewire\PublicWasteRevisionPage;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteNotificationDelivery;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Models\WasteWorkflow;
use Cesa\Waste\Services\WasteApprovalService;
use Cesa\Waste\Services\WasteReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    config(['waste.submissions.max_attempts' => 100]);
    app()->setLocale('id');
});

it('submits the Ciledug input flow for each brand with its own fields', function (string $code, string $slug, bool $hasSection, bool $hasPip): void {
    $brand = WasteBrand::query()->create(['name' => $code, 'code' => $code, 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'Ciledug',
        'code'      => 'CILEDUG',
        'slug'      => $slug,
        'timezone'  => 'Asia/Jakarta',
        'is_active' => true,
    ]);
    $item = WasteItem::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'BB-1',
        'name'      => 'Bahan Baku',
        'unit'      => 'GR',
        'item_type' => 'Bahan Baku',
        'is_active' => true,
    ]);
    $category = WasteCategory::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'WASTE',
        'name'      => 'Waste',
        'is_active' => true,
    ]);

    if ($hasSection) {
        WasteSection::query()->create([
            'brand_id'  => $brand->id,
            'code'      => 'BAR',
            'name'      => 'BAR',
            'is_active' => true,
        ]);
    }

    $pip = $hasPip ? WasteItem::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'PIP-1',
        'name'      => 'Produk PIP',
        'unit'      => 'PCS',
        'item_type' => 'PIP',
        'is_active' => true,
    ]) : null;

    $component = Livewire::test(PublicWasteReportForm::class, [
        'brand'  => strtolower($code),
        'outlet' => $slug,
    ])
        ->assertSet('brandData.code', $code)
        ->assertSet('outletData.slug', $slug)
        ->assertSeeText(__('waste::waste.monthly_flow_hint'))
        ->set('data.reporter_name', 'Sari')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep')
        ->assertSet('currentStep', 2)
        ->set('data.events.0.category_id', $category->id)
        ->set('data.events.0.reason', 'Produk rusak')
        ->set('data.events.0.lines.0.item_id', $item->id)
        ->set('data.events.0.lines.0.quantity', '1.25')
        ->set('photos.0.0', UploadedFile::fake()->image('evidence.jpg'));

    expect($component->get('sections'))->toBe($hasSection ? ['BAR'] : [])
        ->and($component->get('pipItems') === [])->toBe(! $hasPip);

    if ($hasSection) {
        $component->set('data.events.0.section', 'BAR');
    }

    if ($pip) {
        $component
            ->set('data.events.0.pip_item_id', $pip->id)
            ->set('data.events.0.pip_quantity', '1');
    }

    $component->call('submit')->assertRedirect();

    $report = WasteReport::query()->with('latestVersion.events.lines', 'latestVersion.events.evidences')->sole();
    $event = $report->latestVersion->events->sole();

    expect($report->brand_id)->toBe($brand->id)
        ->and($report->outlet_id)->toBe($outlet->id)
        ->and($event->section)->toBe($hasSection ? 'BAR' : null)
        ->and($event->pip_item_id)->toBe($pip?->id)
        ->and($event->lines->sole()->item_id)->toBe($item->id)
        ->and($event->evidences)->toHaveCount(1);
})->with([
    'LUUCA Ciledug'    => ['LUUCA', 'luuca-ciledug', false, false],
    'JCHICKEN Ciledug' => ['JCHICKEN', 'jchicken-ciledug', true, false],
    'MOMOYO Ciledug'   => ['MOMOYO', 'momoyo-ciledug', false, true],
]);

it('keeps a photo with its event when an earlier event is removed', function (): void {
    $brand = WasteBrand::query()->create(['name' => 'Luuca', 'code' => 'LUUCA', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'Ciledug',
        'code'      => 'CILEDUG',
        'slug'      => 'luuca-ciledug',
        'is_active' => true,
    ]);

    $event = ['reason' => '', 'lines' => [['item_id' => null, 'quantity' => null]]];
    $component = Livewire::test(PublicWasteReportForm::class, [
        'brand'  => strtolower($brand->code),
        'outlet' => $outlet->slug,
    ])
        ->set('data.events', [$event, $event, $event])
        ->set('photos.2.0', UploadedFile::fake()->image('third-event.jpg'))
        ->call('removeEvent', 1);

    $photos = $component->get('photos');

    expect($component->get('data.events'))->toHaveCount(2)
        ->and($photos)->toHaveKey(1)
        ->and($photos)->not->toHaveKey(0)
        ->and($photos[1])->toHaveCount(1);
});

it('keeps the surviving event evidence when the first event is removed during revision', function (): void {
    [$report, $manageToken] = wasteInputRejectedReport(2);
    $originalEvents = $report->latestVersion->events()->with('evidences')->orderBy('sequence')->get();
    $survivingEvidence = $originalEvents[1]->evidences->sole();
    $previewUrl = route('waste.public.evidence', [
        'evidence' => $survivingEvidence->getKey(),
        'token'    => $manageToken,
    ]);

    $this->get($previewUrl)
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'image/jpeg');

    Livewire::test(PublicWasteRevisionPage::class, ['token' => $manageToken])
        ->call('nextStep')
        ->assertSee($previewUrl, false)
        ->call('removeEvent', 0)
        ->call('submit')
        ->assertRedirect();

    $updatedReport = $report->fresh();
    $revisedEvent = $updatedReport->latestVersion->events()->with('evidences')->sole();

    expect($updatedReport->versions)->toHaveCount(2)
        ->and($revisedEvent->reason)->toBe('Reason 2')
        ->and($revisedEvent->evidences->sole()->original_name)->toBe('event-2.jpg')
        ->and($revisedEvent->evidences->sole()->sha256)->toBe($survivingEvidence->sha256);
});

it('requires a camera photo for a new event added during revision', function (): void {
    [$report, $manageToken, $item, $category] = wasteInputRejectedReport(1);

    $component = Livewire::test(PublicWasteRevisionPage::class, ['token' => $manageToken])
        ->call('nextStep')
        ->assertSet('currentStep', 2)
        ->call('addEvent')
        ->set('data.events.1.category_id', $category->id)
        ->set('data.events.1.reason', 'New incident')
        ->set('data.events.1.lines.0.item_id', $item->id)
        ->set('data.events.1.lines.0.quantity', '2')
        ->call('submit')
        ->assertHasErrors(['photos.1']);

    $component->assertSeeText($component->errors()->first('photos.1'));
    expect($report->fresh()->versions()->count())->toBe(1);
});

it('shows the PIP quantity error beside its field before a Momoyo report can be submitted', function (): void {
    $brand = WasteBrand::query()->create(['name' => 'Momoyo', 'code' => 'MOMOYO', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'Ciledug',
        'code'      => 'CILEDUG',
        'slug'      => 'momoyo-ciledug',
        'is_active' => true,
    ]);
    $componentItem = WasteItem::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'BB-1',
        'name'      => 'Black Tea',
        'unit'      => 'GR',
        'item_type' => 'Bahan Baku',
        'is_active' => true,
    ]);
    $pipItem = WasteItem::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'PIP-1',
        'name'      => 'Tea PIP',
        'unit'      => 'PCS',
        'item_type' => 'PIP',
        'is_active' => true,
    ]);
    $category = WasteCategory::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'WASTE',
        'name'      => 'Waste',
        'is_active' => true,
    ]);

    $component = Livewire::test(PublicWasteReportForm::class, [
        'brand'  => strtolower($brand->code),
        'outlet' => $outlet->slug,
    ])
        ->set('data.reporter_name', 'Sari')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep')
        ->set('data.events.0.category_id', $category->id)
        ->set('data.events.0.reason', 'Expired ingredient')
        ->set('data.events.0.lines.0.item_id', $componentItem->id)
        ->set('data.events.0.lines.0.quantity', '1')
        ->set('data.events.0.pip_item_id', $pipItem->id)
        ->set('photos.0.0', UploadedFile::fake()->image('evidence.jpg'))
        ->call('submit')
        ->assertHasErrors(['data.events.0.pip_quantity']);

    $component->assertSeeText($component->errors()->first('data.events.0.pip_quantity'));
    expect(WasteReport::query()->count())->toBe(0);
});

it('removes only the chosen item line and keeps the event photo', function (): void {
    $brand = WasteBrand::query()->create(['name' => 'Luuca', 'code' => 'LUUCA', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'Ciledug',
        'code'      => 'CILEDUG',
        'slug'      => 'luuca-ciledug',
        'is_active' => true,
    ]);

    $component = Livewire::test(PublicWasteReportForm::class, [
        'brand'  => strtolower($brand->code),
        'outlet' => $outlet->slug,
    ])
        ->set('data.events.0.lines', [
            ['item_id' => 11, 'quantity' => '1'],
            ['item_id' => 22, 'quantity' => '2'],
            ['item_id' => 33, 'quantity' => '3'],
        ])
        ->set('photos.0.0', UploadedFile::fake()->image('line-evidence.jpg'))
        ->call('removeLine', 0, 1);

    expect($component->get('data.events.0.lines'))->toBe([
        ['item_id' => 11, 'quantity' => '1'],
        ['item_id' => 33, 'quantity' => '3'],
    ])->and($component->get('photos.0'))->toHaveCount(1);
});

it('keeps the progress link when notification dispatch fails after submission', function (): void {
    $brand = WasteBrand::query()->create(['name' => 'Luuca', 'code' => 'LUUCA', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'Ciledug',
        'code'      => 'CILEDUG',
        'slug'      => 'luuca-ciledug',
        'is_active' => true,
    ]);
    $item = WasteItem::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'BB-1',
        'name'      => 'Bahan Baku',
        'unit'      => 'GR',
        'is_active' => true,
    ]);
    $category = WasteCategory::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'WASTE',
        'name'      => 'Waste',
        'is_active' => true,
    ]);

    Queue::shouldReceive('push')->twice()->andThrow(new RuntimeException('queue unavailable'));

    $component = Livewire::test(PublicWasteReportForm::class, [
        'brand'  => strtolower($brand->code),
        'outlet' => $outlet->slug,
    ])
        ->set('data.reporter_name', 'Sari')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep')
        ->set('data.events.0.category_id', $category->id)
        ->set('data.events.0.reason', 'Damaged')
        ->set('data.events.0.lines.0.item_id', $item->id)
        ->set('data.events.0.lines.0.quantity', '1')
        ->set('photos.0.0', UploadedFile::fake()->image('evidence.jpg'))
        ->call('submit')
        ->assertRedirect();

    $deliveries = WasteNotificationDelivery::query()->get();
    $redirect = (string) ($component->effects['redirect'] ?? '');

    expect(WasteReport::query()->count())->toBe(1)
        ->and($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('status')->unique()->all())->toBe(['failed'])
        ->and($deliveries->pluck('last_error')->unique()->all())->toBe(['queue unavailable']);

    $this->get($redirect)->assertSuccessful();
});

/**
 * @return array{0: WasteReport, 1: string, 2: WasteItem, 3: WasteCategory}
 */
function wasteInputRejectedReport(int $eventCount): array
{
    $brand = WasteBrand::query()->create(['name' => 'Luuca', 'code' => 'LUUCA', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'Ciledug',
        'code'      => 'CILEDUG',
        'slug'      => 'luuca-ciledug',
        'is_active' => true,
    ]);
    $item = WasteItem::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'BB-1',
        'name'      => 'Bahan Baku',
        'unit'      => 'GR',
        'is_active' => true,
    ]);
    $category = WasteCategory::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'WASTE',
        'name'      => 'Waste',
        'is_active' => true,
    ]);
    WasteWorkflow::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'One step',
        'steps'     => [['label' => 'Supervisor', 'name' => 'Supervisor', 'phone' => '081234567890']],
        'is_active' => true,
    ]);

    $events = [];
    $photos = [];

    for ($index = 0; $index < $eventCount; $index++) {
        $events[] = [
            'category_id' => $category->id,
            'reason'      => 'Reason '.($index + 1),
            'lines'       => [['item_id' => $item->id, 'quantity' => '1']],
        ];
        $photos[$index] = [UploadedFile::fake()->image('event-'.($index + 1).'.jpg')];
    }

    $submitted = app(WasteReportService::class)->submit($brand, $outlet, [
        'event_date'     => '2026-09-23',
        'reporter_name'  => 'Sari',
        'reporter_phone' => '081234567890',
        'events'         => $events,
    ], $photos);
    $decision = app(WasteApprovalService::class)->reject($submitted['approval_tokens'][0], 'Please revise');

    return [$submitted['report']->fresh(['latestVersion.events.evidences']), $decision['manage_token'], $item, $category];
}
