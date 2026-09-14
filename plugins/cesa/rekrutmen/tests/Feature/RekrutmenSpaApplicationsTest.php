<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Enums\JobApplicationStatus;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Cesa\Rekrutmen\Services\RecruitmentProgressReportExport;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Maatwebsite\Excel\Facades\Excel;
use Webkul\Security\Models\User;

class RekrutmenSpaApplicationsTest extends RekrutmenTestCase
{
    public function test_can_fetch_applications_via_spa_api(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $pipeline = RekrutmenPipeline::firstOrCreate(['id' => 1], ['name' => 'Default Pipeline']);
        $stage = RekrutmenStage::firstOrCreate([
            'id'                    => 1,
            'rekrutmen_pipeline_id' => $pipeline->id,
        ], [
            'name'         => 'Screening CV',
            'order_column' => 1,
        ]);

        $posting = JobPosting::create([
            'title'                 => 'Backend Developer Test',
            'slug'                  => 'backend-dev-test-'.uniqid(),
            'rekrutmen_pipeline_id' => $pipeline->id,
            'is_published'          => true,
        ]);

        $app = JobApplication::create([
            'job_posting_id'   => $posting->id,
            'full_name'        => 'Budi Santoso',
            'email'            => 'budi@example.com',
            'current_stage_id' => $stage->id,
            'status'           => 'in_progress',
        ]);

        $response = $this->getJson('/rekrutmen/api/applications');

        $response->assertOk();
        $response->assertJsonStructure([
            'stages',
            'applications',
            'total',
        ]);

        $this->assertNotEmpty($response->json('applications'));
    }

    public function test_can_fetch_applications_filtered_by_job_id(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $pipeline = RekrutmenPipeline::firstOrCreate(['id' => 1], ['name' => 'Default Pipeline']);
        $stage = RekrutmenStage::firstOrCreate([
            'id'                    => 1,
            'rekrutmen_pipeline_id' => $pipeline->id,
        ], [
            'name'         => 'Screening CV',
            'order_column' => 1,
        ]);

        $posting = JobPosting::create([
            'title'                 => 'Web App Developer Cirebon',
            'slug'                  => 'web-app-developer-cirebon-'.uniqid(),
            'location'              => 'Cirebon',
            'rekrutmen_pipeline_id' => $pipeline->id,
            'is_published'          => true,
        ]);

        JobApplication::create([
            'job_posting_id'   => $posting->id,
            'full_name'        => 'Candidate Cirebon',
            'email'            => 'cirebon@example.com',
            'address_domicile' => 'Cirebon',
            'current_stage_id' => $stage->id,
            'status'           => 'in_progress',
        ]);

        $response = $this->getJson("/rekrutmen/api/applications?job_id={$posting->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('applications'));
        $this->assertEquals($posting->id, $response->json('active_job.id'));
        $this->assertEquals('Web App Developer Cirebon', $response->json('active_job.title'));
        $this->assertNotNull($response->json('applications.0.ai_match_score'));
    }

    public function test_can_fetch_progress_report_via_spa_api(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $response = $this->getJson('/rekrutmen/api/progress-report');

        $response->assertOk();
        $response->assertJsonStructure([
            'summary',
            'positions',
            'overview',
            'timeline',
        ]);
    }

    public function test_can_export_progress_report_with_authentic_template(): void
    {
        Excel::fake();

        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $response = $this->get('/rekrutmen/api/progress-report/export');

        $response->assertOk();
        Excel::assertDownloaded('recruitment-progress-mpp-all-to-all.xlsx', function (RecruitmentProgressReportExport $export): bool {
            $sheets = $export->sheets();

            return count($sheets) === 4
                && $sheets[0]->title() === 'Overview MPP'
                && $sheets[1]->title() === 'Ringkasan Bulanan'
                && $sheets[2]->title() === 'Detail Posisi'
                && $sheets[3]->title() === 'Aktivitas Rekrutmen';
        });
    }

    public function test_can_update_application_stage_to_rejected(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $posting = JobPosting::create([
            'title'        => 'Test Job',
            'slug'         => 'test-job-'.uniqid(),
            'is_published' => true,
        ]);

        $app = JobApplication::create([
            'job_posting_id' => $posting->id,
            'full_name'      => 'Kandidat Ditolak',
            'email'          => 'ditolak@example.com',
            'status'         => 'in_progress',
        ]);

        $response = $this->patchJson("/rekrutmen/api/applications/{$app->id}/stage", [
            'stage_id' => 'rejected',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertEquals(JobApplicationStatus::REJECTED, $app->fresh()->status);
    }

    public function test_can_batch_reject_applications_without_notifications(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $posting = JobPosting::create([
            'title'        => 'Batch Reject Job',
            'slug'         => 'batch-reject-job-'.uniqid(),
            'is_published' => true,
        ]);

        $app1 = JobApplication::create([
            'job_posting_id' => $posting->id,
            'full_name'      => 'Kandidat 1',
            'email'          => 'kandidat1@example.com',
            'status'         => 'in_progress',
        ]);

        $app2 = JobApplication::create([
            'job_posting_id' => $posting->id,
            'full_name'      => 'Kandidat 2',
            'email'          => 'kandidat2@example.com',
            'status'         => 'in_progress',
        ]);

        $response = $this->postJson('/rekrutmen/api/applications/batch-reject', [
            'ids' => [$app1->id, $app2->id],
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'count'   => 2,
        ]);

        $this->assertEquals(JobApplicationStatus::REJECTED, $app1->fresh()->status);
        $this->assertEquals(JobApplicationStatus::REJECTED, $app2->fresh()->status);
    }

    public function test_requests_api_returns_aligned_fields_for_fptk_table(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $response = $this->getJson('/rekrutmen/api/requests');

        $response->assertOk();
        if (! empty($response->json('data'))) {
            $first = $response->json('data.0');
            $this->assertArrayHasKey('posisi_dibutuhkan', $first);
            $this->assertArrayHasKey('division_name', $first);
            $this->assertArrayHasKey('lokasi_penempatan', $first);
            $this->assertArrayHasKey('tanggal_pengajuan', $first);
            $this->assertArrayHasKey('fulfilled_count', $first);
            $this->assertArrayHasKey('quantity', $first);
        }
    }
}
