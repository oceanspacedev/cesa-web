<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Cesa\Rekrutmen\Enums\StatusKebutuhan;
use Cesa\Rekrutmen\Filament\Resources\RequestManPowerResource;
use Cesa\Rekrutmen\Models\Division;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Illuminate\Support\Facades\Notification;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class DivisionBusinessEntityDisplayTest extends RekrutmenTestCase
{
    public function test_configurations_api_includes_business_entity_so_duplicate_division_names_are_distinct(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $companyA = Company::query()->create(['name' => 'PT Cesa A']);
        $companyB = Company::query()->create(['name' => 'PT Cesa B']);

        $divisionA = Division::query()->create([
            'name'       => 'IT',
            'company_id' => $companyA->id,
            'is_active'  => true,
        ]);
        $divisionB = Division::query()->create([
            'name'       => 'IT',
            'company_id' => $companyB->id,
            'is_active'  => true,
        ]);

        $response = $this->getJson('/rekrutmen/api/configurations');

        $response->assertOk();

        $itDivisions = collect($response->json('divisions'))
            ->where('name', 'IT')
            ->values();

        $this->assertCount(2, $itDivisions);
        $this->assertEqualsCanonicalizing(
            ['PT Cesa A', 'PT Cesa B'],
            $itDivisions->pluck('company_name')->all()
        );
        $this->assertEqualsCanonicalizing(
            ['PT Cesa A', 'PT Cesa B'],
            $itDivisions->pluck('badan_usaha')->all()
        );
        $this->assertEqualsCanonicalizing(
            ['IT — PT Cesa A', 'IT — PT Cesa B'],
            $itDivisions->pluck('display_name')->all()
        );

        $mappedA = $itDivisions->firstWhere('company_id', $companyA->id);
        $mappedB = $itDivisions->firstWhere('company_id', $companyB->id);

        $this->assertSame($divisionA->id, $mappedA['id']);
        $this->assertSame($divisionB->id, $mappedB['id']);
        $this->assertSame($companyA->id, $mappedA['company_id']);
        $this->assertSame($companyB->id, $mappedB['company_id']);
    }

    public function test_requests_api_includes_business_entity_alongside_division(): void
    {
        Notification::fake();

        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $companyA = Company::query()->create(['name' => 'PT Alpha Selular']);
        $companyB = Company::query()->create(['name' => 'PT Beta Selular']);
        $divisionA = Division::query()->create([
            'name'       => 'HR',
            'company_id' => $companyA->id,
            'is_active'  => true,
        ]);
        $divisionB = Division::query()->create([
            'name'       => 'HR',
            'company_id' => $companyB->id,
            'is_active'  => true,
        ]);

        $this->createRequest($companyA, $divisionA, 'Staff Alpha');
        $this->createRequest($companyB, $divisionB, 'Staff Beta');

        $response = $this->getJson('/rekrutmen/api/requests');

        $response->assertOk();

        $rows = collect($response->json('data'))
            ->where('division_name', 'HR')
            ->values();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            ['PT Alpha Selular', 'PT Beta Selular'],
            $rows->pluck('company_name')->all()
        );
        $this->assertEqualsCanonicalizing(
            ['PT Alpha Selular', 'PT Beta Selular'],
            $rows->pluck('business_entity_name')->all()
        );
    }

    public function test_manpower_table_description_includes_division_and_business_entity(): void
    {
        Notification::fake();

        $company = Company::query()->create(['name' => 'CV Top Selular']);
        $division = Division::query()->create([
            'name'       => 'E-Commerce',
            'company_id' => $company->id,
            'is_active'  => true,
        ]);

        $request = $this->createRequest($company, $division, 'Admin E-Commerce');

        $description = RequestManPowerResource::formatTablePositionDescription(
            $request->fresh(['division.company', 'company'])
        );

        $this->assertStringContainsString('E-Commerce — CV Top Selular', $description);
    }

    private function createRequest(Company $company, Division $division, string $position): RequestManPower
    {
        return RequestManPower::query()->create([
            'company_id'                 => $company->id,
            'division_id'                => $division->id,
            'email_address'              => 'requester-'.$company->id.'@example.com',
            'nama_pengaju'               => 'Andi Saputra',
            'posisi_pengaju'             => 'HR Manager',
            'tanggal_pengajuan'          => '2026-03-02',
            'posisi_dibutuhkan'          => $position,
            'lokasi_penempatan'          => 'Jakarta',
            'status_kebutuhan'           => StatusKebutuhan::NEW_HIRING,
            'divisi'                     => $division->name,
            'level_pekerjaan'            => 'Staff',
            'jumlah_karyawan_dibutuhkan' => 1,
            'estimasi_tanggal_join'      => '2026-04-01',
            'requirements_kualifikasi'   => 'PHP, Laravel, SQL',
            'job_description'            => 'Develop internal systems',
            'status'                     => RequestManPowerStatus::PENDING,
        ]);
    }
}
