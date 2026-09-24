<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Models\Division;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class DivisionManagementSpaTest extends RekrutmenTestCase
{
    private User $divisionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->divisionManager = User::factory()->create([
            'is_active'           => true,
            'resource_permission' => PermissionType::INDIVIDUAL,
        ]);
        $this->divisionManager->givePermissionTo([
            Permission::findOrCreate('create_rekrutmen_division', 'web'),
            Permission::findOrCreate('update_rekrutmen_division', 'web'),
            Permission::findOrCreate('delete_rekrutmen_division', 'web'),
        ]);
        $this->actingAs($this->divisionManager);
    }

    public function test_can_create_division_via_spa_api(): void
    {
        $company = Company::query()->create(['name' => 'CV Test Company']);

        $response = $this->postJson('/rekrutmen/api/divisions', [
            'name'       => 'LOGISTIC & WAREHOUSE',
            'company_id' => $company->id,
            'is_active'  => true,
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('success'));
        $this->assertSame('LOGISTIC & WAREHOUSE', $response->json('division.name'));
        $this->assertSame($company->id, $response->json('division.company_id'));
        $this->assertSame('CV Test Company', $response->json('division.company_name'));

        $this->assertDatabaseHas('rekrutmen_divisions', [
            'name'       => 'LOGISTIC & WAREHOUSE',
            'company_id' => $company->id,
            'is_active'  => true,
        ]);
    }

    public function test_cannot_create_duplicate_division_for_same_company(): void
    {
        $company = Company::query()->create(['name' => 'CV Test Company']);

        Division::query()->create([
            'name'       => 'MARKETING',
            'company_id' => $company->id,
            'is_active'  => true,
        ]);

        $response = $this->postJson('/rekrutmen/api/divisions', [
            'name'       => 'marketing',
            'company_id' => $company->id,
            'is_active'  => true,
        ]);

        $response->assertStatus(422);
    }

    public function test_can_create_same_division_name_for_different_companies(): void
    {
        $companyA = Company::query()->create(['name' => 'Company A']);
        $companyB = Company::query()->create(['name' => 'Company B']);

        Division::query()->create([
            'name'       => 'ONLINE',
            'company_id' => $companyA->id,
            'is_active'  => true,
        ]);

        $response = $this->postJson('/rekrutmen/api/divisions', [
            'name'       => 'ONLINE',
            'company_id' => $companyB->id,
            'is_active'  => true,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('rekrutmen_divisions', [
            'name'       => 'ONLINE',
            'company_id' => $companyB->id,
        ]);
    }

    public function test_can_update_division_via_spa_api(): void
    {
        $companyA = Company::query()->create(['name' => 'Company A']);
        $companyB = Company::query()->create(['name' => 'Company B']);

        $division = Division::query()->create([
            'name'       => 'OPERATION',
            'company_id' => $companyA->id,
            'is_active'  => true,
            'creator_id' => $this->divisionManager->id,
        ]);

        $this->assertTrue($this->divisionManager->can('update', $division));
        $response = $this->putJson("/rekrutmen/api/divisions/{$division->id}", [
            'name'       => 'OPERATION & MAINTENANCE',
            'company_id' => $companyB->id,
            'is_active'  => false,
        ]);

        $response->assertOk();
        $this->assertSame('OPERATION & MAINTENANCE', $response->json('division.name'));
        $this->assertSame($companyB->id, $response->json('division.company_id'));
        $this->assertFalse($response->json('division.is_active'));

        $this->assertDatabaseHas('rekrutmen_divisions', [
            'id'         => $division->id,
            'name'       => 'OPERATION & MAINTENANCE',
            'company_id' => $companyB->id,
            'is_active'  => false,
        ]);
    }

    public function test_can_delete_division_via_spa_api(): void
    {
        $company = Company::query()->create(['name' => 'Company A']);

        $division = Division::query()->create([
            'name'       => 'TEMPORARY DIVISION',
            'company_id' => $company->id,
            'is_active'  => true,
            'creator_id' => $this->divisionManager->id,
        ]);

        $this->assertTrue($this->divisionManager->can('delete', $division));
        $response = $this->deleteJson("/rekrutmen/api/divisions/{$division->id}");

        $response->assertOk();
        $this->assertTrue($response->json('success'));

        $this->assertSoftDeleted('rekrutmen_divisions', [
            'id' => $division->id,
        ]);
    }
}
