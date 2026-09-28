<?php

use Cesa\Waste\Enums\WasteApprovalStatus;
use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Exports\WasteReportExport;
use Cesa\Waste\Filament\Resources\WasteReportResource\Pages\ViewWasteReport;
use Cesa\Waste\Jobs\SendWasteNotification;
use Cesa\Waste\Livewire\PublicWasteProgressPage;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Services\WasteApprovalService;
use Cesa\Waste\Services\WasteMisReviewService;
use Database\Factories\UserFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    Route::get('/_test/waste-reports', fn (): string => '')->name('filament.admin.resources.waste-reports.index');
    Route::get('/_test/waste-reports/{record}', fn (): string => '')->name('filament.admin.resources.waste-reports.view');
    Route::get('/_test/waste-reports/{record}/edit', fn (): string => '')->name('filament.admin.resources.waste-reports.edit');
});

it('approves a Jchicken report without storing SM or AUDIT decisions', function (): void {
    [$brand, $outlet, $report] = misReviewReport('JCHICKEN');
    $reviewer = UserFactory::new()->createQuietly();
    $brand->users()->attach($reviewer);

    expect((new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $reviewer))->hasReports())->toBeFalse();

    $service = app(WasteMisReviewService::class);
    $approved = $service->approve($report, $reviewer);

    expect($approved->status)->toBe(WasteReportStatus::Approved)
        ->and($approved->latestVersion->status)->toBe(WasteReportStatus::Approved)
        ->and($approved->approved_at)->not->toBeNull()
        ->and($approved->rejected_at)->toBeNull()
        ->and($approved->manage_token_hash)->toBeNull()
        ->and($approved->activityLogs()->where('event', 'mis_approved')->where('actor_id', $reviewer->id)->exists())->toBeTrue()
        ->and((new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $reviewer))->hasReports())->toBeTrue()
        ->and($approved->notifications()->where('type', 'requester_approved')->count())->toBe(1);

    Queue::assertPushed(SendWasteNotification::class, 1);
    expect(fn () => $service->approve($approved, $reviewer))->toThrow(ValidationException::class);
});

it('approves Luuca and Momoyo reports without spreadsheet review columns', function (): void {
    [$luucaBrand, , $luucaReport] = misReviewReport('LUUCA');
    $reviewer = UserFactory::new()->createQuietly();
    $luucaBrand->users()->attach($reviewer);

    $service = app(WasteMisReviewService::class);
    expect($service->approve($luucaReport, $reviewer)->status)->toBe(WasteReportStatus::Approved);

    [$momoyoBrand, , $momoyoReport] = misReviewReport('MOMOYO');
    $momoyoBrand->users()->attach($reviewer);

    expect($service->approve($momoyoReport, $reviewer)->status)->toBe(WasteReportStatus::Approved);
});

it('rejects from the MIS panel with a reason and lets the reporter revise from the progress link', function (): void {
    [$brand, , $report, , $progressToken] = misReviewReport('JCHICKEN');
    $reviewer = UserFactory::new()->createQuietly();
    $brand->users()->attach($reviewer);
    $this->actingAs($reviewer);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    Livewire::test(ViewWasteReport::class, ['record' => $report->getKey()])
        ->callAction('misReject', data: ['reason' => ''])
        ->assertHasFormErrors(['reason' => 'required']);

    Livewire::test(ViewWasteReport::class, ['record' => $report->getKey()])
        ->assertActionVisible('misReject')
        ->callAction('misReject', data: ['reason' => '  Foto perlu diperjelas.  '])
        ->assertNotified('Laporan ditolak.');

    $rejected = $report->fresh('latestVersion');

    expect($rejected->status)->toBe(WasteReportStatus::Rejected)
        ->and($rejected->latestVersion->status)->toBe(WasteReportStatus::Rejected)
        ->and($rejected->latestVersion->rejection_reason)->toBe('Foto perlu diperjelas.')
        ->and($rejected->manage_token_hash)->toBe(hash('sha256', $progressToken))
        ->and($rejected->activityLogs()->where('event', 'mis_rejected')->where('actor_id', $reviewer->id)->exists())->toBeTrue()
        ->and($rejected->notifications()->where('type', 'requester_rejected')->count())->toBe(1)
        ->and($rejected->notifications()->where('type', 'requester_rejected')->firstOrFail()->payload['message'])->toContain('tautan status yang dikirim saat pengajuan');

    Queue::assertPushed(SendWasteNotification::class, 1);

    $this->app['auth']->logout();
    Livewire::test(PublicWasteProgressPage::class, ['token' => $progressToken])
        ->assertSet('revisionUrl', route('waste.public.manage', ['token' => $progressToken]))
        ->assertSee('Foto perlu diperjelas.')
        ->assertSee('Perbaiki laporan');

    $this->get(route('waste.public.manage', ['token' => $progressToken]))->assertOk();
});

it('exposes the MIS approval action only while the report awaits internal review', function (): void {
    [$brand, , $report] = misReviewReport('JCHICKEN');
    $reviewer = UserFactory::new()->createQuietly();
    $brand->users()->attach($reviewer);
    $this->actingAs($reviewer);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    Livewire::test(ViewWasteReport::class, ['record' => $report->getKey()])
        ->assertActionVisible('misApprove')
        ->assertSee('Setujui')
        ->assertDontSee('Setujui (MIS)')
        ->assertDontSee('Tolak (MIS)')
        ->callAction('misApprove')
        ->assertNotified('Laporan disetujui.')
        ->assertActionHidden('misApprove')
        ->assertActionHidden('misReject');

    expect($report->fresh()->status)->toBe(WasteReportStatus::Approved);
});

it('blocks unauthorized reviewers and does not override external approval workflows', function (): void {
    [, , $report] = misReviewReport('JCHICKEN');
    $outsider = UserFactory::new()->createQuietly();
    $service = app(WasteMisReviewService::class);

    expect(fn () => $service->reject($report, $outsider, 'Perlu koreksi.'))->toThrow(AuthorizationException::class)
        ->and($report->fresh()->status)->toBe(WasteReportStatus::Pending);

    $brand = $report->brand;
    $brand->users()->attach($outsider);
    $version = $report->latestVersion;
    $version->approvals()->create([
        'step_order'     => 1,
        'label'          => 'Supervisor',
        'approver_name'  => 'Supervisor',
        'approver_email' => 'supervisor@example.test',
        'status'         => 'pending',
        'token_hash'     => hash('sha256', 'external-approval-token'),
    ]);

    $rejected = $service->reject($report, $outsider, 'Perlu koreksi.');

    expect($rejected->status)->toBe(WasteReportStatus::Rejected)
        ->and($version->approvals()->first()->fresh()->status)->toBe(WasteApprovalStatus::Rejected)
        ->and($version->approvals()->first()->token_hash)->toBeNull();
});

it('keeps internal MIS decisions with the brand manager even when outlet staff can edit reports', function (): void {
    [$brand, $outlet, $report] = misReviewReport('JCHICKEN');
    $outletStaff = UserFactory::new()->createQuietly();
    $outlet->users()->attach($outletStaff);

    expect($outletStaff->can('update', $report))->toBeTrue()
        ->and($outletStaff->can('review', $report))->toBeFalse()
        ->and(fn () => app(WasteMisReviewService::class)->reject($report, $outletStaff, 'Ditolak.'))
        ->toThrow(AuthorizationException::class);

    $this->actingAs($outletStaff);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    Livewire::test(ViewWasteReport::class, ['record' => $report->getKey()])
        ->assertActionHidden('misApprove')
        ->assertActionHidden('misReject');

    expect($report->fresh()->status)->toBe(WasteReportStatus::Pending);
});

it('shows evidence only to admins allowed to view the current report version', function (): void {
    [$brand, , $report] = misReviewReport('JCHICKEN');
    $reviewer = UserFactory::new()->createQuietly();
    $outsider = UserFactory::new()->createQuietly();
    $brand->users()->attach($reviewer);
    config(['waste.attachments.disk' => 'local']);
    Storage::fake('local');
    $path = 'waste/evidence/review.jpg';
    Storage::disk('local')->put($path, 'test image');
    $evidence = $report->latestVersion->events->first()->evidences()->create([
        'path' => $path, 'original_name' => 'review.jpg', 'mime_type' => 'image/jpeg',
        'size' => 10, 'sha256' => hash('sha256', 'test image'),
    ]);
    $url = route('waste.admin.evidence', ['evidence' => $evidence->getKey()]);

    $this->get($url)->assertRedirect();
    $this->actingAs($outsider)->get($url)->assertForbidden();
    $this->actingAs($reviewer)->get($url)->assertOk()->assertSee('test image');

    $newVersion = $report->versions()->create([
        'version_number' => 2, 'status' => WasteReportStatus::Pending, 'workflow_snapshot' => [],
    ]);
    $report->forceFill(['latest_version_id' => $newVersion->getKey()])->save();
    $this->get($url)->assertNotFound();
});

it('lets the dashboard approve a report before whatsapp finishes the steps', function (): void {
    [$brand, $outlet, $report] = misReviewReport('JCHICKEN');
    $reviewer = UserFactory::new()->createQuietly();
    $brand->users()->attach($reviewer);
    $this->actingAs($reviewer);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $version = $report->latestVersion;
    $first = $version->approvals()->create([
        'step_order'     => 1, 'label' => 'Store Manager', 'approver_name' => 'Sari',
        'approver_phone' => '081111111111', 'status' => WasteApprovalStatus::Pending,
        'token_hash'     => hash('sha256', 'first-external-approval'),
    ]);
    $second = $version->approvals()->create([
        'step_order'     => 2, 'label' => 'Audit', 'approver_name' => 'Bima',
        'approver_phone' => '082222222222', 'status' => WasteApprovalStatus::Waiting,
    ]);

    $approved = Livewire::test(ViewWasteReport::class, ['record' => $report->getKey()])
        ->assertActionVisible('misApprove')
        ->callAction('misApprove')
        ->assertNotified('Laporan disetujui.')
        ->assertActionHidden('misApprove');

    expect($approved)->not->toBeNull()
        ->and($report->fresh()->status)->toBe(WasteReportStatus::Approved)
        ->and($first->fresh()->status)->toBe(WasteApprovalStatus::Approved)
        ->and($second->fresh()->status)->toBe(WasteApprovalStatus::Approved)
        ->and($first->fresh()->token_hash)->toBeNull()
        ->and((new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $reviewer))->hasReports())->toBeTrue();
});

it('sends a usable revision link when the dashboard rejects a report still waiting on whatsapp', function (): void {
    [$brand, , $report, , $progressToken] = misReviewReport('MOMOYO');
    $reviewer = UserFactory::new()->createQuietly();
    $brand->users()->attach($reviewer);
    $approvalToken = 'external-momoyo-approval';
    $approval = $report->latestVersion->approvals()->create([
        'step_order'     => 1, 'label' => 'Supervisor', 'approver_name' => 'Supervisor',
        'approver_email' => 'supervisor@example.test', 'status' => WasteApprovalStatus::Pending,
        'token_hash'     => hash('sha256', $approvalToken),
    ]);

    $rejected = app(WasteMisReviewService::class)->reject($report, $reviewer, 'Barang perlu difoto ulang.');
    $delivery = $report->notifications()->where('type', 'requester_rejected')->firstOrFail();
    $message = (string) $delivery->payload['message'];
    $revisionUrl = trim(Str::after($message, "Perbaiki laporan\n"));
    $manageToken = basename((string) parse_url($revisionUrl, PHP_URL_PATH));

    expect($rejected->status)->toBe(WasteReportStatus::Rejected)
        ->and($approval->fresh()->status)->toBe(WasteApprovalStatus::Rejected)
        ->and($approval->fresh()->token_hash)->toBeNull()
        ->and($rejected->progress_token_hash)->toBe(hash('sha256', $progressToken))
        ->and($rejected->manage_token_hash)->toBe(hash('sha256', $manageToken))
        ->and($rejected->manage_token_hash)->not->toBe($rejected->progress_token_hash)
        ->and($revisionUrl)->toBe(route('waste.public.manage', ['token' => $manageToken]));

    $this->get(route('waste.public.progress', ['token' => $progressToken]))->assertOk();
    $this->get($revisionUrl)->assertOk();
});

/**
 * @return array{0: WasteBrand, 1: WasteOutlet, 2: WasteReport, 3: array<int, WasteEventLine>, 4: string}
 */
function misReviewReport(string $brandCode): array
{
    $brand = WasteBrand::query()->create(['name' => ucfirst(strtolower($brandCode)), 'code' => $brandCode, 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id' => $brand->getKey(), 'name' => 'Ciledug', 'code' => 'CILEDUG',
        'slug'     => strtolower($brandCode).'-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $progressToken = Str::random(64);
    $report = WasteReport::query()->create([
        'uid'               => (string) Str::uuid(), 'brand_id' => $brand->getKey(), 'outlet_id' => $outlet->getKey(),
        'event_date'        => '2026-09-23', 'reporter_name' => 'Petugas TPS', 'reporter_phone' => '0000000000',
        'status'            => WasteReportStatus::Pending, 'progress_token_hash' => hash('sha256', $progressToken),
        'manage_token_hash' => hash('sha256', 'original-manage-token'), 'token_version' => 1,
        'submitted_at'      => now(),
    ]);
    $version = $report->versions()->create([
        'version_number' => 1, 'status' => WasteReportStatus::Pending, 'workflow_snapshot' => [],
    ]);
    $report->forceFill(['latest_version_id' => $version->getKey()])->save();
    $event = $version->events()->create([
        'sequence' => 0, 'category_name' => 'Waste', 'reason' => 'Barang rusak',
    ]);
    $lines = [];

    foreach (['B001', 'B002'] as $code) {
        $lines[] = $event->lines()->create([
            'item_code' => $code, 'item_name' => 'Barang '.$code, 'item_type' => 'Bahan Baku',
            'unit'      => 'GR', 'quantity' => 1, 'line_role' => 'direct',
        ]);
    }

    return [$brand, $outlet, $report, $lines, $progressToken];
}
