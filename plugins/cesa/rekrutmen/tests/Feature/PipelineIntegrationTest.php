<?php

use Cesa\Rekrutmen\Models\Division;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'is_active'           => true,
        'resource_permission' => PermissionType::INDIVIDUAL,
    ]);
    $user->givePermissionTo(collect([
        'view_any_rekrutmen_rekrutmen::pipeline',
        'view_rekrutmen_rekrutmen::pipeline',
        'create_rekrutmen_rekrutmen::pipeline',
        'update_rekrutmen_rekrutmen::pipeline',
        'delete_rekrutmen_rekrutmen::pipeline',
        'view_any_rekrutmen_job::application',
        'update_rekrutmen_job::application',
        'update_rekrutmen_job::posting',
        'view_any_rekrutmen_division',
        'create_rekrutmen_division',
        'update_rekrutmen_division',
    ])->map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'))->all());
    $this->actingAs($user);
});

it('reads configurations without creating sample pipelines or stages', function (): void {
    $pipeline = RekrutmenPipeline::query()->create(['name' => 'Existing recruitment pipeline']);
    $stage = RekrutmenStage::query()->create([
        'rekrutmen_pipeline_id' => $pipeline->id,
        'name'                  => 'Screening CV',
        'order_column'          => 1,
    ]);

    $this->getJson(route('rekrutmen.api.configurations'))
        ->assertSuccessful()
        ->assertJsonCount(1, 'pipelines')
        ->assertJsonPath('pipelines.0.id', $pipeline->id)
        ->assertJsonPath('pipelines.0.stages.0.id', $stage->id);

    $this->assertDatabaseCount('rekrutmen_pipelines', 1);
    $this->assertDatabaseCount('rekrutmen_stages', 1);
    $this->assertDatabaseMissing('rekrutmen_pipelines', ['name' => 'Pipeline Divisi IT']);
});

it('returns stages and posting pipeline identifiers across all jobs', function (): void {
    $applications = collect();

    foreach (['Operations', 'Technology'] as $name) {
        $pipeline = RekrutmenPipeline::query()->create(['name' => $name]);
        $stage = RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening '.$name,
            'order_column'          => 1,
        ]);
        $posting = JobPosting::query()->create([
            'title'                 => $name.' vacancy',
            'slug'                  => strtolower($name).'-vacancy',
            'rekrutmen_pipeline_id' => $pipeline->id,
            'is_published'          => true,
        ]);
        $applications->push(JobApplication::query()->create([
            'job_posting_id'   => $posting->id,
            'full_name'        => $name.' applicant',
            'email'            => strtolower($name).'@example.com',
            'current_stage_id' => $stage->id,
            'status'           => 'in_progress',
        ]));
    }

    $response = $this->getJson(route('rekrutmen.api.applications'))
        ->assertSuccessful()
        ->assertJsonCount(2, 'applications');

    expect(collect($response->json('stages'))->pluck('id')->all())
        ->toEqualCanonicalizing($applications->pluck('current_stage_id')->all());

    foreach ($applications as $application) {
        $row = collect($response->json('applications'))->firstWhere('id', $application->id);

        expect($row['job_posting']['rekrutmen_pipeline_id'])
            ->toBe($application->jobPosting->rekrutmen_pipeline_id);
    }
});

it('rejects moving an application into a different pipeline', function (): void {
    $pipeline = RekrutmenPipeline::query()->create(['name' => 'Operations']);
    $otherPipeline = RekrutmenPipeline::query()->create(['name' => 'Technology']);
    $stage = RekrutmenStage::query()->create([
        'rekrutmen_pipeline_id' => $pipeline->id,
        'name'                  => 'Screening CV',
        'order_column'          => 1,
    ]);
    $otherStage = RekrutmenStage::query()->create([
        'rekrutmen_pipeline_id' => $otherPipeline->id,
        'name'                  => 'Technical Assessment',
        'order_column'          => 1,
    ]);
    $posting = JobPosting::query()->create([
        'title'                 => 'Operations vacancy',
        'slug'                  => 'operations-vacancy',
        'rekrutmen_pipeline_id' => $pipeline->id,
    ]);
    $application = JobApplication::query()->create([
        'job_posting_id'   => $posting->id,
        'full_name'        => 'Operations applicant',
        'email'            => 'operations@example.com',
        'current_stage_id' => $stage->id,
        'status'           => 'in_progress',
    ]);

    $this->patchJson(route('rekrutmen.api.applications.stage', $application->id), [
        'stage_id' => $otherStage->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('stage_id');

    expect($application->fresh()->current_stage_id)->toBe($stage->id);
});

it('preserves the posting pipeline once applications exist', function (): void {
    $pipeline = RekrutmenPipeline::query()->create(['name' => 'Operations']);
    $otherPipeline = RekrutmenPipeline::query()->create(['name' => 'Technology']);
    $stage = RekrutmenStage::query()->create([
        'rekrutmen_pipeline_id' => $pipeline->id,
        'name'                  => 'Screening CV',
        'order_column'          => 1,
    ]);
    $posting = JobPosting::query()->create([
        'title'                 => 'Operations vacancy',
        'slug'                  => 'operations-vacancy',
        'rekrutmen_pipeline_id' => $pipeline->id,
    ]);
    $application = JobApplication::query()->create([
        'job_posting_id'   => $posting->id,
        'full_name'        => 'Operations applicant',
        'email'            => 'operations@example.com',
        'current_stage_id' => $stage->id,
        'status'           => 'in_progress',
    ]);

    $this->putJson(route('rekrutmen.api.job-postings.update', $posting->id), [
        'title'                 => 'Updated operations vacancy',
        'rekrutmen_pipeline_id' => $otherPipeline->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('rekrutmen_pipeline_id');

    expect($posting->fresh()->rekrutmen_pipeline_id)->toBe($pipeline->id)
        ->and($posting->fresh()->title)->toBe('Operations vacancy')
        ->and($application->fresh()->current_stage_id)->toBe($stage->id);

    $this->putJson(route('rekrutmen.api.job-postings.update', $posting->id), [
        'title'                 => 'Updated operations vacancy',
        'rekrutmen_pipeline_id' => $pipeline->id,
    ])->assertSuccessful();

    expect($posting->fresh()->title)->toBe('Updated operations vacancy');
});

it('rejects mixed pipeline stage reordering without changing either order', function (bool $includePipelineId): void {
    $pipeline = RekrutmenPipeline::query()->create(['name' => 'Operations']);
    $otherPipeline = RekrutmenPipeline::query()->create(['name' => 'Technology']);
    $stage = RekrutmenStage::query()->create([
        'rekrutmen_pipeline_id' => $pipeline->id,
        'name'                  => 'Screening CV',
        'order_column'          => 3,
    ]);
    $otherStage = RekrutmenStage::query()->create([
        'rekrutmen_pipeline_id' => $otherPipeline->id,
        'name'                  => 'Technical Assessment',
        'order_column'          => 4,
    ]);
    $payload = ['stage_ids' => [$stage->id, $otherStage->id]];

    if ($includePipelineId) {
        $payload['pipeline_id'] = $pipeline->id;
    }

    $this->postJson(route('rekrutmen.api.stages.reorder'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('stage_ids');

    expect((int) $stage->fresh()->order_column)->toBe(3)
        ->and((int) $otherStage->fresh()->order_column)->toBe(4);
})->with([
    'explicit pipeline' => true,
    'inferred pipeline' => false,
]);

it('keeps an inactive division inactive when updating its name and company', function (): void {
    $company = Company::query()->create(['name' => 'PT Original Company']);
    $otherCompany = Company::query()->create(['name' => 'PT Updated Company']);
    $division = Division::query()->create([
        'name'       => 'Operations',
        'company_id' => $company->id,
        'is_active'  => false,
    ]);

    $this->putJson(route('rekrutmen.api.divisions.update', $division->id), [
        'name'       => 'Operations Support',
        'company_id' => $otherCompany->id,
    ])->assertSuccessful()
        ->assertJsonPath('division.is_active', false)
        ->assertJsonPath('division.company_name', $otherCompany->name)
        ->assertJsonPath('division.badan_usaha', $otherCompany->name)
        ->assertJsonPath('division.display_name', 'Operations Support — PT Updated Company');

    expect($division->fresh()->is_active)->toBeFalse();

    $response = $this->getJson(route('rekrutmen.api.configurations'))->assertSuccessful();
    $row = collect($response->json('divisions'))->firstWhere('id', $division->id);

    expect($row['company_name'])->toBe($otherCompany->name)
        ->and($row['display_name'])->toBe('Operations Support — PT Updated Company')
        ->and($row['is_active'])->toBeFalse();
});

it('returns the same company display fields when creating a division', function (): void {
    $company = Company::query()->create(['name' => 'PT Complete Selular']);

    $this->postJson(route('rekrutmen.api.divisions.store'), [
        'name'       => 'Operations',
        'company_id' => $company->id,
    ])->assertSuccessful()
        ->assertJsonPath('division.company_name', $company->name)
        ->assertJsonPath('division.badan_usaha', $company->name)
        ->assertJsonPath('division.display_name', 'Operations — PT Complete Selular')
        ->assertJsonPath('division.is_active', true);
});

it('creates a pipeline with usable initial and final stages', function (): void {
    $response = $this->postJson(route('rekrutmen.api.pipelines.store'), [
        'name'        => 'Operations recruitment',
        'description' => 'Recruitment for operations vacancies',
    ])->assertCreated()->assertJsonPath('success', true);

    $pipeline = RekrutmenPipeline::query()->findOrFail($response->json('pipeline.id'));
    $stages = $pipeline->activeStages;

    expect($stages->count())->toBeGreaterThan(1)
        ->and($stages->first()->name)->toBe('Screening CV')
        ->and($stages->last()->name)->toBe('Hired')
        ->and($stages->last()->isLockedFinalStage())->toBeTrue();

    $posting = JobPosting::query()->create([
        'title'                 => 'Operations vacancy',
        'slug'                  => 'operations-vacancy',
        'rekrutmen_pipeline_id' => $pipeline->id,
    ]);
    $application = JobApplication::query()->create([
        'job_posting_id' => $posting->id,
        'full_name'      => 'Operations applicant',
        'email'          => 'operations@example.com',
        'status'         => 'in_progress',
    ]);

    expect($application->current_stage_id)->toBe($stages->first()->id);
});

it('clones only active stages while preserving the source pipeline', function (): void {
    $source = RekrutmenPipeline::query()->create(['name' => 'Source recruitment']);
    $sourceStages = collect(['Screening CV', 'Legacy assessment', 'Technical Assessment', 'Hired'])
        ->map(fn (string $name, int $index): RekrutmenStage => RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $source->id,
            'name'                  => $name,
            'order_column'          => $index + 1,
        ]));
    $sourceStages[1]->delete();

    $response = $this->postJson(route('rekrutmen.api.pipelines.store'), [
        'name'                   => 'Cloned recruitment',
        'clone_from_pipeline_id' => $source->id,
    ])->assertCreated()->assertJsonCount(3, 'pipeline.stages');

    $clone = RekrutmenPipeline::query()->findOrFail($response->json('pipeline.id'));

    expect($clone->activeStages->pluck('name')->all())
        ->toBe(['Screening CV', 'Technical Assessment', 'Hired'])
        ->and($clone->activeStages->pluck('id')->intersect($sourceStages->pluck('id')))->toBeEmpty()
        ->and($source->fresh()->activeStages->pluck('name')->all())
        ->toBe(['Screening CV', 'Technical Assessment', 'Hired']);

    $this->assertSoftDeleted('rekrutmen_stages', ['id' => $sourceStages[1]->id]);
});

it('deletes unused pipelines and preserves pipelines assigned to postings', function (): void {
    RekrutmenPipeline::query()->create(['name' => 'Standard recruitment']);
    $unused = RekrutmenPipeline::query()->create(['name' => 'Unused recruitment']);
    $unusedStage = RekrutmenStage::query()->create([
        'rekrutmen_pipeline_id' => $unused->id,
        'name'                  => 'Hired',
        'order_column'          => 1,
    ]);
    $used = RekrutmenPipeline::query()->create(['name' => 'Active recruitment']);
    $usedStage = RekrutmenStage::query()->create([
        'rekrutmen_pipeline_id' => $used->id,
        'name'                  => 'Screening CV',
        'order_column'          => 1,
    ]);
    $posting = JobPosting::query()->create([
        'title'                 => 'Operations vacancy',
        'slug'                  => 'operations-vacancy',
        'rekrutmen_pipeline_id' => $used->id,
    ]);

    $this->deleteJson(route('rekrutmen.api.pipelines.destroy', $unused->id))
        ->assertSuccessful()->assertJsonPath('success', true);

    $this->assertSoftDeleted('rekrutmen_pipelines', ['id' => $unused->id]);
    $this->assertSoftDeleted('rekrutmen_stages', ['id' => $unusedStage->id]);

    $this->deleteJson(route('rekrutmen.api.pipelines.destroy', $used->id))
        ->assertUnprocessable()->assertJsonPath('success', false);

    expect($used->fresh()->trashed())->toBeFalse()
        ->and($usedStage->fresh()->trashed())->toBeFalse()
        ->and($posting->fresh()->rekrutmen_pipeline_id)->toBe($used->id);
});

it('reorders active stages around deleted stage positions with Hired last', function (): void {
    $pipeline = RekrutmenPipeline::query()->create(['name' => 'Operations recruitment']);
    $stages = collect(['Screening CV', 'Legacy assessment', 'Interview HR', 'Hired'])
        ->map(fn (string $name, int $index): RekrutmenStage => RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => $name,
            'order_column'          => $index + 1,
        ]));
    $stages[1]->delete();

    $response = $this->postJson(route('rekrutmen.api.stages.reorder'), [
        'pipeline_id' => $pipeline->id,
        'stage_ids'   => [$stages[3]->id, $stages[2]->id, $stages[0]->id],
    ])->assertSuccessful()->assertJsonCount(3, 'stages');

    expect(collect($response->json('stages'))->pluck('id')->all())
        ->toBe([$stages[2]->id, $stages[0]->id, $stages[3]->id])
        ->and((int) $stages[2]->fresh()->order_column)->toBe(1)
        ->and((int) $stages[0]->fresh()->order_column)->toBe(3)
        ->and((int) $stages[3]->fresh()->order_column)->toBe(4);

    $this->assertSoftDeleted('rekrutmen_stages', [
        'id'           => $stages[1]->id,
        'order_column' => 2,
    ]);
});
