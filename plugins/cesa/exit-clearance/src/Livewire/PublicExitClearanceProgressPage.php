<?php

namespace Cesa\ExitClearance\Livewire;

use Cesa\ExitClearance\Models\Request as ExitClearanceRequest;
use Cesa\ExitClearance\Services\ExitClearanceRequestService;
use Filament\Pages\SimplePage;
use Webkul\PluginManager\Package;

class PublicExitClearanceProgressPage extends SimplePage
{
    protected static string $layout = 'exit-clearance::layouts.form';

    protected string $view = 'exit-clearance::livewire.public-exit-clearance-progress-page';

    public array $summary = [];

    public array $approvals = [];

    public string $statusLabel = '';

    public ?string $applicantName = null;

    public ?string $applicantDepartment = null;

    public ?string $applicantUid = null;

    protected ExitClearanceRequestService $requestService;

    public function boot(ExitClearanceRequestService $requestService): void
    {
        $this->requestService = $requestService;
    }

    public function mount(string $response): void
    {
        if (! Package::isPluginInstalled('exit-clearance')) {
            abort(404);
        }

        $request = ExitClearanceRequest::query()
            ->with(['approvers', 'company', 'department'])
            ->where('form_response_id', $response)
            ->first();

        if (! $request) {
            abort(404);
        }

        $this->applicantName = $request->name;
        $this->applicantDepartment = $request->department?->name ?? '-';
        $this->applicantUid = $request->form_uid;

        $this->summary = $this->requestService->buildCategorizedSummary($request);
        $this->approvals = $this->requestService->buildApprovals($request);
        $this->statusLabel = $this->requestService->formatFormStatus($request->form_status);
    }

    public function getHeading(): string
    {
        return __('exit-clearance::livewire/public-exit-clearance-progress-page.heading');
    }

    public function getSubheading(): string
    {
        return __('exit-clearance::livewire/public-exit-clearance-progress-page.subheading');
    }

    public function hasLogo(): bool
    {
        return false;
    }
}
