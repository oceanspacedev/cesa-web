<?php

use Cesa\Waste\Enums\WasteApprovalStatus;
use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Models\WasteWorkflow;
use Cesa\Waste\Services\WasteApprovalService;
use Cesa\Waste\Services\WasteReportService;
use Illuminate\Http\UploadedFile;

it('reminds the active pending approver with a fresh token', function (): void {
    $result = twoStepApprovalReport();

    app(WasteApprovalService::class)->approve($result['approval_tokens'][0]);

    $report = $result['report']->fresh(['latestVersion.approvals']);
    $activeApproval = $report->latestVersion->approvals->firstWhere('status', 'pending');
    $oldTokenHash = $activeApproval->token_hash;

    $this->artisan('approvals:send-pending-reminders')->assertSuccessful();

    $reminderDeliveries = $report->notifications()->where('type', 'approval_2_reminder')->get();

    expect($reminderDeliveries)->toHaveCount(2)
        ->and($reminderDeliveries->pluck('channel')->sort()->values()->all())->toBe(['email', 'whatsapp'])
        ->and($activeApproval->fresh()->token_hash)->not->toBe($oldTokenHash)
        ->and($activeApproval->fresh()->notified_at)->not->toBeNull()
        ->and($report->activityLogs()->where('event', 'reminder_sent')->count())->toBe(1)
        ->and($reminderDeliveries->first()->payload['message'])->toContain('Pengingat')
        ->and($reminderDeliveries->first()->payload['message'])->toContain($report->uid);
});

it('does not remind waste reports that are no longer pending', function (): void {
    $result = twoStepApprovalReport();

    $report = $result['report'];
    $report->forceFill([
        'status'      => WasteReportStatus::Approved,
        'approved_at' => now(),
    ])->save();
    $report->latestVersion->approvals->firstWhere('status', 'pending')->forceFill([
        'status'     => WasteApprovalStatus::Approved,
        'decided_at' => now(),
        'token_hash' => null,
    ])->save();

    $this->artisan('approvals:send-pending-reminders')->assertSuccessful();

    expect($report->notifications()->where('type', 'like', '%_reminder')->count())->toBe(0);
});

function twoStepApprovalReport(): array
{
    $brand = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG', 'slug' => 'jchicken-ciledug',
        'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $item = WasteItem::query()->create([
        'brand_id'  => $brand->id, 'code' => 'B001', 'name' => 'Chicken Popcorn', 'unit' => 'GR',
        'item_type' => 'Bahan Baku', 'is_active' => true, 'source_status' => 'review',
    ]);
    $category = WasteCategory::query()->create([
        'brand_id' => $brand->id, 'code' => 'SPOIL', 'name' => 'Spoil', 'is_active' => true,
    ]);
    WasteSection::seedDefaults();
    WasteWorkflow::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'Two step review',
        'steps'     => [
            ['label' => 'Supervisor', 'name' => 'Supervisor', 'phone' => '089876543210', 'email' => 'supervisor@example.test'],
            ['label' => 'Manager', 'name' => 'Manager', 'phone' => '081211122233', 'email' => 'manager@example.test'],
        ],
        'is_active' => true,
    ]);

    return app(WasteReportService::class)->submit($brand, $outlet, [
        'event_date'     => '2026-09-22',
        'reporter_name'  => 'Field User',
        'reporter_phone' => '081234567890',
        'reporter_email' => 'requester@example.test',
        'events'         => [[
            'section'     => 'BAR',
            'category_id' => $category->id,
            'reason'      => 'Produk rusak',
            'lines'       => [['item_id' => $item->id, 'quantity' => '1.25']],
        ]],
    ], [0 => [UploadedFile::fake()->image('one.jpg')]]);
}
