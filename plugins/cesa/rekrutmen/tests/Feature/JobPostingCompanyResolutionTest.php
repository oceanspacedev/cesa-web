<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Cesa\Rekrutmen\Enums\StatusKebutuhan;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class JobPostingCompanyResolutionTest extends RekrutmenTestCase
{
    protected function createSampleRequest(array $attributes = []): RequestManPower
    {
        return RequestManPower::query()->create(array_merge([
            'email_address'              => 'requester@example.com',
            'nama_pengaju'               => 'Requester Top',
            'posisi_pengaju'             => 'Manager',
            'tanggal_pengajuan'          => now()->toDateString(),
            'posisi_dibutuhkan'          => 'ADMIN E-COMMERCE PRIMA CENTER',
            'lokasi_penempatan'          => 'PRIMA CENTER - JAKARTA',
            'status_kebutuhan'           => StatusKebutuhan::NEW_HIRING,
            'divisi'                     => 'E-Commerce',
            'level_pekerjaan'            => 'Staff',
            'jumlah_karyawan_dibutuhkan' => 1,
            'estimasi_tanggal_join'      => now()->addMonth()->toDateString(),
            'requirements_kualifikasi'   => 'Admin skills',
            'job_description'            => 'Handle orders',
            'status'                     => RequestManPowerStatus::APPROVED,
        ], $attributes));
    }

    public function test_job_posting_resolves_company_from_linked_request(): void
    {
        $pipeline = RekrutmenPipeline::firstOrCreate(['id' => 1], ['name' => 'Default Pipeline']);

        $companyTop = Company::query()->create(['name' => 'CV Top Selular']);

        $request1 = $this->createSampleRequest([
            'company_id' => $companyTop->id,
        ]);

        $posting1 = JobPosting::create([
            'title'                 => 'ADMIN E-COMMERCE PRIMA CENTER',
            'slug'                  => 'admin-ecommerce-prima-center',
            'location'              => 'PRIMA CENTER - JAKARTA',
            'request_man_power_id'  => $request1->id,
            'rekrutmen_pipeline_id' => $pipeline->id,
            'is_published'          => true,
        ]);

        $this->assertSame('CV Top Selular', $posting1->resolveCompanyName());
        $this->assertSame('CV Top Selular', $posting1->company_name);

        // Job posting without MPP links falls back to default
        $postingUnlinked = JobPosting::create([
            'title'                 => 'Standalone Job',
            'slug'                  => 'standalone-job',
            'rekrutmen_pipeline_id' => $pipeline->id,
            'is_published'          => true,
        ]);

        $this->assertSame('PT Complete Selular Group', $postingUnlinked->resolveCompanyName());
    }

    public function test_get_job_postings_api_returns_company_name_and_allows_search(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $pipeline = RekrutmenPipeline::firstOrCreate(['id' => 1], ['name' => 'Default Pipeline']);
        $company = Company::query()->create(['name' => 'CV Top Selular']);

        $request = $this->createSampleRequest([
            'company_id' => $company->id,
        ]);

        $posting = JobPosting::create([
            'title'                 => 'ADMIN E-COMMERCE PRIMA CENTER',
            'slug'                  => 'admin-ecommerce-prima-center-'.uniqid(),
            'location'              => 'PRIMA CENTER - JAKARTA',
            'request_man_power_id'  => $request->id,
            'rekrutmen_pipeline_id' => $pipeline->id,
            'is_published'          => true,
        ]);

        $response = $this->getJson('/rekrutmen/api/job-postings');
        $response->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', $posting->id);
        $this->assertNotNull($item);
        $this->assertSame('CV Top Selular', $item['company_name']);

        // Test search by company name
        $searchResponse = $this->getJson('/rekrutmen/api/job-postings?search=Top+Selular');
        $searchResponse->assertOk();
        $this->assertNotEmpty($searchResponse->json('data'));
        $this->assertSame($posting->id, $searchResponse->json('data.0.id'));

        // Test companies list present in response
        $this->assertNotEmpty($response->json('companies'));
    }

    public function test_can_update_company_in_job_posting_via_api(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $pipeline = RekrutmenPipeline::firstOrCreate(['id' => 1], ['name' => 'Default Pipeline']);
        $companyTop = Company::query()->create(['name' => 'CV Top Selular']);
        $companyMedia = Company::query()->create(['name' => 'PT Media Selular Indonesia']);

        $request = $this->createSampleRequest([
            'company_id' => $companyTop->id,
        ]);

        $posting = JobPosting::create([
            'title'                 => 'ADMIN E-COMMERCE PRIMA CENTER',
            'slug'                  => 'admin-ecommerce-prima-center-'.uniqid(),
            'location'              => 'PRIMA CENTER - JAKARTA',
            'company_id'            => $companyTop->id,
            'request_man_power_id'  => $request->id,
            'rekrutmen_pipeline_id' => $pipeline->id,
            'is_published'          => true,
        ]);

        $this->assertSame('CV Top Selular', $posting->resolveCompanyName());

        // Update company to PT Media Selular Indonesia
        $updateResponse = $this->putJson("/rekrutmen/api/job-postings/{$posting->id}", [
            'title'      => 'ADMIN E-COMMERCE PRIMA CENTER',
            'company_id' => $companyMedia->id,
            'location'   => 'PRIMA CENTER - JAKARTA',
        ]);

        $updateResponse->assertOk();
        $this->assertSame('PT Media Selular Indonesia', $updateResponse->json('posting.company_name'));
        $this->assertSame($companyMedia->id, $updateResponse->json('posting.company_id'));

        $this->assertSame($companyMedia->id, $posting->fresh()->company_id);
        $this->assertSame('PT Media Selular Indonesia', $posting->fresh()->resolveCompanyName());

        // Linked request is also synced
        $this->assertSame($companyMedia->id, $request->fresh()->company_id);
    }

    public function test_can_fetch_companies_api(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        Company::query()->create(['name' => 'PT Test Entity']);

        $response = $this->getJson('/rekrutmen/api/companies');
        $response->assertOk();
        $this->assertNotEmpty($response->json());
        $this->assertTrue(collect($response->json())->contains('name', 'PT Test Entity'));
    }
}
