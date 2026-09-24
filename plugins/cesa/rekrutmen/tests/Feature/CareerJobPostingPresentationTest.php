<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Enums\JobApplicationGender;
use Cesa\Rekrutmen\Enums\JobApplicationMaritalStatus;
use Cesa\Rekrutmen\Enums\JobApplicationStatus;
use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Cesa\Rekrutmen\Enums\StatusKebutuhan;
use Cesa\Rekrutmen\Http\Controllers\Api\CareerController;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Illuminate\Support\Facades\Storage;
use Webkul\Support\Models\Company;

class CareerJobPostingPresentationTest extends RekrutmenTestCase
{
    public function test_job_listing_and_detail_include_thumbnail_url(): void
    {
        Storage::fake('public');

        Storage::disk('public')->put('rekrutmen/job-postings/backend-thumb.jpg', 'image');

        $jobPosting = $this->createReadyJobPosting([
            'title'          => 'Backend Developer',
            'slug'           => 'backend-developer-thumbnail',
            'description'    => 'Build APIs and internal tools.',
            'requirements'   => 'Laravel and SQL',
            'location'       => 'Jakarta',
            'thumbnail_path' => 'rekrutmen/job-postings/backend-thumb.jpg',
        ]);

        $indexPayload = $this->getJobIndexPayload();
        $detailPayload = app(CareerController::class)->show($jobPosting->slug)->getData(true);

        $this->assertStringContainsString(
            'rekrutmen/job-postings/backend-thumb.jpg',
            $indexPayload['data'][0]['thumbnail_url'] ?? ''
        );
        $this->assertStringContainsString(
            'rekrutmen/job-postings/backend-thumb.jpg',
            $detailPayload['data']['thumbnail_url'] ?? ''
        );
    }

    public function test_public_job_thumbnail_uses_recorded_s3_disk_when_a_local_copy_also_exists(): void
    {
        Storage::fake('s3');
        Storage::fake('public');

        $path = 'rekrutmen/job-postings/s3-banner.jpg';
        Storage::disk('s3')->put($path, 's3-image');
        Storage::disk('public')->put($path, 'local-image');

        $jobPosting = $this->createReadyJobPosting([
            'title'          => 'S3 Banner Developer',
            'slug'           => 's3-banner-developer',
            'thumbnail_path' => $path,
            'thumbnail_disk' => 's3',
        ]);

        $indexPayload = $this->getJobIndexPayload();
        $detailPayload = app(CareerController::class)->show($jobPosting->slug)->getData(true);
        $publicUrl = Storage::disk('public')->url($path);

        $this->assertNotNull($indexPayload['data'][0]['thumbnail_url'] ?? null);
        $this->assertNotSame($publicUrl, $indexPayload['data'][0]['thumbnail_url']);
        $this->assertStringContainsString($path, $indexPayload['data'][0]['thumbnail_url']);
        $this->assertNotNull($detailPayload['data']['thumbnail_url'] ?? null);
        $this->assertNotSame($publicUrl, $detailPayload['data']['thumbnail_url']);
        $this->assertStringContainsString($path, $detailPayload['data']['thumbnail_url']);
        $this->assertArrayNotHasKey('thumbnail_disk', $detailPayload['data']);
    }

    public function test_job_listing_is_limited_and_exposes_pagination_metadata(): void
    {
        foreach (range(1, 13) as $sequence) {
            $this->createReadyJobPosting([
                'title' => "Public Job {$sequence}",
                'slug'  => "public-job-{$sequence}",
            ]);
        }

        $payload = $this->getJobIndexPayload();

        $this->assertCount(12, $payload['data']);
        $this->assertSame(1, $payload['meta']['current_page']);
        $this->assertSame(12, $payload['meta']['per_page']);
        $this->assertTrue($payload['meta']['has_more_pages']);
        $this->assertNotNull($payload['links']['next']);
    }

    public function test_job_listing_can_be_filtered_before_pagination(): void
    {
        $matchingPosting = $this->createReadyJobPosting([
            'title'    => 'Backend API Specialist',
            'slug'     => 'backend-api-specialist-filter',
            'location' => 'Jakarta Selatan',
        ]);

        $this->createReadyJobPosting([
            'title'    => 'Sales Consultant',
            'slug'     => 'sales-consultant-filter',
            'location' => 'Bandung',
        ]);

        $payload = $this->getJobIndexPayload([
            'search'   => 'backend',
            'location' => 'Jakarta',
            'per_page' => 5,
        ]);

        $this->assertSame([$matchingPosting->slug], collect($payload['data'])->pluck('slug')->all());
        $this->assertSame('backend', $payload['meta']['filters']['search']);
        $this->assertSame('Jakarta', $payload['meta']['filters']['location']);
        $this->assertFalse($payload['meta']['has_more_pages']);
    }

    public function test_job_listing_supports_short_search_and_limit_aliases(): void
    {
        $matchingPosting = $this->createReadyJobPosting([
            'title' => 'Retail Sales Lead',
            'slug'  => 'retail-sales-lead-alias',
        ]);

        $this->createReadyJobPosting([
            'title' => 'Warehouse Coordinator',
            'slug'  => 'warehouse-coordinator-alias',
        ]);

        $payload = $this->getJobIndexPayload([
            'q'     => 'sales',
            'limit' => 1,
        ]);

        $this->assertCount(1, $payload['data']);
        $this->assertSame($matchingPosting->slug, $payload['data'][0]['slug']);
        $this->assertSame(1, $payload['meta']['per_page']);
        $this->assertSame('sales', $payload['meta']['filters']['search']);
    }

    public function test_job_listing_rejects_oversized_page_requests(): void
    {
        $this->getJson('/api/jobs?per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_expired_job_posting_is_hidden_from_listing_and_detail(): void
    {
        $jobPosting = $this->createReadyJobPosting([
            'title'        => 'Expired Backend Developer',
            'slug'         => 'expired-backend-developer',
            'description'  => 'Build APIs and internal tools.',
            'requirements' => 'Laravel and SQL',
            'location'     => 'Jakarta',
            'closing_date' => now()->subDay()->toDateString(),
        ]);

        $indexPayload = $this->getJobIndexPayload();
        $detailResponse = app(CareerController::class)->show($jobPosting->slug);

        $this->assertFalse(collect($indexPayload['data'])->contains(
            fn (array $job): bool => ($job['slug'] ?? null) === $jobPosting->slug
        ));
        $this->assertSame(404, $detailResponse->getStatusCode());

        $this->postJson("/api/jobs/{$jobPosting->slug}/apply")
            ->assertNotFound();
    }

    public function test_job_posting_remains_visible_through_its_closing_date(): void
    {
        $jobPosting = $this->createReadyJobPosting([
            'title'        => 'Closing Today Backend Developer',
            'slug'         => 'closing-today-backend-developer',
            'description'  => 'Build APIs and internal tools.',
            'requirements' => 'Laravel and SQL',
            'location'     => 'Jakarta',
            'closing_date' => today()->toDateString(),
        ]);

        $indexPayload = $this->getJobIndexPayload();
        $detailResponse = app(CareerController::class)->show($jobPosting->slug);

        $this->assertTrue(collect($indexPayload['data'])->contains(
            fn (array $job): bool => ($job['slug'] ?? null) === $jobPosting->slug
        ));
        $this->assertSame(200, $detailResponse->getStatusCode());
    }

    public function test_stage_less_job_posting_is_hidden_from_listing_and_detail(): void
    {
        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Stage Less Pipeline',
        ]);

        $jobPosting = JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Stage Less Backend Developer',
            'slug'                  => 'stage-less-backend-developer',
            'description'           => 'Build APIs and internal tools.',
            'requirements'          => 'Laravel and SQL',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        $indexPayload = $this->getJobIndexPayload();
        $detailResponse = app(CareerController::class)->show($jobPosting->slug);

        $this->assertFalse(collect($indexPayload['data'])->contains(
            fn (array $job): bool => ($job['slug'] ?? null) === $jobPosting->slug
        ));
        $this->assertSame(404, $detailResponse->getStatusCode());
    }

    public function test_held_manpower_job_posting_is_hidden_even_when_published(): void
    {
        $company = Company::query()->create([
            'name' => 'PT Hold Recruitment',
        ]);

        $requestManPower = RequestManPower::query()->create([
            'company_id'                 => $company->id,
            'email_address'              => 'hold-request@example.com',
            'nama_pengaju'               => 'Requester Hold',
            'posisi_pengaju'             => 'HR Manager',
            'tanggal_pengajuan'          => today()->toDateString(),
            'posisi_dibutuhkan'          => 'Backend Developer',
            'lokasi_penempatan'          => 'Jakarta',
            'status_kebutuhan'           => StatusKebutuhan::NEW_HIRING,
            'divisi'                     => 'IT',
            'level_pekerjaan'            => 'Staff',
            'jumlah_karyawan_dibutuhkan' => 1,
            'estimasi_tanggal_join'      => today()->addMonth()->toDateString(),
            'requirements_kualifikasi'   => 'Laravel',
            'job_description'            => 'Build APIs',
            'status'                     => RequestManPowerStatus::HOLD,
        ]);

        $jobPosting = $this->createReadyJobPosting([
            'request_man_power_id' => $requestManPower->id,
            'title'                => 'Held Backend Developer',
            'slug'                 => 'held-backend-developer',
        ]);

        $requestManPower->forceFill([
            'job_posting_id' => null,
        ])->saveQuietly();

        $indexPayload = $this->getJobIndexPayload();
        $detailResponse = app(CareerController::class)->show($jobPosting->slug);

        $this->assertFalse(collect($indexPayload['data'])->contains(
            fn (array $job): bool => ($job['slug'] ?? null) === $jobPosting->slug
        ));
        $this->assertSame(404, $detailResponse->getStatusCode());
    }

    public function test_fulfilled_job_posting_is_hidden_from_public_listing_and_apply(): void
    {
        $jobPosting = $this->createReadyJobPosting([
            'title' => 'Fulfilled Backend Developer',
            'slug'  => 'fulfilled-backend-developer',
        ]);

        $stage = RekrutmenStage::query()
            ->where('rekrutmen_pipeline_id', $jobPosting->rekrutmen_pipeline_id)
            ->firstOrFail();

        $this->createJobApplication(
            $jobPosting,
            $stage,
            JobApplicationStatus::HIRED,
            'fulfilled-candidate@example.com',
        );

        $indexPayload = $this->getJobIndexPayload();
        $detailResponse = app(CareerController::class)->show($jobPosting->slug);

        $this->assertFalse(collect($indexPayload['data'])->contains(
            fn (array $job): bool => ($job['slug'] ?? null) === $jobPosting->slug
        ));
        $this->assertSame(404, $detailResponse->getStatusCode());

        $this->postJson("/api/jobs/{$jobPosting->slug}/apply")
            ->assertNotFound();
    }

    public function test_shared_job_posting_stays_public_when_another_linked_mpp_is_approved(): void
    {
        $company = Company::query()->create([
            'name' => 'PT Shared Public Vacancy',
        ]);

        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Shared Public Pipeline',
        ]);

        RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening',
            'order_column'          => 1,
        ]);

        $sourceRequest = RequestManPower::query()->create([
            'company_id'                 => $company->id,
            'email_address'              => 'source-hold-public@example.com',
            'nama_pengaju'               => 'Requester Hold',
            'posisi_pengaju'             => 'HR Manager',
            'tanggal_pengajuan'          => today()->toDateString(),
            'posisi_dibutuhkan'          => 'Backend Developer',
            'lokasi_penempatan'          => 'Jakarta',
            'status_kebutuhan'           => StatusKebutuhan::NEW_HIRING,
            'divisi'                     => 'IT',
            'level_pekerjaan'            => 'Staff',
            'jumlah_karyawan_dibutuhkan' => 1,
            'estimasi_tanggal_join'      => today()->addMonth()->toDateString(),
            'requirements_kualifikasi'   => 'Laravel',
            'job_description'            => 'Build APIs',
            'status'                     => RequestManPowerStatus::HOLD,
        ]);

        $jobPosting = JobPosting::query()->create([
            'request_man_power_id'  => $sourceRequest->id,
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Shared Backend Developer',
            'slug'                  => 'shared-backend-developer',
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
        ]);

        RequestManPower::query()->create([
            'company_id'                 => $company->id,
            'email_address'              => 'approved-public@example.com',
            'nama_pengaju'               => 'Requester Approved',
            'posisi_pengaju'             => 'HR Manager',
            'tanggal_pengajuan'          => today()->toDateString(),
            'posisi_dibutuhkan'          => 'Backend Developer',
            'lokasi_penempatan'          => 'Jakarta',
            'status_kebutuhan'           => StatusKebutuhan::NEW_HIRING,
            'divisi'                     => 'IT',
            'level_pekerjaan'            => 'Staff',
            'jumlah_karyawan_dibutuhkan' => 1,
            'estimasi_tanggal_join'      => today()->addMonth()->toDateString(),
            'requirements_kualifikasi'   => 'Laravel',
            'job_description'            => 'Build APIs',
            'status'                     => RequestManPowerStatus::APPROVED,
            'job_posting_id'             => $jobPosting->id,
        ]);

        $indexPayload = $this->getJobIndexPayload();
        $detailResponse = app(CareerController::class)->show($jobPosting->slug);

        $this->assertTrue(collect($indexPayload['data'])->contains(
            fn (array $job): bool => ($job['slug'] ?? null) === $jobPosting->slug
        ));
        $this->assertSame(200, $detailResponse->getStatusCode());
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function getJobIndexPayload(array $query = []): array
    {
        $uri = '/api/jobs';

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->getJson($uri)
            ->assertOk()
            ->json();
    }

    private function createReadyJobPosting(array $attributes): JobPosting
    {
        $pipeline = RekrutmenPipeline::query()->create([
            'name' => 'Presentation Pipeline '.str()->lower(str()->random(5)),
        ]);

        RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Screening',
            'order_column'          => 1,
        ]);

        return JobPosting::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'title'                 => 'Backend Developer',
            'slug'                  => 'backend-developer',
            'description'           => 'Build APIs',
            'requirements'          => 'Laravel',
            'location'              => 'Jakarta',
            'is_published'          => true,
            ...$attributes,
        ]);
    }

    private function createJobApplication(
        JobPosting $jobPosting,
        RekrutmenStage $stage,
        JobApplicationStatus $status,
        string $email,
    ): JobApplication {
        $phoneNumber = '081'.str_pad((string) (abs(crc32($email)) % 1000000000), 9, '0', STR_PAD_LEFT);

        return JobApplication::query()->create([
            'job_posting_id'             => $jobPosting->id,
            'current_stage_id'           => $stage->id,
            'full_name'                  => 'Fulfilled Candidate',
            'email'                      => $email,
            'gender'                     => JobApplicationGender::Male,
            'birth_date'                 => '1995-01-10',
            'marital_status'             => JobApplicationMaritalStatus::Single,
            'address_ktp'                => 'Alamat KTP',
            'address_domicile'           => 'Alamat Domisili',
            'whatsapp_number'            => $phoneNumber,
            'active_phone'               => $phoneNumber,
            'emergency_contact_name'     => 'Bunga',
            'emergency_contact_relation' => 'Saudara',
            'emergency_contact_phone'    => $phoneNumber,
            'status'                     => $status,
        ]);
    }
}
