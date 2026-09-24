<?php

namespace Cesa\Waste\Tests\Feature;

use Cesa\Waste\Exports\WasteReportExport;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteWorkflow;
use Cesa\Waste\Services\WasteAccessService;
use Cesa\Waste\Services\WasteApprovalService;
use Cesa\Waste\Services\WasteMasterImportService;
use Cesa\Waste\Services\WasteMisReviewService;
use Cesa\Waste\Services\WasteReportService;
use Cesa\Waste\Tests\WasteTestCase;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class WasteReportingTest extends WasteTestCase
{
    public function test_brand_and_outlet_assignments_isolate_queries(): void
    {
        $firstBrand = WasteBrand::query()->create(['name' => 'First', 'code' => 'FIRST', 'is_active' => true]);
        $secondBrand = WasteBrand::query()->create(['name' => 'Second', 'code' => 'SECOND', 'is_active' => true]);
        $firstOutlet = WasteOutlet::query()->create(['brand_id' => $firstBrand->id, 'name' => 'First outlet', 'code' => 'F1', 'slug' => 'first-outlet', 'is_active' => true]);
        $secondOutlet = WasteOutlet::query()->create(['brand_id' => $secondBrand->id, 'name' => 'Second outlet', 'code' => 'S1', 'slug' => 'second-outlet', 'is_active' => true]);
        DB::table('users')->insert(['id' => 77, 'name' => 'Brand admin', 'email' => 'brand-admin@example.test', 'password' => 'secret', 'created_at' => now(), 'updated_at' => now()]);
        $firstBrand->users()->attach(77);

        $user = new class
        {
            public function getKey(): int
            {
                return 77;
            }

            public function can(string $ability): bool
            {
                return false;
            }
        };

        $access = app(WasteAccessService::class);
        $this->assertSame([$firstBrand->id], $access->scopeBrands(WasteBrand::query(), $user)->pluck('id')->all());
        $this->assertSame([$firstOutlet->id], $access->scopeOutlets(WasteOutlet::query(), $user)->pluck('id')->all());
        $this->assertTrue($access->canManageBrand($user, $firstBrand));
        $this->assertFalse($access->canManageOutlet($user, $secondOutlet));
    }

    public function test_master_import_uses_brand_code_and_deactivates_invalid_rows(): void
    {
        $brand = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
        $path = tempnam(sys_get_temp_dir(), 'waste-master-');
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Master data');
        $sheet->fromArray([
            ['Nama Item', 'Kode Item', 'Unit', 'Harga pokok', 'KELOMPOK', 'JENIS'],
            ['Same name', 'B001', 'GR', null, 'B001', 'bahan baku'],
            ['Same name', 'S001', 'PCS', null, 'S001', 'produk'],
            ['No unit', 'B002', null, null, 'B002', 'bahan baku'],
            ['Wrong row SALAH', 'B003', 'GR', null, 'B003', 'bahan baku'],
            ['WIP PREP. VANILLA ICE CREAM', 'P004-031', 'PRS', null, 'P004', 'produk'],
            ['WIP SPAGHETTI', 'P004-021', 'PRS', null, 'P004', 'produk'],
        ]);
        (new Xlsx($spreadsheet))->save($path);

        $result = app(WasteMasterImportService::class)->import('JCHICKEN', $path);
        @unlink($path);

        $this->assertSame(6, $result['imported']);
        $this->assertSame(2, $result['inactive']);
        $this->assertSame('GR', WasteItem::query()->where('brand_id', $brand->id)->where('code', 'B001')->value('unit'));
        $this->assertSame('GR', WasteItem::query()->where('brand_id', $brand->id)->where('code', 'P004-031')->value('unit'));
        $this->assertSame('corrected_unit', WasteItem::query()->where('brand_id', $brand->id)->where('code', 'P004-031')->value('source_status'));
        $this->assertSame('Satuan adjustment diperbaiki dari PRS menjadi GR berdasarkan riwayat form.', WasteItem::query()->where('brand_id', $brand->id)->where('code', 'P004-031')->value('notes'));
        $this->assertSame('PRS', WasteItem::query()->where('brand_id', $brand->id)->where('code', 'P004-021')->value('unit'));
        $this->assertTrue((bool) WasteItem::query()->where('brand_id', $brand->id)->where('code', 'S001')->value('is_active'));
        $this->assertFalse((bool) WasteItem::query()->where('brand_id', $brand->id)->where('code', 'B002')->value('is_active'));
        $this->assertFalse((bool) WasteItem::query()->where('brand_id', $brand->id)->where('code', 'B003')->value('is_active'));
    }

    public function test_summary_only_counts_approved_component_lines_once(): void
    {
        [$brand, $outlet, $pip, $component, $category] = $this->seedReportingSetup();
        $service = app(WasteReportService::class);
        $approved = $service->submit($brand, $outlet, $this->reportData($pip, $component, $category, '1.25'), [0 => [UploadedFile::fake()->image('approved.jpg')]]);
        app(WasteApprovalService::class)->approve($approved['approval_tokens'][0]);
        $reviewer = UserFactory::new()->createQuietly();
        $brand->users()->attach($reviewer);
        app(WasteMisReviewService::class)->approve($approved['report'], $reviewer);

        $rejected = $service->submit($brand, $outlet, $this->reportData($pip, $component, $category, '9.00'), [0 => [UploadedFile::fake()->image('rejected.jpg')]]);
        app(WasteApprovalService::class)->reject($rejected['approval_tokens'][0], 'Perlu koreksi.');

        $export = new WasteReportExport(status: 'all', user: new class
        {
            public function getKey(): int
            {
                return 1;
            }

            public function can(string $ability): bool
            {
                return true;
            }
        });
        $details = $export->detailRows();
        $summary = $export->summaryRows();

        $this->assertCount(2, $details);
        $this->assertCount(1, $summary);
        $this->assertSame(1.25, $summary->first()[6]);
        $this->assertSame('component', $details->first()[17]);
    }

    /**
     * @return array{0: WasteBrand, 1: WasteOutlet, 2: WasteItem, 3: WasteItem, 4: WasteCategory}
     */
    protected function seedReportingSetup(): array
    {
        $brand = WasteBrand::query()->create(['name' => 'Momoyo', 'code' => 'MOMOYO', 'is_active' => true]);
        $outlet = WasteOutlet::query()->create(['brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG', 'slug' => 'momoyo-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $pip = WasteItem::query()->create(['brand_id' => $brand->id, 'code' => 'PIP-1', 'name' => 'Milk Tea PIP', 'unit' => 'PCS', 'item_type' => 'PIP', 'is_active' => true]);
        $component = WasteItem::query()->create(['brand_id' => $brand->id, 'code' => 'BB-1', 'name' => 'Black Tea', 'unit' => 'GR', 'item_type' => 'Bahan Baku', 'is_active' => true]);
        $category = WasteCategory::query()->create(['brand_id' => $brand->id, 'code' => 'WASTE', 'name' => 'Waste', 'is_active' => true]);
        WasteWorkflow::query()->create([
            'brand_id'  => $brand->id,
            'name'      => 'One step',
            'steps'     => [['label' => 'Supervisor', 'name' => 'Supervisor', 'phone' => '081234567890']],
            'is_active' => true,
        ]);

        return [$brand, $outlet, $pip, $component, $category];
    }

    protected function reportData(WasteItem $pip, WasteItem $component, WasteCategory $category, string $quantity): array
    {
        return [
            'event_date'     => '2026-09-22',
            'reporter_name'  => 'Field User',
            'reporter_phone' => '081234567890',
            'events'         => [[
                'category_id'  => $category->id,
                'reason'       => 'Bukti PIP',
                'pip_item_id'  => $pip->id,
                'pip_quantity' => '1',
                'lines'        => [['item_id' => $component->id, 'quantity' => $quantity]],
            ]],
        ];
    }
}
