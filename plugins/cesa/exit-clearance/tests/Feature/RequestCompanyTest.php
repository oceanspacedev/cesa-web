<?php

namespace Cesa\ExitClearance\Tests\Feature;

use App\Models\User;
use Cesa\ExitClearance\Filament\Resources\RequestResource\Pages\CreateRequest;
use Cesa\ExitClearance\Filament\Resources\RequestResource\Pages\EditRequest;
use Cesa\ExitClearance\Filament\Resources\RequestResource\Pages\ListRequests;
use Cesa\ExitClearance\Filament\Resources\RequestResource\Pages\ViewRequest;
use Cesa\ExitClearance\Livewire\PublicExitClearanceRequestForm;
use Cesa\ExitClearance\Models\Approver;
use Cesa\ExitClearance\Models\Department;
use Cesa\ExitClearance\Models\Request;
use Cesa\ExitClearance\Services\ExitClearanceRequestService;
use Cesa\ExitClearance\Tests\ExitClearanceTestCase;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User as SecurityUser;
use Webkul\Support\Models\Company;

class RequestCompanyTest extends ExitClearanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Queue::fake();
        config()->set('exit-clearance.security.recaptcha.enabled', false);

        $this->registerRequestRoutes();
    }

    public function test_company_migration_preserves_legacy_requests_without_backfilling(): void
    {
        $migrationPaths = glob(base_path('plugins/cesa/exit-clearance/database/migrations/*_exit_clearance_add_company_id_to_ec_requests_table.php'));

        $this->assertCount(1, $migrationPaths);

        $migration = require $migrationPaths[0];
        $migration->down();

        $legacyRequest = Request::query()->create([
            'name'  => 'Legacy Employee',
            'email' => 'legacy.employee@example.com',
        ]);

        $migration->up();

        $legacyRequest->refresh();

        $this->assertNull($legacyRequest->company_id);
        $this->assertSame('Legacy Employee', $legacyRequest->name);
        $this->assertSame('legacy.employee@example.com', $legacyRequest->email);

        $column = collect(Schema::getColumns('exit_clearance_requests'))->firstWhere('name', 'company_id');

        $this->assertTrue($column['nullable']);
    }

    public function test_internal_request_creation_still_allows_a_null_company(): void
    {
        $request = app(ExitClearanceRequestService::class)->createPublicRequest([
            'name'  => 'Legacy Import',
            'email' => 'legacy.import@example.com',
        ]);

        $this->assertNull($request->company_id);
        $this->assertNull($request->company);
    }

    public function test_company_history_survives_soft_deletion_and_hard_deletion_sets_null(): void
    {
        $company = $this->createCompany();
        $request = Request::factory()->create(['company_id' => $company->id]);

        $company->delete();

        $this->assertTrue($request->fresh()->company->is($company));
        $this->assertSame($company->name, $request->fresh()->company->name);

        $company->forceDelete();

        $this->assertNull($request->fresh()->company_id);
        $this->assertNull($request->fresh()->company);
    }

    public function test_public_company_choices_include_active_and_inactive_companies_in_name_order(): void
    {
        $active = $this->createCompany(['name' => 'ZZ Active Company', 'is_active' => true]);
        $inactive = $this->createCompany(['name' => 'AA Inactive Company', 'is_active' => false]);
        $deleted = $this->createCompany(['name' => 'MM Deleted Company']);
        $deleted->delete();

        Livewire::test(PublicExitClearanceRequestForm::class)
            ->set('currentStep', 2)
            ->assertSet('data.company_id', null)
            ->assertFormFieldExists('company_id', function (Select $field) use ($active, $inactive, $deleted): bool {
                $options = $field->getOptions();
                $names = array_values($options);
                $sortedNames = $names;
                sort($sortedNames);

                $this->assertTrue($field->isRequired());
                $this->assertTrue($field->isSearchable());
                $this->assertSame($active->name, $options[$active->id]);
                $this->assertSame($inactive->name, $options[$inactive->id]);
                $this->assertArrayNotHasKey($deleted->id, $options);
                $this->assertSame($sortedNames, $names);

                return true;
            });
    }

    #[DataProvider('invalidCompanySelections')]
    public function test_public_personal_data_step_rejects_unavailable_companies(string $selection): void
    {
        $data = $this->validFormData([
            'company_id' => $this->invalidCompanyId($selection),
        ]);

        Livewire::test(PublicExitClearanceRequestForm::class)
            ->set('currentStep', 2)
            ->fillForm($data)
            ->call('nextStep')
            ->assertSet('currentStep', 2)
            ->assertHasErrors(['data.company_id'])
            ->assertDispatched('form-errors-presented');

        $this->assertDatabaseCount('exit_clearance_requests', 0);
    }

    #[DataProvider('invalidCompanySelections')]
    public function test_direct_public_submit_rejects_unavailable_companies_when_personal_data_is_hidden(string $selection): void
    {
        $data = $this->validFormData([
            'company_id' => $this->invalidCompanyId($selection),
        ]);

        Livewire::test(PublicExitClearanceRequestForm::class)
            ->set('currentStep', 4)
            ->fillForm($data)
            ->call('submit')
            ->assertHasErrors(['data.company_id'])
            ->assertDispatched('form-errors-presented')
            ->assertNotDispatched('submission-success');

        $this->assertDatabaseCount('exit_clearance_requests', 0);
    }

    #[DataProvider('companyActivityStates')]
    public function test_public_form_saves_an_available_company_and_keeps_department_approvers(bool $isActive): void
    {
        $company = $this->createCompany(['is_active' => $isActive]);
        $department = Department::factory()->create();
        $approver = Approver::query()->create([
            'name'  => 'Department Approver',
            'email' => 'department.approver@example.com',
            'title' => 'Department Head',
        ]);
        $department->approvers()->attach($approver);
        $data = $this->validFormData([
            'company_id'    => $company->id,
            'department_id' => $department->id,
        ]);

        $component = Livewire::test(PublicExitClearanceRequestForm::class)
            ->set('currentStep', 2)
            ->fillForm($data)
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('currentStep', 3)
            ->set('currentStep', 4)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('submission-success');

        $request = Request::query()->where('email', $data['email'])->sole();

        $this->assertSame($company->id, $request->company_id);
        $this->assertTrue($request->company->is($company));
        $this->assertSame([$approver->id], $request->approvers->modelKeys());
        $component->assertRedirect(route('exit-clearance.public.progress', [
            'response' => $request->form_response_id,
        ]));
    }

    public function test_admin_company_field_is_required_searchable_and_has_no_default(): void
    {
        $this->authenticateAdmin();

        $active = $this->createCompany(['name' => 'ZZ Active Admin Company', 'is_active' => true]);
        $inactive = $this->createCompany(['name' => 'AA Inactive Admin Company', 'is_active' => false]);
        $deleted = $this->createCompany(['name' => 'MM Deleted Admin Company']);
        $deleted->delete();

        Livewire::test(CreateRequest::class)
            ->assertSet('data.company_id', null)
            ->assertFormFieldExists('company_id', function (Select $field) use ($active, $inactive, $deleted): bool {
                $options = $field->getOptions();
                $names = array_values($options);
                $sortedNames = $names;
                sort($sortedNames);

                $this->assertTrue($field->isRequired());
                $this->assertTrue($field->isSearchable());
                $this->assertSame($active->name, $options[$active->id]);
                $this->assertSame($inactive->name, $options[$inactive->id]);
                $this->assertArrayNotHasKey($deleted->id, $options);
                $this->assertSame($sortedNames, $names);

                return true;
            });
    }

    #[DataProvider('invalidCompanySelections')]
    public function test_admin_create_rejects_unavailable_companies(string $selection): void
    {
        $this->authenticateAdmin();

        Livewire::test(CreateRequest::class)
            ->fillForm($this->validFormData([
                'company_id' => $this->invalidCompanyId($selection),
            ]))
            ->call('create')
            ->assertHasFormErrors(['company_id']);

        $this->assertDatabaseCount('exit_clearance_requests', 0);
    }

    #[DataProvider('companyActivityStates')]
    public function test_admin_create_saves_an_available_company(bool $isActive): void
    {
        $this->authenticateAdmin();

        $company = $this->createCompany(['is_active' => $isActive]);
        $data = $this->validFormData(['company_id' => $company->id]);

        Livewire::test(CreateRequest::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasNoFormErrors();

        $request = Request::query()->where('email', $data['email'])->sole();

        $this->assertSame($company->id, $request->company_id);
    }

    public function test_admin_can_edit_a_legacy_request_without_adding_a_company(): void
    {
        $this->authenticateAdmin();

        $request = Request::factory()->create($this->validFormData(['company_id' => null]));

        Livewire::test(EditRequest::class, ['record' => $request->id])
            ->fillForm(['name' => 'Updated Legacy Employee', 'company_id' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($request->fresh()->company_id);
        $this->assertSame('Updated Legacy Employee', $request->fresh()->name);
    }

    public function test_company_becomes_required_after_it_is_added_to_a_legacy_request(): void
    {
        $this->authenticateAdmin();

        $request = Request::factory()->create($this->validFormData(['company_id' => null]));
        $company = $this->createCompany();

        Livewire::test(EditRequest::class, ['record' => $request->id])
            ->fillForm(['company_id' => $company->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($company->id, $request->fresh()->company_id);

        Livewire::test(EditRequest::class, ['record' => $request->id])
            ->fillForm(['company_id' => null])
            ->call('save')
            ->assertHasFormErrors(['company_id' => 'required']);

        $this->assertSame($company->id, $request->fresh()->company_id);
    }

    public function test_admin_can_retain_its_own_deleted_company_and_replace_it_with_an_available_company(): void
    {
        $this->authenticateAdmin();

        $company = $this->createCompany();
        $otherDeletedCompany = $this->createCompany();
        $replacement = $this->createCompany(['is_active' => false]);
        $request = Request::factory()->create($this->validFormData(['company_id' => $company->id]));
        $company->delete();
        $otherDeletedCompany->delete();

        Livewire::test(EditRequest::class, ['record' => $request->id])
            ->assertFormFieldExists('company_id', function (Select $field) use ($company, $otherDeletedCompany): bool {
                $this->assertSame($company->name, $field->getOptions()[$company->id]);
                $this->assertArrayNotHasKey($otherDeletedCompany->id, $field->getOptions());

                return true;
            })
            ->fillForm(['name' => 'Employee With Historical Company'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($company->id, $request->fresh()->company_id);
        $this->assertSame('Employee With Historical Company', $request->fresh()->name);

        Livewire::test(EditRequest::class, ['record' => $request->id])
            ->fillForm(['company_id' => $otherDeletedCompany->id])
            ->call('save')
            ->assertHasFormErrors(['company_id']);

        $this->assertSame($company->id, $request->fresh()->company_id);

        Livewire::test(EditRequest::class, ['record' => $request->id])
            ->fillForm(['company_id' => $replacement->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($replacement->id, $request->fresh()->company_id);
    }

    public function test_admin_cannot_assign_a_deleted_company_to_a_legacy_request(): void
    {
        $this->authenticateAdmin();

        $request = Request::factory()->create($this->validFormData(['company_id' => null]));
        $company = $this->createCompany();
        $company->delete();

        Livewire::test(EditRequest::class, ['record' => $request->id])
            ->fillForm(['company_id' => $company->id])
            ->call('save')
            ->assertHasFormErrors(['company_id']);

        $this->assertNull($request->fresh()->company_id);
    }

    public function test_admin_list_and_detail_display_historical_companies_and_legacy_placeholders(): void
    {
        $this->authenticateAdmin();

        $company = $this->createCompany(['name' => 'Historical Core Company']);
        $historicalRequest = Request::factory()->create($this->validFormData(['company_id' => $company->id]));
        $legacyRequest = Request::factory()->create($this->validFormData(['company_id' => null]));
        $company->delete();

        $list = Livewire::test(ListRequests::class)
            ->assertCanSeeTableRecords([$historicalRequest, $legacyRequest])
            ->assertTableColumnVisible('company.name')
            ->assertTableColumnStateSet('company.name', $company->name, $historicalRequest)
            ->assertTableColumnStateSet('company.name', null, $legacyRequest)
            ->assertSee($company->name);

        $companyColumn = $list->instance()->getTable()->getColumn('company.name');
        $companyColumn->record($legacyRequest);
        $companyColumn->clearCachedState();

        $this->assertStringContainsString('—', $companyColumn->toHtml());

        $list->searchTable($company->name)
            ->assertCanSeeTableRecords([$historicalRequest])
            ->assertCanNotSeeTableRecords([$legacyRequest]);

        foreach ([$historicalRequest, $legacyRequest] as $request) {
            $expectedCompanyName = $request->company_id === null ? null : $company->name;
            $detail = Livewire::test(ViewRequest::class, ['record' => $request->id])
                ->assertSee(__('exit-clearance::filament/resources/request.infolist_fields.company'))
                ->assertSee($expectedCompanyName ?? '—');

            $companyEntry = collect($detail->instance()->infolist->getFlatComponents())
                ->first(fn (Component $component): bool => $component instanceof TextEntry && $component->getName() === 'company.name');

            $this->assertInstanceOf(TextEntry::class, $companyEntry);
            $this->assertSame($expectedCompanyName, $companyEntry->getState());
            $this->assertStringContainsString($expectedCompanyName ?? '—', $companyEntry->toHtml());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCompanySelections(): array
    {
        return [
            'empty company'        => ['empty'],
            'nonexistent company'  => ['missing'],
            'soft deleted company' => ['deleted'],
            'malformed company'    => ['malformed'],
        ];
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function companyActivityStates(): array
    {
        return [
            'active company'   => [true],
            'inactive company' => [false],
        ];
    }

    private function invalidCompanyId(string $selection): int|string|null
    {
        if ($selection === 'deleted') {
            $company = $this->createCompany();
            $company->delete();

            return $company->id;
        }

        return match ($selection) {
            'empty'     => null,
            'missing'   => 99999999,
            'malformed' => 'invalid-company',
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createCompany(array $attributes = []): Company
    {
        return Company::factory()->create(array_merge([
            'currency_id' => null,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validFormData(array $attributes = []): array
    {
        return Request::factory()->raw(array_merge([
            'company_id'             => null,
            'department_id'          => Department::factory()->create()->id,
            'phone'                  => '081234567890',
            'form_status'            => ExitClearanceRequestService::FORM_STATUS_PENDING,
            'resignation_letter_url' => null,
        ], $attributes));
    }

    private function authenticateAdmin(): void
    {
        $user = User::factory()->create([
            'resource_permission' => PermissionType::GLOBAL,
            'is_active'           => true,
        ]);
        $admin = SecurityUser::query()->findOrFail($user->id);
        $role = Role::findOrCreate('super_admin', 'web');
        $role->syncPermissionsByNames([
            'view_any_exit_clearance_request',
            'view_exit_clearance_request',
            'create_exit_clearance_request',
            'update_exit_clearance_request',
        ]);
        $admin->assignRole($role);

        $this->actingAs($admin);
    }

    private function registerRequestRoutes(): void
    {
        $routes = [
            'filament.admin.resources.requests.index'  => '/testing/exit-clearance/requests',
            'filament.admin.resources.requests.create' => '/testing/exit-clearance/requests/create',
            'filament.admin.resources.requests.view'   => '/testing/exit-clearance/requests/{record}',
            'filament.admin.resources.requests.edit'   => '/testing/exit-clearance/requests/{record}/edit',
            'exit-clearance.public.progress'           => '/testing/exit-clearance/progress/{response}',
            'exit-clearance.public.approval'           => '/testing/exit-clearance/approval/{request}/{approver}',
        ];

        foreach ($routes as $name => $path) {
            if (! Route::has($name)) {
                Route::get($path, fn (): string => 'exit-clearance')->name($name);
            }
        }

        $registeredRoutes = app('router')->getRoutes();
        $registeredRoutes->refreshNameLookups();
        $registeredRoutes->refreshActionLookups();
    }
}
