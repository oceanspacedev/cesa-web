<?php

namespace Cesa\Waste\Livewire;

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Services\WasteReportService;
use Filament\Pages\SimplePage;

class PublicWasteProgressPage extends SimplePage
{
    protected static string $layout = 'waste::layouts.form';

    protected string $view = 'waste::livewire.public-waste-progress-page';

    public array $summary = [];

    public array $events = [];

    public ?string $revisionUrl = null;

    public ?string $rejectionReason = null;

    public function mount(string $token, WasteReportService $service): void
    {
        $report = $service->reportForProgressToken($token);
        $version = $report->latestVersion;

        if ($report->status === WasteReportStatus::Rejected) {
            $this->rejectionReason = $version?->rejection_reason;

            if (is_string($report->manage_token_hash)
                && hash_equals($report->manage_token_hash, $service->tokenHash($token))) {
                $this->revisionUrl = route('waste.public.manage', ['token' => $token]);
            }
        }

        $this->summary = [
            'brand'         => $report->brand->name,
            'outlet'        => $report->outlet->name,
            'event_date'    => optional($report->event_date)->format('d M Y'),
            'reporter_name' => $report->reporterNameForDisplay(),
            'status'        => $report->status?->value,
            'status_label'  => __('waste::waste.status.'.($report->status?->value ?? 'pending')),
        ];
        $this->events = $version?->events->map(fn ($event): array => [
            'sequence'     => $event->sequence + 1,
            'category'     => $event->category_name,
            'reason'       => $event->reason,
            'section'      => $event->section,
            'pip'          => $event->pip_item_name ?: (strtoupper((string) $report->brand->code) === 'MOMOYO' ? 'NON PIP' : null),
            'pip_quantity' => $event->pip_quantity !== null ? $this->formatQuantity($event->pip_quantity) : null,
            'pip_unit'     => $event->pip_unit,
            'evidence'     => $event->evidences->map(fn ($evidence): string => route('waste.public.evidence', ['evidence' => $evidence->getKey(), 'token' => $token]))->all(),
            'lines'        => $event->lines->map(fn ($line): array => [
                'item'     => $line->item_name,
                'quantity' => $this->formatQuantity($line->quantity),
                'unit'     => $line->unit,
            ])->all(),
        ])->all() ?? [];
    }

    public function getTitle(): string
    {
        return __('waste::waste.progress_title');
    }

    protected function formatQuantity(mixed $quantity): string
    {
        $normalized = rtrim(rtrim(number_format((float) $quantity, 4, '.', ''), '0'), '.');

        return $normalized === '' ? '0' : $normalized;
    }
}
