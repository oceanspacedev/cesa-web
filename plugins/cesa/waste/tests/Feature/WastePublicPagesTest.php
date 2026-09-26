<?php

use Cesa\Waste\Enums\WasteApprovalStatus;
use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Livewire\PublicWasteProgressPage;
use Cesa\Waste\Livewire\PublicWasteReportForm;
use Cesa\Waste\Livewire\PublicWasteRevisionPage;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Models\WasteWorkflow;
use Cesa\Waste\Services\WasteApprovalService;
use Cesa\Waste\Services\WasteReportService;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    app()->setLocale('id');
});

it('renders a guest report form even when the outlet has no approval workflow', function (): void {
    [$brand, $outlet] = wastePublicPagesSetup(withWorkflow: false);

    $this->get(route('waste.public.form', ['brand' => strtolower($brand->code), 'outlet' => $outlet->slug]))
        ->assertSuccessful()
        ->assertSeeText($brand->name)
        ->assertSeeText($outlet->name)
        ->assertSeeText(__('waste::waste.steps.start'))
        ->assertSeeText('Halaman 1 dari 2')
        ->assertSeeText(__('waste::waste.next'))
        ->assertDontSeeText(__('waste::waste.submit'));
});

it('queues a report for MIS review when no external approval workflow exists', function (): void {
    [$brand, $outlet, $item, $category] = wastePublicPagesSetup(withWorkflow: false);

    $result = app(WasteReportService::class)->submit($brand, $outlet, [
        'event_date'     => '2026-09-22',
        'reporter_name'  => 'Field Reporter',
        'reporter_phone' => '089999887766',
        'events'         => [[
            'category_id' => $category->id,
            'reason'      => 'Spilled during preparation',
            'lines'       => [['item_id' => $item->id, 'quantity' => '1.25']],
        ]],
    ], [0 => [UploadedFile::fake()->image('evidence.jpg')]]);

    expect($result['approval_tokens'])->toBe([])
        ->and($result['report']->status)->toBe(WasteReportStatus::Pending)
        ->and($result['report']->approved_at)->toBeNull()
        ->and($result['report']->latestVersion->approvals)->toHaveCount(0)
        ->and($result['report']->latestVersion->workflow_snapshot)->toBe([]);

    $this->get(route('waste.public.progress', ['token' => $result['progress_token']]))
        ->assertSuccessful()
        ->assertDontSeeText($result['report']->uid)
        ->assertSeeText(__('waste::waste.status.pending'));
});

it('shows Momoyo reference and NON PIP quantities on the public progress page', function (): void {
    $brand = WasteBrand::query()->create(['name' => 'Momoyo', 'code' => 'MOMOYO', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG',
        'slug'     => 'momoyo-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $oolong = WasteItem::query()->create([
        'brand_id' => $brand->id, 'code' => 'BB-000075', 'name' => 'Oolong Tea',
        'unit'     => 'Gram', 'item_type' => 'Bahan Baku', 'is_active' => true,
    ]);
    $creamer = WasteItem::query()->create([
        'brand_id' => $brand->id, 'code' => 'BB-000077', 'name' => 'Non Dairy Creamer',
        'unit'     => 'Gram', 'item_type' => 'Bahan Baku', 'is_active' => true,
    ]);
    $category = WasteCategory::query()->create([
        'brand_id' => $brand->id, 'code' => 'WASTE', 'name' => 'Waste', 'is_active' => true,
    ]);

    $result = app(WasteReportService::class)->submit($brand, $outlet, [
        'event_date' => '2026-09-01', 'reporter_name' => 'Petugas Ciledug', 'reporter_phone' => '081234567890',
        'events'     => [
            [
                'category_id' => $category->id, 'reason' => 'Oolong Tea',
                'pip_item_id' => $oolong->id, 'pip_quantity' => '2796',
                'lines'       => [['item_id' => $oolong->id, 'quantity' => '136.39']],
            ],
            [
                'category_id'  => $category->id, 'reason' => 'NON PIP',
                'pip_quantity' => '99',
                'lines'        => [['item_id' => $creamer->id, 'quantity' => '7.82']],
            ],
        ],
    ], [
        0 => [UploadedFile::fake()->image('oolong.jpg')],
        1 => [UploadedFile::fake()->image('creamer.jpg')],
    ]);

    Livewire::test(PublicWasteProgressPage::class, ['token' => $result['progress_token']])
        ->assertSet('events.0.pip', 'Oolong Tea')
        ->assertSet('events.0.pip_quantity', '2796')
        ->assertSet('events.1.pip', 'NON PIP')
        ->assertSet('events.1.pip_quantity', '99')
        ->assertSeeText('Non Dairy Creamer');
});

it('rejects a public submission when its active approval workflow is incomplete', function (array $steps, string $message): void {
    [$brand, $outlet, $item, $category] = wastePublicPagesSetup();
    WasteWorkflow::query()->where('brand_id', $brand->id)->sole()->update(['steps' => $steps]);

    $component = Livewire::test(PublicWasteReportForm::class, ['brand' => 'jchicken', 'outlet' => $outlet->slug])
        ->set('data.event_date', '2026-09-01')
        ->set('data.reporter_name', 'Petugas Ciledug')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep')
        ->set('data.events.0.category_id', $category->id)
        ->set('data.events.0.reason', 'Daun layu')
        ->set('data.events.0.lines.0.item_id', $item->id)
        ->set('data.events.0.lines.0.quantity', '58')
        ->set('photos.0.0', UploadedFile::fake()->image('daun-layu.jpg'))
        ->call('submit')
        ->assertHasErrors(['workflow']);

    $component->assertSeeText($message);
    expect(WasteReport::query()->count())->toBe(0);
})->with([
    'no approvers'                     => [[], 'Outlet belum memiliki workflow approval yang aktif.'],
    'missing approver name'            => [[['label' => 'Supervisor', 'phone' => '081234567890']], 'Workflow waste memiliki approver yang belum lengkap.'],
    'missing notification destination' => [[['label' => 'Supervisor', 'name' => 'Supervisor']], 'Setiap approver harus memiliki nomor WhatsApp atau email.'],
]);

it('renders a ready guest report form with camera controls and no file picker', function (): void {
    [$brand, $outlet, $item, $category] = wastePublicPagesSetup();

    $this->get(route('waste.public.form', ['brand' => strtolower($brand->code), 'outlet' => $outlet->slug]))
        ->assertSuccessful()
        ->assertSeeText(__('waste::waste.form_heading').' - '.$brand->name.' / '.$outlet->name)
        ->assertSeeText(__('waste::waste.fields.reporter_name'))
        ->assertSeeText(__('waste::waste.fields.reporter_phone'))
        ->assertSeeText(__('waste::waste.next'))
        ->assertSeeText('Halaman 1 dari 2')
        ->assertSee('pf-header-card', false)
        ->assertSee('pf-section-bar', false)
        ->assertSee('fi-input', false)
        ->assertSee('fi-fo-field', false)
        ->assertSee('space-y-6', false)
        ->assertSee('pf-page', false)
        ->assertDontSeeText(__('waste::waste.submit'))
        ->assertDontSeeText(__('waste::waste.camera_start'))
        ->assertDontSeeText($item->name)
        ->assertSee('x-data="wasteReporter"', false)
        ->assertSee('data-waste-reporter="name"', false)
        ->assertSee('data-waste-reporter="phone"', false)
        ->assertSee('data-waste-reporter="email"', false)
        ->assertSee('placeholder="'.__('waste::waste.placeholders.reporter_name').'"', false)
        ->assertSee('placeholder="'.__('waste::waste.placeholders.reporter_phone').'"', false)
        ->assertSee('placeholder="'.__('waste::waste.placeholders.reporter_email').'"', false)
        ->assertDontSee('data-waste-component', false)
        ->assertDontSee('type="file"', false)
        ->assertDontSee('Private Approver')
        ->assertDontSee('private-approver@example.test')
        ->assertDontSee('081111223344');

    expect(WasteReport::query()->count())->toBe(0);
    $this->assertGuest();
});

it('shows the selected item unit beside its quantity', function (): void {
    [$brand, $outlet, $item] = wastePublicPagesSetup();

    Livewire::test(PublicWasteReportForm::class, [
        'brand'  => strtolower($brand->code),
        'outlet' => $outlet->slug,
    ])
        ->set('data.reporter_name', 'Sari')
        ->set('data.reporter_phone', '081234567890')
        ->set('data.events.0.lines.0.item_id', $item->id)
        ->call('nextStep')
        ->assertSee('data-waste-quantity-unit="'.$item->unit.'"', false);
});

it('advances one page at a time and keeps later fields off the first page', function (): void {
    [$brand, $outlet, $item, $category] = wastePublicPagesSetup();

    Livewire::test(PublicWasteReportForm::class, [
        'brand'  => strtolower($brand->code),
        'outlet' => $outlet->slug,
    ])
        ->call('nextStep')
        ->assertHasErrors(['data.reporter_name', 'data.reporter_phone'])
        ->assertSet('currentStep', 1)
        ->set('data.reporter_name', 'Sari')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep')
        ->assertSet('currentStep', 2)
        ->assertSeeText(__('waste::waste.fields.reason'))
        ->assertSee('placeholder="'.__('waste::waste.placeholders.quantity').'"', false)
        ->assertSee('placeholder="'.__('waste::waste.placeholders.reason').'"', false)
        ->assertSeeText(__('waste::waste.placeholders.section'))
        ->assertSeeText(__('waste::waste.fields.section'))
        ->assertSeeText(__('waste::waste.fields.category'))
        ->assertSee($item->name.' — '.$item->code.' ('.$item->unit.')')
        ->assertSeeText(__('waste::waste.camera_start'))
        ->assertSeeText(__('waste::waste.submit'))
        ->assertSeeText('Halaman 2 dari 2')
        ->assertDontSeeText(__('waste::waste.next'))
        ->call('previousStep')
        ->assertSet('currentStep', 1)
        ->assertSeeText('Halaman 1 dari 2');
});

it('explains saved camera photos and lets the reporter remove one', function (): void {
    [$brand, $outlet] = wastePublicPagesSetup();

    Livewire::test(PublicWasteReportForm::class, [
        'brand'  => strtolower($brand->code),
        'outlet' => $outlet->slug,
    ])
        ->set('data.reporter_name', 'Sari')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep')
        ->assertSeeText(__('waste::waste.camera_progress', ['count' => 0, 'max' => 5]))
        ->assertSeeText(__('waste::waste.camera_need_more'))
        ->assertSeeText(__('waste::waste.camera_hint'))
        ->assertSeeText(__('waste::waste.camera_open_hint'))
        ->set('photos.0.0', UploadedFile::fake()->image('one.jpg'))
        ->set('photos.0.1', UploadedFile::fake()->image('two.jpg'))
        ->assertSeeText(__('waste::waste.camera_progress', ['count' => 2, 'max' => 5]))
        ->assertSeeText(__('waste::waste.camera_can_add', ['remaining' => 3]))
        ->assertSee('wire:click="removePhoto(0, 0)"', false)
        ->assertSee('wire:click="removePhoto(0, 1)"', false)
        ->assertDontSeeText('2/5')
        ->call('removePhoto', 0, 0)
        ->assertSeeText(__('waste::waste.camera_progress', ['count' => 1, 'max' => 5]))
        ->assertSee('wire:click="removePhoto(0, 0)"', false)
        ->assertDontSee('wire:click="removePhoto(0, 1)"', false);
});

it('lets guests search a select only when it has more than five choices', function (int $choiceCount, bool $searchable): void {
    [$brand, $outlet, , $category] = wastePublicPagesSetup();

    foreach (range(1, $choiceCount - 1) as $index) {
        WasteItem::query()->create([
            'brand_id'  => $brand->id,
            'code'      => 'EX-'.$index,
            'name'      => 'Extra Item '.$index,
            'unit'      => 'PCS',
            'is_active' => true,
        ]);
    }

    $component = Livewire::test(PublicWasteReportForm::class, [
        'brand'  => strtolower($brand->code),
        'outlet' => $outlet->slug,
    ])
        ->set('data.reporter_name', 'Sari')
        ->set('data.reporter_phone', '081234567890')
        ->set('data.events.0.category_id', $category->id)
        ->set('data.events.0.reason', 'Tumpah')
        ->call('nextStep');

    if ($searchable) {
        $component
            ->assertSee('data-waste-search-select', false)
            ->assertSee(__('waste::waste.select_search'), false);
    } else {
        $component->assertDontSee('data-waste-search-select', false);
    }
})->with([
    'five choices' => [5, false],
    'six choices'  => [6, true],
]);

it('sends a submitted report to its own url that stays after refresh', function (): void {
    [$brand, $outlet, $item, $category] = wastePublicPagesSetup();

    $component = Livewire::test(PublicWasteReportForm::class, [
        'brand'  => strtolower($brand->code),
        'outlet' => $outlet->slug,
    ])
        ->set('data.reporter_name', 'Sari')
        ->set('data.reporter_phone', '081234567890')
        ->set('data.events.0.category_id', $category->id)
        ->set('data.events.0.reason', 'Layu')
        ->set('data.events.0.lines.0.item_id', $item->id)
        ->set('data.events.0.lines.0.quantity', '1')
        ->set('photos.0.0', UploadedFile::fake()->image('one.jpg'))
        ->call('submit');

    $report = WasteReport::query()->first();
    $submittedUrl = (string) ($component->effects['redirect'] ?? '');

    $component->assertRedirect();
    $parts = explode('/', trim((string) parse_url($submittedUrl, PHP_URL_PATH), '/'));
    $token = $parts[2] ?? '';

    expect($submittedUrl)->toContain('/waste/progress/')
        ->and($submittedUrl)->not->toContain('/waste/submitted/')
        ->and($submittedUrl)->not->toContain('/waste/'.strtolower($brand->code).'/');

    $this->get($submittedUrl)
        ->assertSuccessful()
        ->assertSeeText('Sari')
        ->assertDontSeeText($report->uid)
        ->assertDontSeeText(__('waste::waste.fields.uid'))
        ->assertDontSeeText(__('waste::waste.keep_manage_link'));

    $this->get($submittedUrl)
        ->assertSuccessful()
        ->assertDontSeeText($report->uid);
});

it('renders report progress and evidence without exposing private approval or management data', function (): void {
    $result = wastePublicPagesReport();
    $report = $result['report'];
    $evidence = $report->latestVersion->events->first()->evidences->first();
    $evidenceUrl = route('waste.public.evidence', ['evidence' => $evidence->getKey(), 'token' => $result['progress_token']]);

    $this->get(route('waste.public.progress', ['token' => $result['progress_token']]))
        ->assertSuccessful()
        ->assertDontSeeText($report->uid)
        ->assertDontSeeText(__('waste::waste.fields.uid'))
        ->assertSeeText('Field Reporter')
        ->assertSeeText('Black Tea')
        ->assertSeeText('Spilled during preparation')
        ->assertDontSeeText('Supervisor')
        ->assertSeeText('1.25 GR')
        ->assertDontSeeText('1.2500')
        ->assertDontSeeText(__('waste::waste.approval_timeline'))
        ->assertDontSeeText($report->latestVersion->events->first()->lines->first()->item_code)
        ->assertSee('pf-header-card', false)
        ->assertSee('pf-page', false)
        ->assertSee('src="'.$evidenceUrl.'"', false)
        ->assertSee('waste-camera-slots', false)
        ->assertSee(__('waste::waste.progress_title'), false)
        ->assertSeeText(__('waste::waste.fields.reason'))
        ->assertSeeText(__('waste::waste.choose_item'))
        ->assertSeeText(__('waste::waste.fields.quantity'))
        ->assertDontSee('grid-cols-2', false)
        ->assertDontSee('Private Approver')
        ->assertDontSee('private-approver@example.test')
        ->assertDontSee('081111223344')
        ->assertDontSee('reporter@example.test')
        ->assertDontSee('089999887766')
        ->assertDontSee($result['approval_tokens'][0])
        ->assertDontSee($result['manage_token'])
        ->assertDontSee($evidence->path);

    $this->get($evidenceUrl)->assertSuccessful()->assertHeader('Content-Type', 'image/jpeg');
    expect($report->fresh()->status)->toBe(WasteReportStatus::Pending);

    $this->get(route('waste.public.submitted', [
        'token'    => $result['progress_token'],
        'revision' => $result['manage_token'],
    ]))->assertRedirect(route('waste.public.progress', ['token' => $result['progress_token']]));

    $this->get(route('waste.public.progress', ['token' => $result['progress_token']]))
        ->assertDontSee($result['manage_token'])
        ->assertDontSeeText(__('waste::waste.keep_manage_link'));

    $this->get(route('waste.public.submitted', [
        'token'    => $result['progress_token'],
        'revision' => 'not-the-revision-token',
    ]))->assertNotFound();
});

it('renders approval details with explicit decision confirmations without deciding on get', function (): void {
    $result = wastePublicPagesReport();
    $report = $result['report'];
    $approval = $report->latestVersion->approvals->first();
    $originalTokenHash = $approval->token_hash;

    $this->get(route('waste.public.approval', ['token' => $result['approval_tokens'][0]]))
        ->assertSuccessful()
        ->assertSeeText($report->uid)
        ->assertSeeText('Black Tea')
        ->assertSeeText('Spilled during preparation')
        ->assertSee('wire:click="approve"', false)
        ->assertSee('wire:click="reject"', false)
        ->assertSee('wire:confirm="'.__('waste::waste.confirm_approve').'"', false)
        ->assertSee('wire:confirm="'.__('waste::waste.confirm_reject').'"', false)
        ->assertSee('wire:model="rejectionReason"', false)
        ->assertSee('pf-header-card', false)
        ->assertDontSee('private-approver@example.test')
        ->assertDontSee('081111223344');

    $approval->refresh();
    expect($approval->status)->toBe(WasteApprovalStatus::Pending)
        ->and($approval->decided_at)->toBeNull()
        ->and($approval->token_hash)->toBe($originalTokenHash)
        ->and($report->fresh()->status)->toBe(WasteReportStatus::Pending)
        ->and($report->activityLogs()->count())->toBe(1);
});

it('explains how to revise a rejected report without exposing its private revision link', function (): void {
    $result = wastePublicPagesReport();
    $decision = app(WasteApprovalService::class)->reject($result['approval_tokens'][0], 'Please correct the quantity.');

    $this->get(route('waste.public.progress', ['token' => $decision['progress_token']]))
        ->assertSuccessful()
        ->assertSeeText(__('waste::waste.needs_revision'))
        ->assertSeeText('Please correct the quantity.')
        ->assertSeeText(__('waste::waste.revision_link_notice'))
        ->assertDontSee($decision['manage_token'])
        ->assertDontSee(route('waste.public.manage', ['token' => $decision['manage_token']]))
        ->assertDontSeeText(__('waste::waste.approval_timeline'));

    $this->get(route('waste.public.progress', ['token' => $result['progress_token']]))->assertNotFound();
    expect($decision['report']->fresh()->status)->toBe(WasteReportStatus::Rejected);
});

it('renders the editable report only after rejection and keeps the existing version unchanged on get', function (): void {
    $result = wastePublicPagesReport();
    $report = $result['report'];
    $manageUrl = route('waste.public.manage', ['token' => $result['manage_token']]);

    $this->get($manageUrl)->assertNotFound();
    $decision = app(WasteApprovalService::class)->reject($result['approval_tokens'][0], 'Please correct the quantity.');
    $this->get($manageUrl)->assertNotFound();

    $this->get(route('waste.public.manage', ['token' => $decision['manage_token']]))
        ->assertSuccessful()
        ->assertSeeText('Ciledug')
        ->assertSee('Field Reporter')
        ->assertSeeText('Halaman 1 dari 2')
        ->assertDontSee('type="file"', false);

    Livewire::test(PublicWasteRevisionPage::class, ['token' => $decision['manage_token']])
        ->assertSet('data.events.0.reason', 'Spilled during preparation')
        ->call('nextStep')
        ->assertHasNoErrors()
        ->assertSet('currentStep', 2)
        ->assertSee('Black Tea')
        ->assertSeeText(__('waste::waste.resubmit'))
        ->assertSeeText(__('waste::waste.camera_start'))
        ->assertSeeText(__('waste::waste.camera_capture'))
        ->assertSeeText(__('waste::waste.camera_retake'))
        ->assertSeeText(__('waste::waste.camera_use'))
        ->assertDontSee('type="file"', false);

    expect($report->fresh()->status)->toBe(WasteReportStatus::Rejected)
        ->and($report->versions()->count())->toBe(1)
        ->and($report->latestVersion->events->first()->evidences()->count())->toBe(1);
});

/**
 * @return array{0: WasteBrand, 1: WasteOutlet, 2: WasteItem, 3: WasteCategory}
 */
function wastePublicPagesSetup(bool $withWorkflow = true): array
{
    $brand = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'Ciledug',
        'code'      => 'CILEDUG',
        'slug'      => 'jchicken-ciledug',
        'timezone'  => 'Asia/Jakarta',
        'is_active' => true,
    ]);
    $item = WasteItem::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'BB-1',
        'name'      => 'Black Tea',
        'unit'      => 'GR',
        'is_active' => true,
    ]);
    $category = WasteCategory::query()->create(['brand_id' => $brand->id, 'code' => 'WASTE', 'name' => 'Waste', 'is_active' => true]);

    foreach (WasteSection::defaultNames()['JCHICKEN'] as $name) {
        WasteSection::query()->create([
            'brand_id'  => $brand->id,
            'code'      => $name,
            'name'      => $name,
            'is_active' => true,
        ]);
    }

    if ($withWorkflow) {
        WasteWorkflow::query()->create([
            'brand_id' => $brand->id,
            'name'     => 'Public pages workflow',
            'steps'    => [[
                'label' => 'Supervisor',
                'name'  => 'Private Approver',
                'phone' => '081111223344',
                'email' => 'private-approver@example.test',
            ]],
            'is_active' => true,
        ]);
    }

    return [$brand, $outlet, $item, $category];
}

/**
 * @return array{report: WasteReport, progress_token: string, manage_token: string, approval_tokens: array<int, string>}
 */
function wastePublicPagesReport(): array
{
    [$brand, $outlet, $item, $category] = wastePublicPagesSetup();

    return app(WasteReportService::class)->submit($brand, $outlet, [
        'event_date'     => '2026-09-22',
        'reporter_name'  => 'Field Reporter',
        'reporter_phone' => '089999887766',
        'reporter_email' => 'reporter@example.test',
        'events'         => [[
            'category_id' => $category->id,
            'reason'      => 'Spilled during preparation',
            'lines'       => [['item_id' => $item->id, 'quantity' => '1.25']],
        ]],
    ], [0 => [UploadedFile::fake()->image('evidence.jpg')]]);
}
