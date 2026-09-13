<?php

namespace Cesa\ExitClearance\Tests\Feature;

use Cesa\ExitClearance\Filament\Exports\RequestExporter;
use Cesa\ExitClearance\Livewire\PublicExitClearanceApprovalPage;
use Cesa\ExitClearance\Livewire\PublicExitClearanceProgressPage;
use Cesa\ExitClearance\Models\Approver;
use Cesa\ExitClearance\Models\Request;
use Cesa\ExitClearance\Services\ExitClearanceRequestService;
use Cesa\ExitClearance\Tests\ExitClearanceTestCase;
use Filament\Actions\Exports\Models\Export;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Webkul\Support\Models\Company;

class RequestCompanyOutputsTest extends ExitClearanceTestCase
{
    #[DataProvider('companyStates')]
    public function test_outputs_preserve_company_names_and_support_legacy_requests(string $companyState): void
    {
        app()->setLocale('id');

        $request = $this->createRequestWithCompanyState($companyState);
        $expectedCompany = $companyState === 'legacy' ? '—' : 'PT Cesa Output Test';
        $request = RequestExporter::modifyQuery(Request::query())->findOrFail($request->getKey());
        $exporter = new RequestExporter(new Export, ['company.name' => 'Badan Usaha'], []);

        $this->assertTrue($request->relationLoaded('company'));
        $this->assertSame([$expectedCompany], $exporter($request));

        $requestService = app(ExitClearanceRequestService::class);
        $summary = $requestService->buildSummary($request);
        $personalSummary = $requestService->buildCategorizedSummary($request)['data_diri'];

        foreach ([$summary, $personalSummary] as $items) {
            $companyIndex = collect($items)->search(fn (array $item): bool => $item['label'] === 'Badan Usaha');

            $this->assertNotFalse($companyIndex);
            $this->assertSame($expectedCompany, $items[$companyIndex]['value']);
            $this->assertSame('Divisi', $items[$companyIndex + 1]['label']);
        }

        $pdfHtml = view('exit-clearance::pdf.request', ['record' => $request])->render();

        $this->assertStringContainsString('Badan Usaha', $pdfHtml);
        $this->assertStringContainsString('<td>'.$expectedCompany.'</td>', $pdfHtml);

        foreach (['request-status', 'approval-request'] as $mailView) {
            $mailHtml = view('exit-clearance::mail.'.$mailView, [
                'request'     => $request,
                'approver'    => new Approver(['name' => 'Test Approver']),
                'statusLabel' => 'Pending',
                'summary'     => $summary,
                'approvals'   => [],
                'progressUrl' => 'https://example.com/progress',
                'actionUrl'   => 'https://example.com/approval',
            ])->render();

            $this->assertStringContainsString('Badan Usaha', $mailHtml);
            $this->assertStringContainsString($expectedCompany, $mailHtml);
        }
    }

    #[DataProvider('publicCompanyStates')]
    public function test_public_pages_render_company_names_and_legacy_placeholder(string $companyState): void
    {
        app()->setLocale('id');
        $this->withoutVite();

        $request = $this->createRequestWithCompanyState($companyState);
        $expectedCompany = $companyState === 'legacy' ? '—' : 'PT Cesa Output Test';
        $approver = Approver::query()->create([
            'name'  => 'Company Output Approver',
            'email' => 'company-output-approver@example.com',
            'title' => 'HR Manager',
        ]);

        $request->approvers()->sync([
            $approver->getKey() => ['status' => ExitClearanceRequestService::APPROVAL_PENDING],
        ]);

        Livewire::test(PublicExitClearanceProgressPage::class, [
            'response' => $request->form_response_id,
        ])
            ->assertSet('summary', fn (array $summary): bool => collect($summary['data_diri'])->firstWhere('label', 'Badan Usaha')['value'] === $expectedCompany)
            ->assertSee('Badan Usaha')
            ->assertSee($expectedCompany);

        Livewire::test(PublicExitClearanceApprovalPage::class, [
            'request'  => $request->getKey(),
            'approver' => $approver->getKey(),
        ])
            ->assertSet('summary', fn (array $summary): bool => collect($summary['data_diri'])->firstWhere('label', 'Badan Usaha')['value'] === $expectedCompany)
            ->assertSee('Badan Usaha')
            ->assertSee($expectedCompany);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function companyStates(): array
    {
        return [
            'active company'       => ['active'],
            'inactive company'     => ['inactive'],
            'soft-deleted company' => ['deleted'],
            'legacy null company'  => ['legacy'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicCompanyStates(): array
    {
        return [
            'active company'      => ['active'],
            'legacy null company' => ['legacy'],
        ];
    }

    private function createRequestWithCompanyState(string $companyState): Request
    {
        $company = null;

        if ($companyState !== 'legacy') {
            $company = Company::factory()->create([
                'name'        => 'PT Cesa Output Test',
                'is_active'   => $companyState !== 'inactive',
                'currency_id' => null,
            ]);
        }

        $request = Request::factory()->create([
            'company_id'             => $company?->getKey(),
            'form_status'            => ExitClearanceRequestService::FORM_STATUS_PENDING,
            'resignation_letter_url' => null,
        ]);

        if ($companyState === 'deleted') {
            $company->delete();
        }

        return $request;
    }
}
