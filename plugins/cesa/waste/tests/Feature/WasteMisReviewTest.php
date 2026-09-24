<?php

use Cesa\Waste\Enums\WasteApprovalStatus;
use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Exports\WasteReportExport;
use Cesa\Waste\Filament\Resources\WasteReportResource\Pages\ViewWasteReport;
use Cesa\Waste\Jobs\SendWasteNotification;
use Cesa\Waste\Livewire\PublicWasteProgressPage;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteEventLine;
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

it('requires explicit SM and AUDIT decisions on every Jchicken line before MIS approval', function (): void {
    [$brand, $outlet, $report, $lines] = misReviewReport('JCHICKEN');
    $reviewer = UserFactory::new()->createQuietly();
    $brand->users()->attach($reviewer);

    expect((new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $reviewer))->hasReports())->toBeFalse();

    $service = app(WasteMisReviewService::class);
    expect(fn () => $service->approve($report, $reviewer))->toThrow(ValidationException::class);

    $lines[0]->update(['sm_checked' => false, 'audit_checked' => false]);
    $lines[1]->update(['sm_checked' => true]);
    expect(fn () => $service->approve($report, $reviewer))->toThrow(ValidationException::class);

    $lines[1]->update(['audit_checked' => false]);
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

it('requires Luuca AUDIT and allows Momoyo to be approved without review flags', function (): void {
    [$luucaBrand, , $luucaReport, $luucaLines] = misReviewReport('LUUCA');
    $reviewer = UserFactory::new()->createQuietly();
    $luucaBrand->users()->attach($reviewer);

    $service = app(WasteMisReviewService::class);
    expect(fn () => $service->approve($luucaReport, $reviewer))->toThrow(ValidationException::class);

    foreach ($luucaLines as $line) {
        $line->update(['audit_checked' => false]);
    }

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
        ->assertNotified('Laporan ditolak MIS.');

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
    [$brand, , $report, $lines] = misReviewReport('JCHICKEN');
    $reviewer = UserFactory::new()->createQuietly();
    $brand->users()->attach($reviewer);
    $this->actingAs($reviewer);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    foreach ($lines as $line) {
        $line->update(['sm_checked' => false, 'audit_checked' => false]);
    }

    Livewire::test(ViewWasteReport::class, ['record' => $report->getKey()])
        ->assertActionVisible('misApprove')
        ->assertSee('SM')
        ->assertSee('AUDIT')
        ->assertSee('FALSE')
        ->callAction('misApprove')
        ->assertNotified('Laporan disetujui MIS.')
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

    expect(fn () => $service->reject($report, $outsider, 'Perlu koreksi.'))->toThrow(ValidationException::class)
        ->and($report->fresh()->status)->toBe(WasteReportStatus::Pending)
        ->and($version->approvals()->first()->token_hash)->toBe(hash('sha256', 'external-approval-token'));
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

it('waits for every external approval then lets MIS check stable lines and release the monthly export', function (): void {
    [$brand, $outlet, $report, $lines] = misReviewReport('JCHICKEN');
    $reviewer = UserFactory::new()->createQuietly();
    $brand->users()->attach($reviewer);
    $this->actingAs($reviewer);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $version = $report->latestVersion;
    $firstToken = 'first-external-approval';
    $first = $version->approvals()->create([
        'step_order'     => 1, 'label' => 'TPS', 'approver_name' => 'Supervisor TPS',
        'approver_email' => 'supervisor@example.test', 'status' => WasteApprovalStatus::Pending,
        'token_hash'     => hash('sha256', $firstToken),
    ]);
    $second = $version->approvals()->create([
        'step_order'     => 2, 'label' => 'Manager', 'approver_name' => 'Manager',
        'approver_email' => 'manager@example.test', 'status' => WasteApprovalStatus::Waiting,
    ]);

    $mis = app(WasteMisReviewService::class);
    expect(fn () => $mis->approve($report, $reviewer))->toThrow(ValidationException::class);
    Livewire::test(ViewWasteReport::class, ['record' => $report->getKey()])
        ->assertActionHidden('misReviewLines')
        ->assertActionHidden('misApprove');

    $firstDecision = app(WasteApprovalService::class)->approve($firstToken);
    expect($firstDecision['report']->status)->toBe(WasteReportStatus::Pending)
        ->and($firstDecision['next_token'])->not->toBeNull()
        ->and(fn () => $mis->approve($report, $reviewer))->toThrow(ValidationException::class);

    $lastDecision = app(WasteApprovalService::class)->approve($firstDecision['next_token']);
    expect($lastDecision['report']->status)->toBe(WasteReportStatus::Pending)
        ->and($lastDecision['report']->latestVersion->status)->toBe(WasteReportStatus::Pending)
        ->and($lastDecision['report']->approved_at)->toBeNull()
        ->and($lastDecision['report']->notifications()->where('type', 'requester_approved')->exists())->toBeFalse()
        ->and($lastDecision['report']->activityLogs()->where('event', 'external_approved')->exists())->toBeTrue()
        ->and((new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $reviewer))->hasReports())->toBeFalse();

    expect(fn () => $mis->updateExternalLineChecks($report, $reviewer, [999999 => ['sm_checked' => true]]))
        ->toThrow(ValidationException::class);

    $outletStaff = UserFactory::new()->createQuietly();
    $outlet->users()->attach($outletStaff);
    expect(fn () => $mis->updateExternalLineChecks($report, $outletStaff, [
        $lines[0]->getKey() => ['sm_checked' => true, 'audit_checked' => true],
    ]))->toThrow(AuthorizationException::class)
        ->and(fn () => $mis->updateExternalLineChecks($report, $reviewer, [
            $lines[0]->getKey() => ['quantity' => 999],
        ]))->toThrow(ValidationException::class)
        ->and($lines[0]->fresh()->quantity)->toBe('1.0000');

    Livewire::test(ViewWasteReport::class, ['record' => $report->getKey()])
        ->mountAction('viewEvidence')
        ->assertActionMounted('viewEvidence');

    Livewire::test(ViewWasteReport::class, ['record' => $report->getKey()])
        ->assertActionVisible('viewEvidence')
        ->assertActionVisible('misReviewLines')
        ->assertActionVisible('misApprove')
        ->callAction('misReviewLines', data: [
            'line_'.$lines[0]->getKey().'_sm'    => '0',
            'line_'.$lines[0]->getKey().'_audit' => '1',
            'line_'.$lines[1]->getKey().'_sm'    => '1',
            'line_'.$lines[1]->getKey().'_audit' => '0',
        ])
        ->assertNotified('Penanda MIS per barang tersimpan.')
        ->callAction('misApprove')
        ->assertNotified('Laporan disetujui MIS.')
        ->assertActionHidden('misReviewLines')
        ->assertActionHidden('misApprove');

    expect($report->fresh()->status)->toBe(WasteReportStatus::Approved)
        ->and($first->fresh()->status)->toBe(WasteApprovalStatus::Approved)
        ->and($second->fresh()->status)->toBe(WasteApprovalStatus::Approved)
        ->and($lines[0]->fresh()->sm_checked)->toBeFalse()
        ->and($lines[0]->fresh()->audit_checked)->toBeTrue()
        ->and($lines[1]->fresh()->sm_checked)->toBeTrue()
        ->and($lines[1]->fresh()->audit_checked)->toBeFalse()
        ->and($report->activityLogs()->where('event', 'mis_line_checks_updated')->exists())->toBeTrue()
        ->and($report->notifications()->where('type', 'requester_approved')->exists())->toBeTrue()
        ->and((new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $reviewer))->hasReports())->toBeTrue();
});

it('sends a usable revision link only after MIS rejects an externally approved report', function (): void {
    [$brand, , $report, , $progressToken] = misReviewReport('MOMOYO');
    $reviewer = UserFactory::new()->createQuietly();
    $brand->users()->attach($reviewer);
    $approvalToken = 'external-momoyo-approval';
    $approval = $report->latestVersion->approvals()->create([
        'step_order'     => 1, 'label' => 'Supervisor', 'approver_name' => 'Supervisor',
        'approver_email' => 'supervisor@example.test', 'status' => WasteApprovalStatus::Pending,
        'token_hash'     => hash('sha256', $approvalToken),
    ]);

    app(WasteApprovalService::class)->approve($approvalToken);
    expect($report->notifications()->where('type', 'requester_approved')->exists())->toBeFalse();

    $rejected = app(WasteMisReviewService::class)->reject($report, $reviewer, 'Barang perlu difoto ulang.');
    $delivery = $report->notifications()->where('type', 'requester_rejected')->firstOrFail();
    $message = (string) $delivery->payload['message'];
    $revisionUrl = trim(Str::after($message, 'Revisi: '));
    $manageToken = basename((string) parse_url($revisionUrl, PHP_URL_PATH));

    expect($rejected->status)->toBe(WasteReportStatus::Rejected)
        ->and($approval->fresh()->status)->toBe(WasteApprovalStatus::Approved)
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
