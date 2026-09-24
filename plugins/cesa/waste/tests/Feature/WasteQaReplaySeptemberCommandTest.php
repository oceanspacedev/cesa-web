<?php

use Cesa\Waste\Console\Commands\ReplayWasteSeptemberQa;
use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Models\WasteUnit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

it('replays one actual public submission into persistent QA rows without notifications and safely resumes', function (): void {
    $brand = WasteBrand::query()->create([
        'name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true,
    ]);
    $outlet = WasteOutlet::query()->create([
        'brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG',
        'slug'     => 'jchicken-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    WasteUnit::query()->create(['code' => 'GR', 'name' => 'Gram', 'is_active' => true]);
    $item = WasteItem::query()->create([
        'brand_id' => $brand->id, 'code' => 'B001-036', 'name' => 'Daun Mint',
        'unit'     => 'GR', 'source_unit_label' => 'GR', 'item_type' => 'bahan baku', 'is_active' => true,
    ]);
    $category = WasteCategory::query()->create([
        'brand_id' => $brand->id, 'code' => 'WASTE', 'name' => 'Waste', 'is_active' => true,
    ]);
    WasteSection::query()->create([
        'brand_id' => $brand->id, 'code' => 'BAR', 'name' => 'BAR', 'is_active' => true,
    ]);

    $sourceDirectory = sys_get_temp_dir().'/waste-qa-source-'.bin2hex(random_bytes(6));
    mkdir($sourceDirectory, 0700);
    $workbook = new Spreadsheet;
    $workbook->getActiveSheet()->setTitle('SEPTEMBER 26')->setCellValue('H7', 'Petugas TPS dari Excel');
    (new Xlsx($workbook))->save($sourceDirectory.'/Waste Form Adjustment Jchicken Ciledug .xlsx');
    $workbook->disconnectWorksheets();

    try {
        $options = [
            '--source-dir' => $sourceDirectory,
            '--brand'      => 'JCHICKEN',
            '--limit'      => '1',
        ];
        expect(Artisan::call('waste:qa-replay-september', [...$options, '--dry-run' => true]))
            ->toBe(0);
        expect(WasteReport::query()->count())->toBe(0);

        $exitCode = Artisan::call('waste:qa-replay-september', $options);
        expect($exitCode)->toBe(0, Artisan::output());
        $report = WasteReport::query()->with('latestVersion.events.evidences', 'latestVersion.events.lines')->sole();
        $event = $report->latestVersion->events->sole();
        $evidence = $event->evidences->sole();
        $line = $event->lines->sole();

        expect($report->submission_key)->toBe(ReplayWasteSeptemberQa::submissionKey('JCHICKEN', 7))
            ->and($report->reporter_email)->toBe(ReplayWasteSeptemberQa::reporterEmail('JCHICKEN', 7))
            ->and($report->reporter_name)->toBe('Petugas TPS dari Excel')
            ->and($report->status)->toBe(WasteReportStatus::Pending)
            ->and($report->outlet_id)->toBe($outlet->id)
            ->and($event->category_id)->toBe($category->id)
            ->and($line->item_id)->toBe($item->id)
            ->and($line->quantity)->toBe('58.0000')
            ->and($evidence->original_name)->toBe('QA-SIMULASI-JCHICKEN-baris-7.png')
            ->and(Storage::disk('local')->exists($evidence->path))->toBeTrue()
            ->and($report->activityLogs()->where('event', 'submitted')->count())->toBe(1)
            ->and($report->activityLogs()->where('event', 'qa_source_replay')->count())->toBe(1)
            ->and($report->notifications()->count())->toBe(0);
        Queue::assertNothingPushed();

        expect(Artisan::call('waste:qa-replay-september', $options))->toBe(0)
            ->and(WasteReport::query()->count())->toBe(1)
            ->and($report->activityLogs()->where('event', 'qa_source_replay')->count())->toBe(1);

        $randomKey = bin2hex(random_bytes(32));
        $report->forceFill(['submission_key' => $randomKey])->save();
        $report->activityLogs()->where('event', 'qa_source_replay')->delete();
        $evidence->forceFill(['original_name' => 'foto-bukan-QA.png'])->save();

        expect(Artisan::call('waste:qa-replay-september', $options))->toBe(1)
            ->and($report->fresh()->submission_key)->toBe($randomKey)
            ->and($report->activityLogs()->where('event', 'qa_source_replay')->count())->toBe(0)
            ->and(WasteReport::query()->count())->toBe(1);
    } finally {
        @unlink($sourceDirectory.'/Waste Form Adjustment Jchicken Ciledug .xlsx');
        @rmdir($sourceDirectory);
    }
});
