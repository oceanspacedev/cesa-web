<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Cesa\Rekrutmen\Filament\Pages\RecruitmentProgressReportPage;
use Cesa\Rekrutmen\Filament\Resources\ActivityLogResource;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Filament\Resources\RoleResource;
use Webkul\Security\Models\User;

class RecruitmentAccessControlTest extends RekrutmenTestCase
{
    public function test_activity_log_and_report_page_require_history_permissions(): void
    {
        Filament::setCurrentPanel('admin');

        $userWithoutPermission = User::factory()->create([
            'is_active' => true,
        ]);

        Permission::findOrCreate('view_any_cesa::rekrutmen::models::job::application::history', 'web');
        Permission::findOrCreate('create_cesa::rekrutmen::models::job::application::history', 'web');

        $this->actingAs($userWithoutPermission);

        $this->assertFalse(ActivityLogResource::canAccess());
        $this->assertFalse(ActivityLogResource::canCreate());
        $this->assertFalse(RecruitmentProgressReportPage::canAccess());
        $this->getJson('/api/recruitment/progress-report')->assertForbidden();
        $this->getJson('/api/recruitment/progress-report/timeline')->assertForbidden();
        $this->getJson('/api/recruitment/progress-report/overview')->assertForbidden();

        $userWithoutPermission->givePermissionTo([
            'view_any_cesa::rekrutmen::models::job::application::history',
            'create_cesa::rekrutmen::models::job::application::history',
        ]);

        $this->assertTrue(ActivityLogResource::canAccess());
        $this->assertTrue(ActivityLogResource::canCreate());
        $this->assertTrue(RecruitmentProgressReportPage::canAccess());
    }

    public function test_activity_log_and_report_page_accept_resource_style_activity_permissions(): void
    {
        Filament::setCurrentPanel('admin');

        $userWithResourcePermissions = User::factory()->create([
            'is_active' => true,
        ]);

        Permission::findOrCreate('view_any_rekrutmen_activity::log', 'web');
        Permission::findOrCreate('create_rekrutmen_activity::log', 'web');

        $this->actingAs($userWithResourcePermissions);

        $this->assertFalse(ActivityLogResource::canAccess());
        $this->assertFalse(ActivityLogResource::canCreate());
        $this->assertFalse(RecruitmentProgressReportPage::canAccess());

        $userWithResourcePermissions->givePermissionTo([
            'view_any_rekrutmen_activity::log',
            'create_rekrutmen_activity::log',
        ]);

        $this->assertTrue(ActivityLogResource::canAccess());
        $this->assertTrue(ActivityLogResource::canCreate());
        $this->assertTrue(RecruitmentProgressReportPage::canAccess());
    }

    public function test_activity_log_resource_is_hidden_from_navigation(): void
    {
        $this->assertFalse(ActivityLogResource::shouldRegisterNavigation());
    }

    public function test_shield_role_form_lists_rekrutmen_resource_permissions(): void
    {
        Filament::setCurrentPanel('admin');

        $resources = RoleResource::getPluginResources();

        $this->assertArrayHasKey('Rekrutmen', $resources);

        $permissionKeys = collect(FilamentShield::getResources())
            ->filter(fn (array $entity, string $resource): bool => str_starts_with($resource, 'Cesa\\Rekrutmen\\'))
            ->flatMap(fn (array $entity): array => collect($entity['permissions'])->pluck('key'))
            ->all();

        $this->assertContains('view_any_rekrutmen_job::posting', $permissionKeys);
        $this->assertContains('view_any_rekrutmen_job::application', $permissionKeys);
        $this->assertContains('view_any_rekrutmen_request::man::power', $permissionKeys);
        $this->assertContains('view_any_rekrutmen_division', $permissionKeys);
        $this->assertContains('view_any_rekrutmen_approver', $permissionKeys);
        $this->assertContains('view_any_rekrutmen_rekrutmen::pipeline', $permissionKeys);
        $this->assertContains('view_any_rekrutmen_activity::log', $permissionKeys);
    }
}
