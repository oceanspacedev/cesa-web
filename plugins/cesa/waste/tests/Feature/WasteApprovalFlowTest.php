<?php

use Cesa\Waste\Enums\WasteApprovalStatus;
use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Exports\WasteReportExport;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteWorkflow;
use Cesa\Waste\Services\WasteApprovalService;
use Cesa\Waste\Services\WasteNotificationService;
use Cesa\Waste\Services\WasteReportService;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

it('walks one report from submission through each whatsapp step into the monthly sheet', function (): void {
    Queue::fake();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    app()->setLocale('id');

    $reviewer = UserFactory::new()->createQuietly();
    $brand = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    $brand->users()->attach($reviewer);
    $outlet = WasteOutlet::query()->create([
        'brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG',
        'slug'     => 'jchicken-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $item = WasteItem::query()->create([
        'brand_id' => $brand->id, 'code' => 'B001', 'name' => 'Chicken',
        'unit'     => 'PCS', 'item_type' => 'bahan baku', 'is_active' => true,
    ]);
    $category = WasteCategory::query()->create([
        'brand_id' => $brand->id, 'code' => 'WASTE', 'name' => 'Waste', 'is_active' => true,
    ]);
    WasteWorkflow::query()->create([
        'brand_id'  => $brand->id,
        'outlet_id' => $outlet->id,
        'name'      => 'Persetujuan Ciledug',
        'is_active' => true,
        'steps'     => [
            ['label' => 'Store Manager', 'name' => 'Sari', 'phone' => '081111111111'],
            ['label' => 'Audit', 'name' => 'Bima', 'phone' => '082222222222'],
        ],
    ]);

    $result = app(WasteReportService::class)->submit($brand, $outlet, [
        'event_date'     => '2026-09-22',
        'reporter_name'  => 'Field Reporter',
        'reporter_phone' => '089999887766',
        'events'         => [[
            'category_id' => $category->id,
            'reason'      => 'Layu',
            'lines'       => [['item_id' => $item->id, 'quantity' => '2']],
        ]],
    ], [0 => [UploadedFile::fake()->image('bukti.jpg')]]);
    app(WasteNotificationService::class)->queueSubmission(
        $result['report'],
        $result['progress_token'],
        $result['manage_token'],
        $result['approval_tokens'],
    );

    $report = $result['report']->fresh('latestVersion.approvals');
    $export = fn (): WasteReportExport => new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $reviewer);

    expect($report->status)->toBe(WasteReportStatus::Pending)
        ->and($report->latestVersion->approvals->pluck('status')->all())->toBe([
            WasteApprovalStatus::Pending,
            WasteApprovalStatus::Waiting,
        ])
        ->and($result['approval_tokens'])->toHaveCount(1)
        ->and($report->notifications()->where('type', 'approval_1')->first()->payload['message'])->toContain("Sari\nJchicken / Ciledug")
        ->and($export()->hasReports())->toBeFalse();

    $this->get(route('waste.public.approval', ['token' => $result['approval_tokens'][0]]))
        ->assertSuccessful()
        ->assertSeeText('Sari')
        ->assertSeeText('Bima')
        ->assertDontSeeText('Store Manager')
        ->assertSeeText(__('waste::waste.approval_status.pending'))
        ->assertSeeText(__('waste::waste.approval_status.waiting'))
        ->assertSeeText(__('waste::waste.approve'))
        ->assertDontSee('081111111111')
        ->assertDontSee('082222222222');

    $first = app(WasteApprovalService::class)->approve($result['approval_tokens'][0]);

    expect($first['report']->status)->toBe(WasteReportStatus::Pending)
        ->and($first['report']->latestVersion->approvals->pluck('status')->all())->toBe([
            WasteApprovalStatus::Approved,
            WasteApprovalStatus::Pending,
        ])
        ->and($first['report']->notifications()->where('type', 'approval_2')->first()->payload['message'])->toContain("Bima\nJchicken / Ciledug");

    $second = app(WasteApprovalService::class)->approve($first['next_token']);

    expect($second['report']->status)->toBe(WasteReportStatus::Approved)
        ->and($second['next_token'])->toBeNull()
        ->and($second['report']->notifications()->where('type', 'requester_approved')->exists())->toBeTrue()
        ->and($second['report']->notifications()->where('type', 'requester_approved')->first()->payload['message'])->toContain("Chicken 2 PCS\n\nLaporan disetujui.");

    $sheet = $export()->sheets()[0]->collection();

    expect($second['report']->status)->toBe(WasteReportStatus::Approved)
        ->and($sheet->get(5))->toContain('Store Manager', 'Audit')
        ->and($sheet->get(6)[10])->toBe('Disetujui')
        ->and($sheet->get(6)[11])->toBe('Disetujui');

    $this->get(route('waste.public.approval', ['token' => $result['approval_tokens'][0]]))
        ->assertSuccessful()
        ->assertSeeText(__('waste::waste.approval_closed_approved'))
        ->assertDontSeeText(__('waste::waste.approve'))
        ->assertDontSeeText(__('waste::waste.reject'));
});
