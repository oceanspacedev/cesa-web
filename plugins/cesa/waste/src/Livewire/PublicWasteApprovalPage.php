<?php

namespace Cesa\Waste\Livewire;

use Cesa\Waste\Services\WasteApprovalService;
use Cesa\Waste\Services\WasteReportService;
use Filament\Pages\SimplePage;
use Illuminate\Validation\ValidationException;

class PublicWasteApprovalPage extends SimplePage
{
    protected static string $layout = 'waste::layouts.form';

    protected string $view = 'waste::livewire.public-waste-approval-page';

    public array $summary = [];

    public array $events = [];

    public array $approvals = [];

    public string $token = '';

    public string $rejectionReason = '';

    public bool $actionTaken = false;

    public function mount(string $token, WasteReportService $service): void
    {
        $this->token = $token;
        $this->load($service->approvalForToken($token));
    }

    public function approve(WasteApprovalService $service): void
    {
        if ($this->actionTaken) {
            return;
        }

        $result = $service->approve($this->token);
        $this->actionTaken = true;
        $this->summary['status'] = $result['report']->status?->value;
        $this->summary['status_label'] = __('waste::waste.status.'.($result['report']->status?->value ?? 'pending'));
    }

    public function reject(WasteApprovalService $service): void
    {
        if ($this->actionTaken) {
            return;
        }

        if (trim($this->rejectionReason) === '') {
            throw ValidationException::withMessages(['rejectionReason' => 'Alasan penolakan wajib diisi.']);
        }

        $result = $service->reject($this->token, $this->rejectionReason);
        $this->actionTaken = true;
        $this->summary['status'] = $result['report']->status?->value;
        $this->summary['status_label'] = __('waste::waste.status.'.($result['report']->status?->value ?? 'rejected'));
    }

    public function getTitle(): string
    {
        return __('waste::waste.approval_title');
    }

    protected function load($approval): void
    {
        $report = $approval->version->report;
        $this->summary = [
            'uid'           => $report->uid,
            'brand'         => $report->brand->name,
            'outlet'        => $report->outlet->name,
            'event_date'    => optional($report->event_date)->format('d M Y'),
            'reporter_name' => $report->reporterNameForDisplay(),
            'status'        => $report->status?->value,
            'status_label'  => __('waste::waste.status.'.($report->status?->value ?? 'pending')),
            'current_step'  => $approval->label,
            'approver_name' => $approval->approver_name,
        ];
        $this->events = $approval->version->events->map(fn ($event): array => [
            'sequence' => $event->sequence + 1,
            'category' => $event->category_name,
            'reason'   => $event->reason,
            'evidence' => $event->evidences->map(fn ($evidence): string => route('waste.public.evidence', ['evidence' => $evidence->getKey(), 'token' => $this->token]))->all(),
            'lines'    => $event->lines->map(fn ($line): array => [
                'item'     => $line->item_name,
                'code'     => $line->item_code,
                'quantity' => $line->quantity,
                'unit'     => $line->unit,
            ])->all(),
        ])->all();
        $this->approvals = $approval->version->approvals->map(fn ($step): array => [
            'label'        => $step->label,
            'name'         => $step->approver_name,
            'status'       => $step->status?->value,
            'status_label' => __('waste::waste.approval_status.'.($step->status?->value ?? 'waiting')),
        ])->all();
    }
}
