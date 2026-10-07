<?php

use Cesa\Waste\Enums\WasteApprovalStatus;
use Cesa\Waste\Enums\WasteReportStatus;
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

it('keeps the original progress link working after rejection and revision', function (): void {
    $result = durableWasteReport();
    $originalProgress = $result['progress_token'];

    $this->get(route('waste.public.progress', ['token' => $originalProgress]))->assertSuccessful();

    $decision = app(WasteApprovalService::class)->reject(
        $result['approval_tokens'][0],
        'Please correct the quantity.',
    );

    $this->get(route('waste.public.progress', ['token' => $originalProgress]))
        ->assertSuccessful()
        ->assertSeeText(__('waste::waste.needs_revision'));

    $this->get(route('waste.public.progress', ['token' => $decision['progress_token']]))
        ->assertSuccessful();

    $revised = app(WasteReportService::class)->revise($decision['report'], [
        'event_date'     => '2026-09-22',
        'reporter_name'  => 'Field User',
        'reporter_phone' => '081234567890',
        'events'         => [[
            'section'     => 'BAR',
            'category_id' => WasteCategory::query()->firstOrFail()->id,
            'reason'      => 'Corrected quantity',
            'lines'       => [[
                'item_id'  => WasteItem::query()->firstOrFail()->id,
                'quantity' => '2',
            ]],
        ]],
    ], [0 => [UploadedFile::fake()->image('two.jpg')]]);

    $this->get(route('waste.public.progress', ['token' => $originalProgress]))
        ->assertSuccessful()
        ->assertSeeText(__('waste::waste.status.pending'));

    $this->get(route('waste.public.progress', ['token' => $revised['progress_token']]))
        ->assertSuccessful();
});

it('keeps the original approval link working after a reminder rotates the current token', function (): void {
    $result = durableWasteReport();
    $approvalToken = $result['approval_tokens'][0];

    $this->get(route('waste.public.approval', ['token' => $approvalToken]))
        ->assertSuccessful()
        ->assertSee('wire:click="approve"', false);

    $approval = $result['report']->fresh('latestVersion.approvals')->latestVersion->approvals->first();
    expect($approval)->not->toBeNull();

    expect(app(WasteApprovalService::class)->remind($result['report']))->toBeTrue();

    expect($approval->fresh()->token_hash)->not->toBe(hash('sha256', $approvalToken))
        ->and($approval->fresh()->status)->toBe(WasteApprovalStatus::Pending)
        ->and($result['report']->fresh()->status)->toBe(WasteReportStatus::Pending);

    $this->get(route('waste.public.approval', ['token' => $approvalToken]))
        ->assertSuccessful()
        ->assertSee('wire:click="approve"', false);

    $decision = app(WasteApprovalService::class)->approve($approvalToken);

    expect($decision['report']->fresh()->status)->toBe(WasteReportStatus::Approved);
});

/**
 * @return array{report: WasteReport, progress_token: string, manage_token: string, approval_tokens: array<int, string>}
 */
function durableWasteReport(): array
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
        'brand_id'      => $brand->id,
        'code'          => 'B001',
        'name'          => 'Chicken Popcorn',
        'unit'          => 'GR',
        'item_type'     => 'Bahan Baku',
        'is_active'     => true,
        'source_status' => 'review',
    ]);
    $category = WasteCategory::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'SPOIL',
        'name'      => 'Spoil',
        'is_active' => true,
    ]);
    WasteSection::seedDefaults();
    WasteWorkflow::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'Outlet review',
        'steps'     => [[
            'label' => 'Supervisor',
            'name'  => 'Supervisor',
            'phone' => '089876543210',
            'email' => 'supervisor@example.test',
        ]],
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
