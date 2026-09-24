<?php

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Services\WasteQaReviewerService;
use Cesa\Waste\Services\WasteReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Webkul\Security\Models\User;

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
    config(['waste.attachments.disk' => 'local']);
});

it('previews, approves through MIS, and reruns without duplicate checks or notifications', function (): void {
    [$report, $row] = qaReviewSourceReport('JCHICKEN');
    $originalDeliveries = $report->notifications()->count();

    $this->artisan('waste:qa-review-september', ['--brand' => 'JCHICKEN', '--limit' => '1', '--dry-run' => true])
        ->expectsOutputToContain('1 siap disetujui, 0 sudah disetujui, 0 perubahan')
        ->assertSuccessful();

    expect(User::query()->where('email', WasteQaReviewerService::EMAIL)->exists())->toBeFalse()
        ->and($report->fresh()->status)->toBe(WasteReportStatus::Pending);

    $this->artisan('waste:qa-review-september', ['--brand' => 'JCHICKEN', '--limit' => '1'])
        ->expectsOutputToContain('1 laporan disetujui MIS')
        ->assertSuccessful();

    $reviewer = User::query()->where('email', WasteQaReviewerService::EMAIL)->firstOrFail();
    $approved = $report->fresh('latestVersion.events.lines');
    $line = $approved->latestVersion->events->sole()->lines->sole();

    expect($reviewer->name)->toBe(WasteQaReviewerService::NAME)
        ->and($reviewer->is_active)->toBeFalse()
        ->and($approved->status)->toBe(WasteReportStatus::Approved)
        ->and($line->sm_checked)->toBe($row['sm'])
        ->and($line->audit_checked)->toBe($row['audit'])
        ->and($approved->activityLogs()->where('event', 'mis_approved')->where('actor_id', $reviewer->id)->count())->toBe(1)
        ->and($approved->notifications()->count())->toBe($originalDeliveries);

    $this->artisan('waste:qa-review-september', ['--brand' => 'JCHICKEN', '--limit' => '1'])
        ->expectsOutputToContain('1 sudah disetujui')
        ->assertSuccessful();

    expect($approved->activityLogs()->where('event', 'mis_approved')->count())->toBe(1);
});

it('requires the exact QA source log and source fields before writing any review', function (): void {
    [$valid] = qaReviewSourceReport('JCHICKEN');
    [$wrong, $wrongRow] = qaReviewSourceReport('JCHICKEN', sourceRowIndex: 1);
    $wrong->latestVersion->events->sole()->lines->sole()->forceFill(['quantity' => '999.0000'])->save();

    $this->artisan('waste:qa-review-september', ['--brand' => 'JCHICKEN', '--limit' => '2'])
        ->expectsOutputToContain('1 baris bermasalah. Tidak ada laporan yang disetujui.')
        ->assertFailed();

    expect($valid->fresh()->status)->toBe(WasteReportStatus::Pending)
        ->and(User::query()->where('email', WasteQaReviewerService::EMAIL)->exists())->toBeFalse();

    $wrong->latestVersion->events->sole()->lines->sole()->forceFill(['quantity' => $wrongRow['quantity']])->save();
    $wrong->activityLogs()->where('event', 'qa_source_replay')->delete();

    $this->artisan('waste:qa-review-september', ['--brand' => 'JCHICKEN', '--limit' => '2'])
        ->expectsOutputToContain('penanda asal TPS QA tidak lengkap')
        ->assertFailed();

    expect($valid->fresh()->status)->toBe(WasteReportStatus::Pending);
});

it('refuses to approve a QA report whose TPS evidence file is missing', function (): void {
    [$report] = qaReviewSourceReport('JCHICKEN');
    $evidence = $report->latestVersion->events->sole()->evidences->sole();
    Storage::disk('local')->delete($evidence->path);

    $this->artisan('waste:qa-review-september', ['--brand' => 'JCHICKEN', '--limit' => '1'])
        ->expectsOutputToContain('foto bukti TPS QA tidak tersedia')
        ->assertFailed();

    expect($report->fresh()->status)->toBe(WasteReportStatus::Pending)
        ->and(User::query()->where('email', WasteQaReviewerService::EMAIL)->exists())->toBeFalse();
});

it('handles Luuca AUDIT and Momoyo without MIS line flags', function (): void {
    [$luuca, $luucaRow] = qaReviewSourceReport('LUUCA');
    [$momoyo] = qaReviewSourceReport('MOMOYO');

    $this->artisan('waste:qa-review-september', ['--brand' => 'LUUCA', '--limit' => '1'])
        ->assertSuccessful();
    $this->artisan('waste:qa-review-september', ['--brand' => 'MOMOYO', '--limit' => '1'])
        ->assertSuccessful();

    $luucaLine = $luuca->fresh('latestVersion.events.lines')->latestVersion->events->sole()->lines->sole();
    $momoyoLine = $momoyo->fresh('latestVersion.events.lines')->latestVersion->events->sole()->lines->sole();
    expect($luuca->fresh()->status)->toBe(WasteReportStatus::Approved)
        ->and($luucaLine->audit_checked)->toBe($luucaRow['audit'])
        ->and($momoyo->fresh()->status)->toBe(WasteReportStatus::Approved)
        ->and($momoyoLine->audit_checked)->toBeNull()
        ->and($momoyoLine->sm_checked)->toBeNull();
});

/** @return array{0: WasteReport, 1: array<string, mixed>} */
function qaReviewSourceReport(string $brandCode, int $sourceRowIndex = 0): array
{
    $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/september_2026_source_replay.json'), true, 512, JSON_THROW_ON_ERROR);
    $brandFixture = $fixture['brands'][$brandCode];
    $row = $brandFixture['rows'][$sourceRowIndex];
    $brand = WasteBrand::query()->firstOrCreate(['code' => $brandCode], ['name' => $brandCode, 'is_active' => true]);
    $outlet = WasteOutlet::query()->firstOrCreate(['brand_id' => $brand->id, 'slug' => strtolower($brandCode).'-ciledug'], [
        'name' => 'Ciledug', 'code' => 'CILEDUG', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $category = WasteCategory::query()->firstOrCreate(['brand_id' => $brand->id, 'name' => $row['category']], [
        'code' => strtoupper($row['category']), 'is_active' => true,
    ]);
    if (isset($row['section'])) {
        WasteSection::query()->firstOrCreate(['brand_id' => $brand->id, 'name' => $row['section']], [
            'code' => $row['section'], 'is_active' => true,
        ]);
    }

    $itemFixture = $brandFixture['items'][$row['item_code']];
    $item = WasteItem::query()->firstOrCreate(['brand_id' => $brand->id, 'code' => $row['item_code']], [
        'name' => $itemFixture['name'], 'unit' => $row['unit'], 'item_type' => $itemFixture['item_type'], 'is_active' => true,
    ]);
    $pipItem = null;
    if (isset($row['pip_code'])) {
        $pipFixture = $brandFixture['items'][$row['pip_code']];
        $pipItem = WasteItem::query()->firstOrCreate(['brand_id' => $brand->id, 'code' => $row['pip_code']], [
            'name' => $pipFixture['name'], 'unit' => $pipFixture['unit'], 'item_type' => $pipFixture['item_type'], 'is_active' => true,
        ]);
    }

    $email = 'qa-waste-202609-'.strtolower($brandCode).'-row'.$row['source_row'].'@example.test';
    $report = app(WasteReportService::class)->submit($brand, $outlet, [
        'submission_key' => hash('sha256', 'waste-qa-tps-september-2026|'.$brandCode.'|'.$row['source_row']),
        'event_date'     => $row['date'], 'reporter_name' => 'QA TPS', 'reporter_phone' => '081234567890',
        'reporter_email' => $email,
        'events'         => [[
            'section'      => $row['section'] ?? '', 'category_id' => $category->id,
            'reason'       => $row['reason'] ?? '', 'pip_item_id' => $pipItem?->id,
            'pip_quantity' => $row['pip_quantity'] ?? null,
            'lines'        => [['item_id' => $item->id, 'quantity' => $row['quantity'], 'unit' => $row['unit']]],
        ]],
    ], [0 => [UploadedFile::fake()->image('qa-source.jpg')]])['report'];

    $report->activityLogs()->create([
        'version_id' => $report->latest_version_id,
        'event'      => 'qa_source_replay',
        'actor_type' => 'system',
        'metadata'   => [
            'period'     => '2026-09', 'brand' => $brandCode,
            'source_row' => $row['source_row'], 'source_file' => $brandFixture['source_file'],
        ],
    ]);

    return [$report, $row];
}
