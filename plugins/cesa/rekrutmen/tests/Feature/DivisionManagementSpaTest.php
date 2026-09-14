<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Models\Division;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class DivisionManagementSpaTest extends RekrutmenTestCase
{
    public function test_can_create_division_via_spa_api(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

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
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

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
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

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
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $companyA = Company::query()->create(['name' => 'Company A']);
        $companyB = Company::query()->create(['name' => 'Company B']);

        $division = Division::query()->create([
            'name'       => 'OPERATION',
            'company_id' => $companyA->id,
            'is_active'  => true,
        ]);

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
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $company = Company::query()->create(['name' => 'Company A']);

        $division = Division::query()->create([
            'name'       => 'TEMPORARY DIVISION',
            'company_id' => $company->id,
            'is_active'  => true,
        ]);

        $response = $this->deleteJson("/rekrutmen/api/divisions/{$division->id}");

        $response->assertOk();
        $this->assertTrue($response->json('success'));

        $this->assertSoftDeleted('rekrutmen_divisions', [
            'id' => $division->id,
        ]);
    }
}
