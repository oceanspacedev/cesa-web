<?php

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Exports\WasteReportExport;
use Cesa\Waste\Filament\Resources\WasteReportResource;
use Cesa\Waste\Filament\Resources\WasteReportResource\Pages\EditWasteReport;
use Cesa\Waste\Filament\Resources\WasteReportResource\Pages\ListWasteReports;
use Cesa\Waste\Filament\Resources\WasteReportResource\Pages\ViewWasteReport;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteEvent;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Policies\WasteReportPolicy;
use Cesa\Waste\Services\WasteMisReviewService;
use Cesa\Waste\Services\WasteReportService;
use Database\Factories\UserFactory;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('hides the reference column and introduction on the report list', function (): void {
    Route::get('/_test/waste-reports', fn (): string => '')->name('filament.admin.resources.waste-reports.index');
    Route::get('/_test/waste-reports/create', fn (): string => '')->name('filament.admin.resources.waste-reports.create');
    Route::get('/_test/waste-reports/{record}', fn (): string => '')->name('filament.admin.resources.waste-reports.view');
    Route::get('/_test/waste-reports/{record}/edit', fn (): string => '')->name('filament.admin.resources.waste-reports.edit');
    $user = UserFactory::new()->createQuietly();
    [$brand] = wasteAdminCatalog();
    $brand->users()->attach($user);
    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $page = Livewire::test(ListWasteReports::class)
        ->assertTableColumnDoesNotExist('uid')
        ->assertDontSee('Daftar laporan form publik. Catat insiden hanya untuk koreksi di luar form.');

    expect($page->instance()->getSubheading())->toBeNull();
});

it('lets an admin create and correct a report without photos or a new approval round', function (): void {
    $user = UserFactory::new()->create();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($user);

    $service = app(WasteReportService::class);
    $report = $service->saveByAdmin(wasteAdminPayload($brand, $outlet, $item, $category), null, $user);

    expect($report->status)->toBe(WasteReportStatus::Pending)
        ->and($report->latestVersion->events)->toHaveCount(1)
        ->and($report->latestVersion->events->first()->lines->first()->quantity)->toBe('2.5000')
        ->and($report->latestVersion->events->first()->evidences()->count())->toBe(0)
        ->and($report->activityLogs()->where('event', 'admin_created')->exists())->toBeTrue()
        ->and(app(WasteReportPolicy::class)->create($user))->toBeTrue()
        ->and(app(WasteReportPolicy::class)->update($user, $report))->toBeTrue();

    $submitted = $service->submit($brand, $outlet, wasteAdminPayload($brand, $outlet, $item, $category, '1'), [
        0 => [UploadedFile::fake()->image('proof.jpg')],
    ]);
    $submitted['report']->refresh();
    $evidenceId = $submitted['report']->latestVersion->events->first()->evidences()->value('id');

    $correction = wasteAdminPayload($brand, $outlet, $item, $category, '9', WasteReportStatus::Approved->value);
    $correction['events'][0]['id'] = $submitted['report']->latestVersion->events->first()->getKey();
    $correction['events'][0]['lines'][0]['id'] = $submitted['report']->latestVersion->events->first()->lines->first()->getKey();
    $corrected = $service->saveByAdmin($correction, $submitted['report'], $user);

    expect($corrected->status)->toBe(WasteReportStatus::Pending)
        ->and($corrected->latest_version_id)->toBe($submitted['report']->latest_version_id)
        ->and($corrected->latestVersion->events->first()->lines->first()->quantity)->toBe('9.0000')
        ->and($corrected->latestVersion->events->first()->evidences()->value('id'))->toBe($evidenceId);
});

it('keeps the surviving event photo when an admin deletes the first event', function (): void {
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($user);
    $payload = wasteAdminPayload($brand, $outlet, $item, $category);
    $payload['events'][0]['reason'] = 'Kejadian pertama';
    $payload['events'][] = [
        'category_id' => $category->id,
        'reason'      => 'Kejadian kedua',
        'lines'       => [['item_id' => $item->id, 'quantity' => '3']],
    ];
    $service = app(WasteReportService::class);
    $report = $service->submit($brand, $outlet, $payload, [
        0 => [UploadedFile::fake()->image('pertama.jpg')],
        1 => [UploadedFile::fake()->image('kedua.jpg')],
    ])['report']->fresh(['latestVersion.events.lines', 'latestVersion.events.evidences']);
    [$firstEvent, $secondEvent] = $report->latestVersion->events->all();
    $firstPath = $firstEvent->evidences->sole()->path;
    $secondPath = $secondEvent->evidences->sole()->path;
    $secondEvidenceId = $secondEvent->evidences->sole()->getKey();
    Storage::disk('local')->assertExists([$firstPath, $secondPath]);

    $payload['events'] = [$payload['events'][1]];
    $payload['events'][0]['id'] = $secondEvent->getKey();
    $payload['events'][0]['lines'][0]['id'] = $secondEvent->lines->sole()->getKey();
    $updated = $service->saveByAdmin($payload, $report, $user);
    $survivor = $updated->latestVersion->events->sole();

    expect($survivor->getKey())->toBe($secondEvent->getKey())
        ->and($survivor->sequence)->toBe(0)
        ->and($survivor->reason)->toBe('Kejadian kedua')
        ->and($survivor->evidences()->sole()->getKey())->toBe($secondEvidenceId)
        ->and($survivor->evidences()->sole()->original_name)->toBe('kedua.jpg')
        ->and(WasteEvent::query()->find($firstEvent->getKey()))->toBeNull();

    Storage::disk('local')->assertMissing($firstPath);
    Storage::disk('local')->assertExists($secondPath);
});

it('does not let an admin replace a public incident with a new incident without its photo', function (): void {
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($user);
    $payload = wasteAdminPayload($brand, $outlet, $item, $category);
    $service = app(WasteReportService::class);
    $report = $service->submit($brand, $outlet, $payload, [
        0 => [UploadedFile::fake()->image('original.jpg')],
    ])['report'];
    $originalEvent = $report->latestVersion->events->first();
    $originalEvidenceId = $originalEvent->evidences()->value('id');

    $payload['events'][0]['reason'] = 'Kejadian pengganti';
    expect(fn () => $service->saveByAdmin($payload, $report, $user))->toThrow(ValidationException::class)
        ->and($report->fresh()->latestVersion->events->first()->getKey())->toBe($originalEvent->getKey())
        ->and($report->fresh()->latestVersion->events->first()->evidences()->value('id'))->toBe($originalEvidenceId);
});

it('keeps each photo with its event when an admin changes the event order', function (): void {
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($user);
    $payload = wasteAdminPayload($brand, $outlet, $item, $category);
    $payload['events'][0]['reason'] = 'Kejadian pertama';
    $payload['events'][] = [
        'category_id' => $category->id,
        'reason'      => 'Kejadian kedua',
        'lines'       => [['item_id' => $item->id, 'quantity' => '3']],
    ];
    $service = app(WasteReportService::class);
    $report = $service->submit($brand, $outlet, $payload, [
        0 => [UploadedFile::fake()->image('pertama.jpg')],
        1 => [UploadedFile::fake()->image('kedua.jpg')],
    ])['report']->fresh(['latestVersion.events.lines', 'latestVersion.events.evidences']);
    [$firstEvent, $secondEvent] = $report->latestVersion->events->all();

    foreach ([$firstEvent, $secondEvent] as $index => $savedEvent) {
        $payload['events'][$index]['id'] = $savedEvent->getKey();
        $payload['events'][$index]['lines'][0]['id'] = $savedEvent->lines->sole()->getKey();
    }
    $payload['events'] = array_reverse($payload['events']);
    $updated = $service->saveByAdmin($payload, $report, $user);

    expect($updated->latestVersion->events->pluck('id')->all())->toBe([$secondEvent->getKey(), $firstEvent->getKey()])
        ->and($updated->latestVersion->events[0]->evidences()->sole()->original_name)->toBe('kedua.jpg')
        ->and($updated->latestVersion->events[1]->evidences()->sole()->original_name)->toBe('pertama.jpg');
});

it('keeps review marks on the surviving duplicate-item line', function (): void {
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($user);
    $payload = wasteAdminPayload($brand, $outlet, $item, $category);
    $payload['events'][0]['lines'] = [
        ['item_id' => $item->id, 'quantity' => '2.5', 'sm_checked' => '0', 'audit_checked' => '1'],
        ['item_id' => $item->id, 'quantity' => '2.5', 'sm_checked' => '1', 'audit_checked' => '0'],
    ];
    $service = app(WasteReportService::class);
    $report = $service->saveByAdmin($payload, null, $user);
    $savedEvent = $report->latestVersion->events->sole();
    [$firstLine, $secondLine] = $savedEvent->lines->all();

    $payload['events'][0]['id'] = $savedEvent->getKey();
    $payload['events'][0]['lines'] = [$payload['events'][0]['lines'][1]];
    $payload['events'][0]['lines'][0]['id'] = $secondLine->getKey();
    unset($payload['events'][0]['lines'][0]['sm_checked'], $payload['events'][0]['lines'][0]['audit_checked']);
    $updated = $service->saveByAdmin($payload, $report, $user);
    $survivor = $updated->latestVersion->events->sole()->lines->sole();

    expect($survivor->getKey())->toBe($secondLine->getKey())
        ->and($survivor->sm_checked)->toBeTrue()
        ->and($survivor->audit_checked)->toBeFalse()
        ->and($firstLine->fresh())->toBeNull();
});

it('puts saved event and line IDs in the admin edit form', function (): void {
    Route::get('/_test/waste-reports', fn (): string => '')->name('filament.admin.resources.waste-reports.index');
    Route::get('/_test/waste-reports/{record}', fn (): string => '')->name('filament.admin.resources.waste-reports.view');
    Route::get('/_test/waste-reports/{record}/edit', fn (): string => '')->name('filament.admin.resources.waste-reports.edit');
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($user);
    $report = app(WasteReportService::class)->saveByAdmin(wasteAdminPayload($brand, $outlet, $item, $category), null, $user);
    $event = $report->latestVersion->events->sole();
    $line = $event->lines->sole();
    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $data = Livewire::test(EditWasteReport::class, ['record' => $report->getKey()])->get('data');
    $eventData = array_values($data['events'])[0];
    $lineData = array_values($eventData['lines'])[0];

    expect($eventData['id'])->toBe($event->getKey())
        ->and($lineData['id'])->toBe($line->getKey());
});

it('rejects event and line IDs outside the current report or repeated in one edit', function (): void {
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($user);
    $service = app(WasteReportService::class);
    $payload = wasteAdminPayload($brand, $outlet, $item, $category);
    $report = $service->saveByAdmin($payload, null, $user);
    $otherReport = $service->saveByAdmin($payload, null, $user);
    $event = $report->latestVersion->events->sole();
    $otherEvent = $otherReport->latestVersion->events->sole();
    $payload['events'][0]['id'] = $otherEvent->getKey();
    expect(fn () => $service->saveByAdmin($payload, $report, $user))->toThrow(ValidationException::class);

    $payload['events'][0]['id'] = $event->getKey();
    $payload['events'][0]['lines'][0]['id'] = $otherEvent->lines->sole()->getKey();
    expect(fn () => $service->saveByAdmin($payload, $report, $user))->toThrow(ValidationException::class);

    $payload['events'][0]['lines'][0]['id'] = $event->lines->sole()->getKey();
    $payload['events'][0]['lines'][] = $payload['events'][0]['lines'][0];
    expect(fn () => $service->saveByAdmin($payload, $report, $user))->toThrow(ValidationException::class);

    array_pop($payload['events'][0]['lines']);
    $payload['events'][] = $payload['events'][0];
    expect(fn () => $service->saveByAdmin($payload, $report, $user))->toThrow(ValidationException::class)
        ->and($report->fresh()->latestVersion->events->sole()->lines->sole()->quantity)->toBe('2.5000');
});

it('rejects IDs from an older version of the same report', function (): void {
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($user);
    $payload = wasteAdminPayload($brand, $outlet, $item, $category);
    $payload['events'][0]['lines'][0]['sm_checked'] = '0';
    $payload['events'][0]['lines'][0]['audit_checked'] = '0';
    $service = app(WasteReportService::class);
    $report = $service->saveByAdmin($payload, null, $user);
    $oldEvent = $report->latestVersion->events->sole();
    app(WasteMisReviewService::class)->approve($report, $user);

    $payload['events'][0]['id'] = $oldEvent->getKey();
    $payload['events'][0]['lines'][0]['id'] = $oldEvent->lines->sole()->getKey();
    $reopened = $service->saveByAdmin($payload, $report, $user);

    expect($reopened->latestVersion->events->sole()->getKey())->not->toBe($oldEvent->getKey())
        ->and(fn () => $service->saveByAdmin($payload, $reopened, $user))->toThrow(ValidationException::class);
});

it('reopens an approved report as a new pending version before corrected values enter monthly export', function (): void {
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($user);
    $payload = wasteAdminPayload($brand, $outlet, $item, $category);
    $payload['events'][0]['lines'][0]['sm_checked'] = '1';
    $payload['events'][0]['lines'][0]['audit_checked'] = '0';
    $service = app(WasteReportService::class);
    $report = $service->saveByAdmin($payload, null, $user);
    app(WasteMisReviewService::class)->approve($report, $user);
    $approvedVersionId = $report->latest_version_id;
    $export = new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $user);
    expect($export->hasReports())->toBeTrue();

    $payload['events'][0]['lines'][0]['quantity'] = '9';
    $corrected = $service->saveByAdmin($payload, $report, $user);
    $newLine = $corrected->latestVersion->events->first()->lines->first();
    $approvedVersion = $corrected->versions()->findOrFail($approvedVersionId);

    expect($corrected->status)->toBe(WasteReportStatus::Pending)
        ->and($corrected->latest_version_id)->not->toBe($approvedVersionId)
        ->and($corrected->approved_at)->toBeNull()
        ->and($newLine->quantity)->toBe('9.0000')
        ->and($newLine->sm_checked)->toBeNull()
        ->and($newLine->audit_checked)->toBeNull()
        ->and($approvedVersion->status)->toBe(WasteReportStatus::Approved)
        ->and($approvedVersion->events->first()->lines->first()->quantity)->toBe('2.5000')
        ->and((new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $user))->hasReports())->toBeFalse()
        ->and(fn () => app(WasteMisReviewService::class)->approve($corrected, $user))
        ->toThrow(ValidationException::class);
});

it('blocks edits to reports with external workflow approvals', function (): void {
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($user);
    $service = app(WasteReportService::class);
    $payload = wasteAdminPayload($brand, $outlet, $item, $category);
    $report = $service->saveByAdmin($payload, null, $user);
    $report->latestVersion->approvals()->create([
        'step_order'     => 1, 'label' => 'Supervisor', 'approver_name' => 'Supervisor',
        'approver_email' => 'supervisor@example.test', 'status' => 'pending',
    ]);
    $payload['events'][0]['lines'][0]['quantity'] = '9';

    expect(app(WasteReportPolicy::class)->update($user, $report))->toBeFalse()
        ->and(fn () => $service->saveByAdmin($payload, $report, $user))->toThrow(ValidationException::class)
        ->and($report->fresh()->latestVersion->events->first()->lines->first()->quantity)->toBe('2.5000');
});

it('follows the public report form on the admin create form', function (): void {
    $schema = WasteReportResource::form(Schema::make());
    $wizard = wasteFormChildren($schema)[0];

    expect($wizard)->toBeInstanceOf(Wizard::class);

    $steps = wasteFormChildren($wizard);
    $reporter = collect(wasteFormChildren($steps[0]))->map->getName()->all();
    $event = wasteFormChildren($steps[1])[0];
    $eventFields = collect(wasteFormChildren($event))->map->getName()->all();
    $lineFields = collect(wasteFormChildren(wasteFormChildren($event)[1]))->map->getName()->all();

    expect($steps[0]->getLabel())->toBe('Data pelapor')
        ->and($steps[1]->getLabel())->toBe('Barang & foto')
        ->and($reporter)->toBe(['brand_id', 'outlet_id', 'event_date', 'reporter_name', 'reporter_phone', 'reporter_email'])
        ->and($eventFields)->toBe(['id', 'lines', 'reason', 'section', 'category_id', 'pip_item_id', 'pip_quantity'])
        ->and($lineFields)->toBe(['id', 'item_id', 'quantity', 'unit', 'sm_checked', 'audit_checked']);
});

it('shows business report fields while keeping delivery diagnostics out of the report page', function (): void {
    Route::get('/_test/waste-reports', fn (): string => '')->name('filament.admin.resources.waste-reports.index');
    Route::get('/_test/waste-reports/create', fn (): string => '')->name('filament.admin.resources.waste-reports.create');
    Route::get('/_test/waste-reports/{record}', fn (): string => '')->name('filament.admin.resources.waste-reports.view');
    Route::get('/_test/waste-reports/{record}/edit', fn (): string => '')->name('filament.admin.resources.waste-reports.edit');
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($user);
    $pipItem = WasteItem::query()->create([
        'brand_id'  => $brand->getKey(),
        'code'      => 'PIP001',
        'name'      => 'PIP Ciledug',
        'unit'      => 'PCS',
        'item_type' => 'PIP',
        'is_active' => true,
    ]);
    $payload = wasteAdminPayload($brand, $outlet, $item, $category);
    $payload['events'][0]['pip_item_id'] = $pipItem->getKey();
    $payload['events'][0]['pip_quantity'] = '7';
    $report = app(WasteReportService::class)->saveByAdmin($payload, null, $user);
    $delivery = $report->notifications()->create([
        'version_id' => $report->latest_version_id,
        'channel'    => 'whatsapp',
        'type'       => 'requester_submitted',
        'recipient'  => '081299988877',
        'status'     => 'sent',
        'last_error' => 'INTERNAL_PROVIDER_TRACE',
    ]);
    $report->latestVersion->events->sole()->evidences()->create([
        'path'          => 'waste/evidence/internal-qa-photo.png',
        'original_name' => 'internal-qa-photo.png',
    ]);
    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $eventFields = collect(wasteFormChildren(wasteFormChildren(WasteReportResource::infolist(Schema::make()))[1]))
        ->flatMap(fn (Component $component): array => wasteFormChildren($component))
        ->map->getName()
        ->all();

    expect($eventFields)->toContain('pip_item_name', 'pip_quantity', 'lines');

    Livewire::test(ListWasteReports::class)
        ->assertTableColumnExists('event_date')
        ->assertTableColumnDoesNotExist('created_at');

    Livewire::test(ViewWasteReport::class, ['record' => $report->getKey()])
        ->assertSee($item->name)
        ->assertSee($pipItem->name)
        ->assertActionVisible('misApprove')
        ->assertDontSee('INTERNAL_PROVIDER_TRACE')
        ->assertDontSee('081299988877')
        ->assertDontSee('Notifikasi')
        ->assertActionHidden('retryNotifications');

    $evidenceHtml = view('waste::admin-evidence', ['report' => $report->fresh(['latestVersion.events.evidences'])])->render();
    expect($evidenceHtml)->toContain('Foto 1')->not->toContain('internal-qa-photo.png');

    $delivery->update(['status' => 'failed']);

    Livewire::test(ViewWasteReport::class, ['record' => $report->getKey()])
        ->assertActionVisible('retryNotifications')
        ->assertSee('Kirim ulang pemberitahuan')
        ->assertDontSee('INTERNAL_PROVIDER_TRACE')
        ->callAction('retryNotifications')
        ->assertActionHidden('retryNotifications');

    expect($delivery->fresh()->status)->toBe('pending');
});

it('shows a plain reporter label for QA rows while keeping their source marker in the database', function (): void {
    Route::get('/_test/waste-reports', fn (): string => '')->name('filament.admin.resources.waste-reports.index');
    Route::get('/_test/waste-reports/create', fn (): string => '')->name('filament.admin.resources.waste-reports.create');
    Route::get('/_test/waste-reports/{record}', fn (): string => '')->name('filament.admin.resources.waste-reports.view');
    Route::get('/_test/waste-reports/{record}/edit', fn (): string => '')->name('filament.admin.resources.waste-reports.edit');
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($user);
    $payload = wasteAdminPayload($brand, $outlet, $item, $category);
    $payload['reporter_name'] = 'Petugas QA TPS (USER Excel kosong)';
    $payload['reporter_phone'] = '0000000000';
    $payload['reporter_email'] = 'qa-waste-202609-jchicken-row7@example.test';
    $report = app(WasteReportService::class)->saveByAdmin($payload, null, $user);
    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    Livewire::test(ListWasteReports::class)
        ->assertCanSeeTableRecords([$report])
        ->assertSee('Tidak tercatat')
        ->assertDontSee('USER Excel kosong');

    Livewire::test(ViewWasteReport::class, ['record' => $report->getKey()])
        ->assertSee('Tidak tercatat')
        ->assertDontSee('USER Excel kosong')
        ->assertDontSee('qa-waste-202609-jchicken-row7@example.test')
        ->assertDontSee('0000000000');

    $edit = Livewire::test(EditWasteReport::class, ['record' => $report->getKey()]);
    expect($edit->get('data')['reporter_name'])->toBe(__('waste::waste.qa_reporter'))
        ->and($edit->get('data')['reporter_phone'])->toBeNull()
        ->and($edit->get('data')['reporter_email'])->toBeNull();

    $edit->call('save')->assertHasNoErrors();

    expect($report->fresh()->reporter_name)->toBe('Petugas QA TPS (USER Excel kosong)')
        ->and($report->reporter_phone)->toBe('0000000000')
        ->and($report->reporter_email)->toBe('qa-waste-202609-jchicken-row7@example.test');
});

it('refuses an admin report for an outlet outside the user scope', function (): void {
    $user = UserFactory::new()->create();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $otherBrand = WasteBrand::query()->create(['name' => 'Other', 'code' => 'OTHER', 'is_active' => true]);
    $otherBrand->users()->attach($user);

    expect(fn () => app(WasteReportService::class)->saveByAdmin(
        wasteAdminPayload($brand, $outlet, $item, $category),
        null,
        $user,
    ))->toThrow(ValidationException::class);
});

it('deletes a report and its events from the admin list', function (): void {
    $user = UserFactory::new()->create();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($user);
    $report = app(WasteReportService::class)->submit($brand, $outlet, wasteAdminPayload($brand, $outlet, $item, $category), [
        0 => [UploadedFile::fake()->image('report.jpg')],
    ])['report'];
    $eventId = $report->latestVersion->events->first()->getKey();
    $evidencePath = $report->latestVersion->events->first()->evidences()->value('path');
    Storage::disk('local')->assertExists($evidencePath);

    expect(array_keys(WasteReportResource::getPages()))->toBe(['index', 'create', 'view', 'edit'])
        ->and(app(WasteReportPolicy::class)->delete($user, $report))->toBeTrue();

    $report->delete();

    expect(WasteReport::query()->find($report->getKey()))->toBeNull()
        ->and(WasteEvent::query()->find($eventId))->toBeNull();
    Storage::disk('local')->assertMissing($evidencePath);
});

it('keeps a physical evidence file until every report reference is removed', function (): void {
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($user);
    $service = app(WasteReportService::class);
    $payload = wasteAdminPayload($brand, $outlet, $item, $category);
    $firstReport = $service->saveByAdmin($payload, null, $user);
    $secondReport = $service->saveByAdmin($payload, null, $user);
    $path = 'waste/evidence/shared.jpg';
    Storage::disk('local')->put($path, 'shared photo');

    foreach ([$firstReport, $secondReport] as $report) {
        $report->latestVersion->events->first()->evidences()->create([
            'path'          => $path,
            'original_name' => 'shared.jpg',
        ]);
    }

    $firstReport->delete();
    Storage::disk('local')->assertExists($path);

    $secondReport->delete();
    Storage::disk('local')->assertMissing($path);
});

it('does not delete an evidence file when its report deletion rolls back', function (): void {
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($user);
    $report = app(WasteReportService::class)->submit($brand, $outlet, wasteAdminPayload($brand, $outlet, $item, $category), [
        0 => [UploadedFile::fake()->image('rollback.jpg')],
    ])['report'];
    $path = $report->latestVersion->events->first()->evidences()->value('path');

    expect(fn () => DB::transaction(function () use ($report): void {
        $report->delete();
        throw new RuntimeException('Rollback');
    }))->toThrow(RuntimeException::class);

    expect(WasteReport::query()->find($report->getKey()))->not->toBeNull();
    Storage::disk('local')->assertExists($path);
});

it('protects reviewed reports from hard deletion by outlet staff and brand managers', function (): void {
    $reviewer = UserFactory::new()->createQuietly();
    $outletStaff = UserFactory::new()->createQuietly();
    [$brand, $outlet, $item, $category] = wasteAdminCatalog();
    $brand->users()->attach($reviewer);
    $outlet->users()->attach($outletStaff);
    $payload = wasteAdminPayload($brand, $outlet, $item, $category);
    $payload['events'][0]['lines'][0]['sm_checked'] = '1';
    $payload['events'][0]['lines'][0]['audit_checked'] = '0';
    $report = app(WasteReportService::class)->saveByAdmin($payload, null, $reviewer);
    $policy = app(WasteReportPolicy::class);

    expect($policy->delete($reviewer, $report))->toBeTrue()
        ->and($policy->delete($outletStaff, $report))->toBeFalse();

    app(WasteMisReviewService::class)->approve($report, $reviewer);
    expect($policy->delete($reviewer, $report->fresh()))->toBeFalse();

    $payload['events'][0]['lines'][0]['quantity'] = '9';
    $reopened = app(WasteReportService::class)->saveByAdmin($payload, $report, $reviewer);
    expect($reopened->status)->toBe(WasteReportStatus::Pending)
        ->and($policy->delete($reviewer, $reopened))->toBeFalse();
});

/**
 * @return array<int, mixed>
 */
function wasteFormChildren(object $owner): array
{
    $class = new ReflectionClass($owner);
    $property = $class->getProperty($class->hasProperty('childComponents') ? 'childComponents' : 'components');
    $value = $property->getValue($owner);

    if (is_array($value) && array_key_exists('default', $value)) {
        $value = $value['default'];
    }

    return array_values(array_filter(
        is_array($value) ? $value : [],
        fn (mixed $item): bool => $item instanceof Component,
    ));
}

/**
 * @return array{0: WasteBrand, 1: WasteOutlet, 2: WasteItem, 3: WasteCategory}
 */
function wasteAdminCatalog(): array
{
    $brand = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create(['brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG', 'slug' => 'jchicken-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
    $item = WasteItem::query()->create(['brand_id' => $brand->id, 'code' => 'B001', 'name' => 'Chicken', 'unit' => 'PCS', 'item_type' => 'bahan baku', 'is_active' => true]);
    $category = WasteCategory::query()->create(['brand_id' => $brand->id, 'code' => 'WASTE', 'name' => 'Waste', 'is_active' => true]);

    return [$brand, $outlet, $item, $category];
}

/**
 * @return array<string, mixed>
 */
function wasteAdminPayload(WasteBrand $brand, WasteOutlet $outlet, WasteItem $item, WasteCategory $category, string $quantity = '2.5', string $status = 'approved'): array
{
    return [
        'brand_id'       => $brand->id,
        'outlet_id'      => $outlet->id,
        'event_date'     => '2026-09-23',
        'status'         => $status,
        'reporter_name'  => 'Admin',
        'reporter_phone' => '081234567890',
        'events'         => [[
            'category_id' => $category->id,
            'reason'      => 'Koreksi insiden',
            'lines'       => [['item_id' => $item->id, 'quantity' => $quantity]],
        ]],
    ];
}
