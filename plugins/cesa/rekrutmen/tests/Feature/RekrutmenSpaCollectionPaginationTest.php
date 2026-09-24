<?php

use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Cesa\Rekrutmen\Enums\StatusKebutuhan;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Services\RekrutmenStorage;
use Cesa\Rekrutmen\Services\ScheduledNotificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
    Queue::fake();
    $this->mock(ScheduledNotificationService::class)
        ->shouldReceive('processDueNotifications')
        ->andReturn(0);
    $user = User::factory()->create([
        'is_active'           => true,
        'resource_permission' => PermissionType::INDIVIDUAL,
    ]);
    $user->givePermissionTo([
        Permission::findOrCreate('view_any_rekrutmen_request::man::power', 'web'),
        Permission::findOrCreate('view_any_rekrutmen_job::application', 'web'),
    ]);
    $this->actingAs($user);
    $this->freezeTime();
});

it('returns every matching manpower request across pages and finds older requests', function (): void {
    $createRequest = fn (string $position): RequestManPower => RequestManPower::query()->create([
        'email_address'              => 'requester-'.md5($position).'@example.com',
        'nama_pengaju'               => 'Andi Saputra',
        'posisi_pengaju'             => 'HR Manager',
        'tanggal_pengajuan'          => today(),
        'posisi_dibutuhkan'          => $position,
        'lokasi_penempatan'          => 'Jakarta',
        'status_kebutuhan'           => StatusKebutuhan::NEW_HIRING,
        'divisi'                     => 'Sales',
        'level_pekerjaan'            => 'Staff',
        'jumlah_karyawan_dibutuhkan' => 1,
        'estimasi_tanggal_join'      => today()->addMonth(),
        'requirements_kualifikasi'   => 'Pengalaman penjualan',
        'job_description'            => 'Melayani pelanggan',
        'status'                     => RequestManPowerStatus::PENDING,
    ]);
    $requestIds = collect();

    foreach (range(1, 451) as $number) {
        $requestIds->push($createRequest('PaginationBatch Sales '.$number)->getKey());
    }

    $createRequest('Unrelated position');
    $createRequest('PaginationBatch deleted request')->delete();
    $returnedIds = collect();

    foreach (range(1, 5) as $page) {
        $response = $this->getJson(route('rekrutmen.api.requests', [
            'search'   => 'PaginationBatch',
            'per_page' => 100,
            'page'     => $page,
        ]))
            ->assertSuccessful()
            ->assertJsonPath('current_page', $page)
            ->assertJsonPath('per_page', 100)
            ->assertJsonPath('total', 451)
            ->assertJsonPath('last_page', 5)
            ->assertJsonCount($page === 5 ? 51 : 100, 'data');

        $returnedIds->push(...array_column($response->json('data'), 'id'));
    }

    expect($returnedIds->all())->toBe($requestIds->reverse()->values()->all())
        ->and($returnedIds->unique())->toHaveCount(451);

    $firstPage = $this->getJson(route('rekrutmen.api.requests'))
        ->assertSuccessful()
        ->assertJsonPath('total', 452)
        ->assertJsonCount(50, 'data');

    expect(array_column($firstPage->json('data'), 'id'))->not->toContain($requestIds->first());

    $this->getJson(route('rekrutmen.api.requests', ['search' => '  PaginationBatch Sales 1  ']))
        ->assertSuccessful()
        ->assertJsonPath('total', 111)
        ->assertJsonPath('last_page', 3);

    $this->getJson(route('rekrutmen.api.requests', [
        'search'   => 'PaginationBatch Sales 1',
        'per_page' => 100,
        'page'     => 2,
    ]))
        ->assertSuccessful()
        ->assertJsonPath('data.10.id', $requestIds->first());

    $this->getJson(route('rekrutmen.api.requests', ['per_page' => 1000]))
        ->assertSuccessful()
        ->assertJsonPath('per_page', 100)
        ->assertJsonPath('total', 452)
        ->assertJsonCount(100, 'data');
});

it('returns all applications across pages with job and pipeline filters and a true total', function (): void {
    $pipeline = RekrutmenPipeline::query()->create(['name' => 'Sales Pipeline']);
    $stage = RekrutmenStage::query()->create([
        'rekrutmen_pipeline_id' => $pipeline->getKey(),
        'name'                  => 'Screening CV',
        'order_column'          => 1,
    ]);
    $posting = JobPosting::query()->create([
        'title'                 => 'Sales Bandung',
        'slug'                  => 'pagination-sales-bandung',
        'rekrutmen_pipeline_id' => $pipeline->getKey(),
        'is_published'          => true,
    ]);
    $otherPipeline = RekrutmenPipeline::query()->create(['name' => 'Courier Pipeline']);
    $otherPosting = JobPosting::query()->create([
        'title'                 => 'Courier Jakarta',
        'slug'                  => 'pagination-courier-jakarta',
        'rekrutmen_pipeline_id' => $otherPipeline->getKey(),
        'is_published'          => true,
    ]);
    $applicationIds = collect();

    foreach (range(1, 451) as $number) {
        $application = JobApplication::query()->create([
            'job_posting_id'   => $number === 451 ? $otherPosting->getKey() : $posting->getKey(),
            'current_stage_id' => $number === 451 ? null : $stage->getKey(),
            'full_name'        => $number === 1 ? 'Older Applicant Needle' : 'Applicant '.$number,
            'email'            => 'pagination-applicant-'.$number.'@example.com',
            'status'           => 'in_progress',
        ]);

        $applicationIds->push($application->getKey());
    }

    JobApplication::query()->create([
        'job_posting_id'   => $posting->getKey(),
        'current_stage_id' => $stage->getKey(),
        'full_name'        => 'Deleted Applicant Needle',
        'email'            => 'pagination-deleted-applicant@example.com',
        'status'           => 'in_progress',
    ])->delete();

    $this->mock(RekrutmenStorage::class)
        ->shouldReceive('findCandidateResume')
        ->andReturnNull();

    foreach ([
        ['filters' => [], 'ids' => $applicationIds, 'active_job' => null],
        ['filters' => ['job_id' => $posting->getKey()], 'ids' => $applicationIds->take(450), 'active_job' => $posting->getKey()],
        ['filters' => ['pipeline_id' => $pipeline->getKey()], 'ids' => $applicationIds->take(450), 'active_job' => null],
    ] as $scenario) {
        $returnedIds = collect();

        foreach (range(1, 5) as $page) {
            $response = $this->getJson(route('rekrutmen.api.applications', [
                ...$scenario['filters'],
                'page' => $page,
            ]))
                ->assertSuccessful()
                ->assertJsonPath('current_page', $page)
                ->assertJsonPath('per_page', 100)
                ->assertJsonPath('last_page', 5)
                ->assertJsonPath('total', $scenario['ids']->count())
                ->assertJsonCount($page === 5 ? $scenario['ids']->count() - 400 : 100, 'applications')
                ->assertJsonPath('stages.0.id', $stage->getKey());

            if ($scenario['active_job'] === null) {
                $response->assertJsonPath('active_job', null);
            } else {
                $response->assertJsonPath('active_job.id', $scenario['active_job']);
            }

            $returnedIds->push(...array_column($response->json('applications'), 'id'));
        }

        expect($returnedIds->all())->toBe($scenario['ids']->reverse()->values()->all())
            ->and($returnedIds->unique())->toHaveCount($scenario['ids']->count());
    }

    $firstPage = $this->getJson(route('rekrutmen.api.applications'))
        ->assertSuccessful()
        ->assertJsonPath('total', 451);

    expect(array_column($firstPage->json('applications'), 'id'))->not->toContain($applicationIds->first());

    foreach ([[], ['job_id' => $posting->getKey()], ['pipeline_id' => $pipeline->getKey()]] as $filters) {
        $this->getJson(route('rekrutmen.api.applications', [...$filters, 'search' => '  Applicant Needle  ']))
            ->assertSuccessful()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('last_page', 1)
            ->assertJsonPath('applications.0.id', $applicationIds->first())
            ->assertJsonCount(1, 'applications');
    }

    $this->getJson(route('rekrutmen.api.applications', ['per_page' => 1000]))
        ->assertSuccessful()
        ->assertJsonPath('per_page', 100)
        ->assertJsonPath('total', 451)
        ->assertJsonCount(100, 'applications');
});
