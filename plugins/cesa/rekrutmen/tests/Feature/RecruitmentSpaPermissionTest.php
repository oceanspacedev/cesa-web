<?php

use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Cesa\Rekrutmen\Enums\StatusKebutuhan;
use Cesa\Rekrutmen\Models\Approver;
use Cesa\Rekrutmen\Models\Division;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Cesa\Rekrutmen\Models\RequestManPower;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Team;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

function grantSpaPermissions(User $user, string ...$names): void
{
    $user->givePermissionTo(array_map(
        static fn (string $name): Permission => Permission::findOrCreate($name, 'web'),
        $names,
    ));
}

function createSpaManPowerRequest(array $attributes = []): RequestManPower
{
    return RequestManPower::query()->create(array_merge([
        'email_address'              => fake()->safeEmail(),
        'nama_pengaju'               => 'Recruitment Manager',
        'posisi_pengaju'             => 'Manager',
        'tanggal_pengajuan'          => today(),
        'posisi_dibutuhkan'          => 'Recruiter',
        'lokasi_penempatan'          => 'Jakarta',
        'status_kebutuhan'           => StatusKebutuhan::NEW_HIRING,
        'divisi'                     => 'HR',
        'level_pekerjaan'            => 'Staff',
        'jumlah_karyawan_dibutuhkan' => 1,
        'estimasi_tanggal_join'      => today()->addMonth(),
        'requirements_kualifikasi'   => 'Recruitment experience',
        'job_description'            => 'Recruit candidates',
        'status'                     => 'pending',
    ], $attributes));
}

it('denies recruitment shell and API endpoints without recruitment permissions', function (): void {
    $this->actingAs(User::factory()->create(['is_active' => true]));

    $this->get('/rekrutmen')->assertForbidden();
    $this->get('/admin/job-postings')->assertForbidden();
    $this->getJson('/rekrutmen/api/job-postings')->assertForbidden();
    $this->postJson('/rekrutmen/api/job-postings', ['title' => 'Unauthorized'])->assertForbidden();
    $this->getJson('/rekrutmen/api/settings/mail')->assertForbidden();
    $this->postJson('/rekrutmen/api/settings/mail-templates', ['templates' => []])->assertForbidden();
});

it('sends policy abilities to Vue and keeps read-only posting users from mutating records', function (): void {
    $user = User::factory()->create([
        'is_active'           => true,
        'resource_permission' => PermissionType::GLOBAL,
    ]);
    grantSpaPermissions($user, 'view_any_rekrutmen_job::posting', 'view_rekrutmen_job::posting');
    $this->actingAs($user);

    $posting = JobPosting::query()->create([
        'title' => 'Read Only Posting',
        'slug'  => 'read-only-posting',
    ]);

    $page = $this->get('/rekrutmen')->assertOk();
    preg_match('/data-permissions="([^"]+)"/', $page->getContent(), $matches);
    $permissions = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);

    expect($permissions['jobPostings'])->toBe([
        'viewAny' => true,
        'create'  => false,
        'update'  => false,
        'delete'  => false,
    ])->and($permissions['mailSettings']['update'])->toBeFalse();

    $this->getJson('/rekrutmen/api/job-postings')
        ->assertOk()
        ->assertJsonPath('data.0.id', $posting->id)
        ->assertJsonPath('data.0.can_view', true)
        ->assertJsonPath('data.0.can_update', false)
        ->assertJsonPath('data.0.can_delete', false);

    $this->patchJson("/rekrutmen/api/job-postings/{$posting->id}/publish")->assertForbidden();
    $this->deleteJson("/rekrutmen/api/job-postings/{$posting->id}")->assertForbidden();
});

it('limits individual and team posting collections and mutations to permitted owners', function (): void {
    $viewer = User::factory()->create(['is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $colleague = User::factory()->create(['is_active' => true]);
    $outsider = User::factory()->create(['is_active' => true]);
    grantSpaPermissions(
        $viewer,
        'view_any_rekrutmen_job::posting',
        'view_rekrutmen_job::posting',
        'update_rekrutmen_job::posting',
    );

    $own = JobPosting::query()->create(['title' => 'Own Posting', 'slug' => 'own-posting', 'creator_id' => $viewer->id]);
    $teamPosting = JobPosting::query()->create(['title' => 'Team Posting', 'slug' => 'team-posting', 'creator_id' => $colleague->id]);
    $outside = JobPosting::query()->create(['title' => 'Outside Posting', 'slug' => 'outside-posting', 'creator_id' => $outsider->id]);

    $this->actingAs($viewer);
    $this->getJson('/rekrutmen/api/job-postings')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $own->id)
        ->assertJsonPath('data.0.can_update', true);
    $this->putJson("/rekrutmen/api/job-postings/{$teamPosting->id}", ['title' => 'Denied'])->assertForbidden();
    $this->putJson("/rekrutmen/api/job-postings/{$own->id}", ['title' => 'Updated Own Posting'])->assertOk();

    $team = Team::query()->create(['name' => 'Recruitment Team']);
    $team->users()->attach([$viewer->id, $colleague->id]);
    $viewer->refresh()->resource_permission = PermissionType::GROUP;
    $viewer->save();

    $this->getJson('/rekrutmen/api/job-postings')
        ->assertOk()
        ->assertJsonPath('total', 2);
    $this->putJson("/rekrutmen/api/job-postings/{$teamPosting->id}", ['title' => 'Updated Team Posting'])->assertOk();
    $this->putJson("/rekrutmen/api/job-postings/{$outside->id}", ['title' => 'Denied'])->assertForbidden();
});

it('filters configuration sections by resource permission and protects mail settings separately', function (): void {
    $viewer = User::factory()->create(['is_active' => true]);
    $outsider = User::factory()->create(['is_active' => true]);
    grantSpaPermissions($viewer, 'view_any_rekrutmen_division', 'view_rekrutmen_division');
    $this->actingAs($viewer);

    $company = Company::query()->create(['name' => 'Scope Company']);
    Division::query()->create(['name' => 'Own Division', 'company_id' => $company->id, 'creator_id' => $viewer->id]);
    Division::query()->create(['name' => 'Outside Division', 'company_id' => $company->id, 'creator_id' => $outsider->id]);

    $this->getJson('/rekrutmen/api/configurations')
        ->assertOk()
        ->assertJsonCount(1, 'divisions')
        ->assertJsonPath('divisions.0.name', 'Own Division')
        ->assertJsonCount(0, 'pipelines')
        ->assertJsonCount(0, 'approvers');
    $this->getJson('/rekrutmen/api/settings/mail')->assertForbidden();

    grantSpaPermissions($viewer, 'view_any_rekrutmen_rekrutmen::pipeline');
    $pipeline = RekrutmenPipeline::query()->create(['name' => 'Visible Pipeline']);
    JobPosting::query()->create([
        'title'                 => 'Hidden Posting Count',
        'slug'                  => 'hidden-posting-count',
        'rekrutmen_pipeline_id' => $pipeline->id,
    ]);
    $this->getJson('/rekrutmen/api/configurations')
        ->assertOk()
        ->assertJsonPath('pipelines.0.job_postings_count', 0);

    grantSpaPermissions($viewer, 'view_any_rekrutmen_approver');
    Approver::query()->create([
        'name'           => 'Visible Approver',
        'email'          => 'visible-approver@example.com',
        'phone'          => '081234567890',
        'title'          => 'Manager',
        'company_id'     => $company->id,
        'approval_order' => 1,
    ]);
    $configurationResponse = $this->getJson('/rekrutmen/api/configurations')
        ->assertOk()
        ->assertJsonPath('approvers.0.email', 'visible-approver@example.com')
        ->assertJsonPath('approvers.0.phone', null);
    expect(array_key_exists('creator', $configurationResponse->json('approvers.0')))->toBeFalse();

    grantSpaPermissions($viewer, 'manage_rekrutmen_mail');
    $this->getJson('/rekrutmen/api/settings/mail')->assertOk();
    $this->getJson('/rekrutmen/api/settings/mail-templates')->assertOk();
});

it('rejects a mixed-owner candidate batch before changing any application', function (): void {
    $viewer = User::factory()->create(['is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $outsider = User::factory()->create(['is_active' => true]);
    grantSpaPermissions(
        $viewer,
        'view_any_rekrutmen_job::application',
        'update_rekrutmen_job::application',
    );
    $this->actingAs($viewer);

    $pipeline = RekrutmenPipeline::query()->create(['name' => 'Batch Permissions']);
    $stage = RekrutmenStage::query()->create([
        'rekrutmen_pipeline_id' => $pipeline->id,
        'name'                  => 'Screening CV',
        'order_column'          => 1,
    ]);
    $posting = JobPosting::query()->create(['title' => 'Batch Job', 'slug' => 'batch-job', 'rekrutmen_pipeline_id' => $pipeline->id]);
    $own = JobApplication::query()->create([
        'job_posting_id'   => $posting->id,
        'current_stage_id' => $stage->id,
        'full_name'        => 'Own Candidate',
        'email'            => 'own-candidate@example.com',
        'status'           => 'in_progress',
        'creator_id'       => $viewer->id,
    ]);
    $foreign = JobApplication::query()->create([
        'job_posting_id'   => $posting->id,
        'current_stage_id' => $stage->id,
        'full_name'        => 'Foreign Candidate',
        'email'            => 'foreign-candidate@example.com',
        'status'           => 'in_progress',
        'creator_id'       => $outsider->id,
    ]);

    $this->postJson('/rekrutmen/api/applications/batch-reject', ['ids' => [$own->id, $foreign->id]])
        ->assertForbidden();

    expect($own->fresh()->status->value)->toBe('in_progress')
        ->and($foreign->fresh()->status->value)->toBe('in_progress');
});

it('applies the same manual approval state rule as Filament', function (): void {
    $user = User::factory()->create(['is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    grantSpaPermissions(
        $user,
        'view_any_rekrutmen_request::man::power',
        'view_rekrutmen_request::man::power',
        'update_rekrutmen_request::man::power',
    );
    $this->actingAs($user);

    $request = RequestManPower::query()->create([
        'email_address'              => 'requester@example.com',
        'nama_pengaju'               => 'Requester',
        'posisi_pengaju'             => 'Manager',
        'tanggal_pengajuan'          => today(),
        'posisi_dibutuhkan'          => 'Recruiter',
        'lokasi_penempatan'          => 'Jakarta',
        'status_kebutuhan'           => StatusKebutuhan::NEW_HIRING,
        'divisi'                     => 'HR',
        'level_pekerjaan'            => 'Staff',
        'jumlah_karyawan_dibutuhkan' => 1,
        'estimasi_tanggal_join'      => today()->addMonth(),
        'requirements_kualifikasi'   => 'Recruitment experience',
        'job_description'            => 'Recruit candidates',
        'status'                     => 'approved',
        'creator_id'                 => $user->id,
    ]);

    expect($request->fresh()->status)->toBe(RequestManPowerStatus::APPROVED)
        ->and($user->can('update_rekrutmen_request::man::power'))->toBeTrue()
        ->and($request->creator_id)->toBe($user->id)
        ->and($user->can('update', $request))->toBeTrue();

    $this->getJson('/rekrutmen/api/requests')
        ->assertOk()
        ->assertJsonPath('data.0.can_approve_reject', false)
        ->assertJsonPath('data.0.can_hold', true);
    $this->postJson("/rekrutmen/api/requests/{$request->id}/approve")->assertForbidden();
    $this->postJson("/rekrutmen/api/requests/{$request->id}/reject")->assertForbidden();
});

it('hides candidate details and request progress links from collection-only viewers', function (): void {
    $viewer = User::factory()->create(['is_active' => true, 'resource_permission' => PermissionType::GLOBAL]);
    grantSpaPermissions(
        $viewer,
        'view_any_rekrutmen_job::application',
        'view_any_rekrutmen_request::man::power',
        'view_any_rekrutmen_job::posting',
    );
    $this->actingAs($viewer);

    $posting = JobPosting::query()->create([
        'title'       => 'Private Candidate',
        'slug'        => 'private-candidate',
        'description' => 'Confidential posting details',
    ]);
    $application = JobApplication::query()->create([
        'job_posting_id'         => $posting->id,
        'full_name'              => 'Candidate Name',
        'email'                  => 'private-candidate@example.com',
        'birth_date'             => '1990-01-02',
        'address_domicile'       => 'Private street address',
        'emergency_contact_name' => 'Private emergency contact',
        'status'                 => 'in_progress',
    ]);
    $application->forceFill(['ai_screening_status' => 'completed', 'ai_summary' => 'Private AI assessment'])->save();
    $manPowerRequest = createSpaManPowerRequest(['requirements_kualifikasi' => 'Confidential requirements']);

    $this->getJson('/rekrutmen/api/applications')
        ->assertOk()
        ->assertJsonPath('applications.0.full_name', null)
        ->assertJsonPath('applications.0.email', null)
        ->assertJsonPath('applications.0.phone', null)
        ->assertJsonPath('applications.0.can_view', false)
        ->assertJsonPath('applications.0.birth_date', null)
        ->assertJsonPath('applications.0.address_domicile', null)
        ->assertJsonPath('applications.0.emergency_contact_name', null)
        ->assertJsonPath('applications.0.ai_summary', null)
        ->assertJsonPath('applications.0.resume_url', null);
    $this->getJson("/rekrutmen/api/applications/{$application->id}/cv")->assertForbidden();

    $this->getJson('/rekrutmen/api/requests')
        ->assertOk()
        ->assertJsonPath('data.0.id', $manPowerRequest->id)
        ->assertJsonPath('data.0.can_view', false)
        ->assertJsonPath('data.0.requirements_kualifikasi', null)
        ->assertJsonPath('data.0.public_progress_url', null);
    $this->getJson('/rekrutmen/api/job-postings')
        ->assertOk()
        ->assertJsonPath('data.0.can_view', false)
        ->assertJsonPath('data.0.description', null)
        ->assertJsonPath('data.0.context_description', null)
        ->assertJsonPath('data.0.thumbnail_url', null);
});

it('authorizes every linked request before changing a posting company', function (): void {
    $viewer = User::factory()->create(['is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $outsider = User::factory()->create(['is_active' => true]);
    grantSpaPermissions($viewer, 'update_rekrutmen_job::posting', 'update_rekrutmen_request::man::power');
    $this->actingAs($viewer);

    $originalCompany = Company::query()->create(['name' => 'Original Company']);
    $newCompany = Company::query()->create(['name' => 'New Company']);
    $posting = JobPosting::query()->create([
        'title'      => 'Protected Posting',
        'slug'       => 'protected-posting',
        'company_id' => $originalCompany->id,
    ]);
    $ownRequest = createSpaManPowerRequest([
        'job_posting_id' => $posting->id,
        'company_id'     => $originalCompany->id,
    ]);
    $this->actingAs($outsider);
    $foreignRequest = createSpaManPowerRequest([
        'job_posting_id' => $posting->id,
        'company_id'     => $originalCompany->id,
    ]);
    $this->actingAs($viewer);

    $this->putJson("/rekrutmen/api/job-postings/{$posting->id}", [
        'title'      => 'Denied Company Change',
        'company_id' => $newCompany->id,
    ])->assertForbidden();
    expect($posting->fresh()->title)->toBe('Protected Posting')
        ->and($posting->company_id)->toBe($originalCompany->id)
        ->and($ownRequest->fresh()->company_id)->toBe($originalCompany->id)
        ->and($foreignRequest->fresh()->company_id)->toBe($originalCompany->id);

    $this->putJson("/rekrutmen/api/job-postings/{$posting->id}", [
        'title'      => 'Allowed Title Change',
        'company_id' => $originalCompany->id,
    ])->assertOk();
    expect($posting->fresh()->title)->toBe('Allowed Title Change');

    $viewer->resource_permission = PermissionType::GLOBAL;
    $viewer->save();
    $this->putJson("/rekrutmen/api/job-postings/{$posting->id}", [
        'title'      => 'Allowed Company Change',
        'company_id' => $newCompany->id,
    ])->assertOk();
    expect($posting->fresh()->company_id)->toBe($newCompany->id)
        ->and($ownRequest->fresh()->company_id)->toBe($newCompany->id)
        ->and($foreignRequest->fresh()->company_id)->toBe($newCompany->id);
});

it('rejects a private pipeline selected on a posting write', function (): void {
    $viewer = User::factory()->create(['is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $outsider = User::factory()->create(['is_active' => true]);
    grantSpaPermissions(
        $viewer,
        'create_rekrutmen_job::posting',
        'update_rekrutmen_job::posting',
        'view_rekrutmen_rekrutmen::pipeline',
    );

    RekrutmenPipeline::query()->firstOrCreate(['id' => 1], ['name' => 'Default Recruitment Pipeline']);
    $this->actingAs($outsider);
    $privatePipeline = RekrutmenPipeline::query()->create(['name' => 'Private Pipeline']);
    $this->actingAs($viewer);

    $this->postJson('/rekrutmen/api/job-postings', [
        'title'                 => 'Denied New Posting',
        'rekrutmen_pipeline_id' => $privatePipeline->id,
    ])->assertForbidden();

    $posting = JobPosting::query()->create(['title' => 'Existing Posting', 'slug' => 'existing-posting']);
    $this->putJson("/rekrutmen/api/job-postings/{$posting->id}", [
        'title'                 => 'Denied Pipeline Change',
        'rekrutmen_pipeline_id' => $privatePipeline->id,
    ])->assertForbidden();
    expect($posting->fresh()->title)->toBe('Existing Posting')
        ->and($posting->rekrutmen_pipeline_id)->not->toBe($privatePipeline->id);
});
