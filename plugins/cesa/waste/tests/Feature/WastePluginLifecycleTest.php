<?php

namespace Cesa\Waste\Tests\Feature;

use Cesa\Waste\Enums\WasteApprovalStatus;
use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Jobs\SendWasteNotification;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Models\WasteWorkflow;
use Cesa\Waste\Services\WasteApprovalService;
use Cesa\Waste\Services\WasteMisReviewService;
use Cesa\Waste\Services\WasteReportService;
use Cesa\Waste\Tests\WasteTestCase;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

class WastePluginLifecycleTest extends WasteTestCase
{
    public function test_submission_snapshots_workflow_and_approves_in_order(): void
    {
        [$brand, $outlet, $item, $category, $pip] = $this->seedWasteSetup();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $this->get(route('waste.public.form', ['brand' => 'JCHICKEN', 'outlet' => 'CILEDUG']))->assertOk();
        $result = app(WasteReportService::class)->submit($brand, $outlet, $this->reportData($item, $category, '1.25', $pip), [
            0 => [UploadedFile::fake()->image('waste.jpg')],
        ]);

        $report = $result['report']->fresh(['latestVersion.approvals', 'latestVersion.events.lines', 'latestVersion.events.evidences']);
        $this->assertSame(WasteReportStatus::Pending, $report->status);
        $this->assertCount(2, $report->latestVersion->approvals);
        $this->assertSame(WasteApprovalStatus::Pending, $report->latestVersion->approvals[0]->status);
        $this->assertSame(WasteApprovalStatus::Waiting, $report->latestVersion->approvals[1]->status);
        $this->assertSame('component', $report->latestVersion->events[0]->lines[0]->line_role);
        $this->assertCount(1, $report->latestVersion->events[0]->evidences);
        $this->get(route('waste.public.approval', ['token' => $result['approval_tokens'][0]]))->assertOk();
        $this->get(route('waste.public.progress', ['token' => $result['progress_token']]))->assertOk();
        $this->get(route('waste.public.evidence', [
            'evidence' => $report->latestVersion->events[0]->evidences[0]->getKey(),
            'token'    => $result['approval_tokens'][0],
        ]))->assertOk();

        $firstDecision = app(WasteApprovalService::class)->approve($result['approval_tokens'][0]);
        $this->assertNotNull($firstDecision['next_token']);
        $this->assertSame(WasteReportStatus::Pending, $firstDecision['report']->status);

        $secondDecision = app(WasteApprovalService::class)->approve($firstDecision['next_token']);
        $this->assertSame(WasteReportStatus::Pending, $secondDecision['report']->status);
        $this->assertDatabaseMissing('waste_notification_deliveries', ['report_id' => $report->getKey(), 'type' => 'requester_approved']);

        $reviewer = UserFactory::new()->createQuietly();
        $brand->users()->attach($reviewer);
        $report->latestVersion->events->first()->lines->first()->update(['sm_checked' => false, 'audit_checked' => true]);
        app(WasteMisReviewService::class)->approve($report, $reviewer);

        $this->assertDatabaseHas('waste_reports', ['id' => $report->getKey(), 'status' => 'approved']);
        $this->assertDatabaseHas('waste_notification_deliveries', ['report_id' => $report->getKey(), 'type' => 'requester_approved']);
        Queue::assertPushed(SendWasteNotification::class);
    }

    public function test_rejection_can_be_revised_without_overwriting_history(): void
    {
        [$brand, $outlet, $item, $category, $pip] = $this->seedWasteSetup();
        $service = app(WasteReportService::class);
        $first = $service->submit($brand, $outlet, $this->reportData($item, $category, '1.25', $pip), [
            0 => [UploadedFile::fake()->image('first.jpg')],
        ]);
        $rejected = app(WasteApprovalService::class)->reject($first['approval_tokens'][0], 'Foto kurang jelas.');
        $this->assertSame(WasteReportStatus::Rejected, $rejected['report']->status);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $this->get(route('waste.public.manage', ['token' => $first['manage_token']]))->assertNotFound();
        $this->get(route('waste.public.manage', ['token' => $rejected['manage_token']]))->assertOk();

        $second = $service->revise($rejected['report']->fresh(['brand', 'outlet', 'latestVersion.events.lines']), $this->reportData($item, $category, '2.5', $pip), [
            0 => [UploadedFile::fake()->image('second.jpg')],
        ]);

        $report = $second['report']->fresh('versions');
        $this->assertSame(WasteReportStatus::Pending, $report->status);
        $this->assertCount(2, $report->versions);
        $this->assertSame(WasteReportStatus::Rejected, $report->versions->first()->status);
        $this->assertSame(WasteReportStatus::Pending, $report->versions->last()->status);
        $this->assertSame('2.5000', (string) $report->latestVersion->events->first()->lines->first()->quantity);
    }

    /**
     * @return array{0: WasteBrand, 1: WasteOutlet, 2: WasteItem, 3: WasteCategory, 4: WasteItem}
     */
    protected function seedWasteSetup(): array
    {
        $brand = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
        $outlet = WasteOutlet::query()->create(['brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG', 'slug' => 'jchicken-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $item = WasteItem::query()->create(['brand_id' => $brand->id, 'code' => 'B001', 'name' => 'Chicken Popcorn', 'unit' => 'GR', 'item_type' => 'Bahan Baku', 'is_active' => true, 'source_status' => 'review']);
        $category = WasteCategory::query()->create(['brand_id' => $brand->id, 'code' => 'SPOIL', 'name' => 'Spoil', 'is_active' => true]);
        WasteSection::seedDefaults();
        $pip = WasteItem::query()->create(['brand_id' => $brand->id, 'code' => 'P001', 'name' => 'PIP Test', 'unit' => 'PCS', 'item_type' => 'PIP', 'is_active' => true, 'source_status' => 'review']);
        WasteWorkflow::query()->create([
            'brand_id' => $brand->id,
            'name'     => 'Default review',
            'steps'    => [
                ['label' => 'Supervisor', 'name' => 'Supervisor', 'phone' => '081234567890'],
                ['label' => 'Audit', 'name' => 'Audit', 'email' => 'audit@example.test'],
            ],
            'is_active' => true,
        ]);

        return [$brand, $outlet, $item, $category, $pip];
    }

    protected function reportData(WasteItem $item, WasteCategory $category, string $quantity = '1.25', ?WasteItem $pip = null): array
    {
        return [
            'event_date'     => '2026-09-22',
            'reporter_name'  => 'Field User',
            'reporter_phone' => '081234567890',
            'reporter_email' => null,
            'events'         => [[
                'section'      => 'BAR',
                'category_id'  => $category->id,
                'reason'       => 'Produk rusak',
                'pip_item_id'  => $pip?->id,
                'pip_quantity' => $pip ? '1' : null,
                'lines'        => [['item_id' => $item->id, 'quantity' => $quantity]],
            ]],
        ];
    }
}
