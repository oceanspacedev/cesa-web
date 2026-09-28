<?php

namespace Cesa\Waste\Services;

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Jobs\SendWasteNotification;
use Cesa\Waste\Models\WasteApproval;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteReportVersion;
use Illuminate\Support\Facades\Queue;
use Throwable;

class WasteNotificationService
{
    public function queueSubmission(WasteReport $report, string $progressToken, string $manageToken, array $approvalTokens): void
    {
        $version = $report->latestVersion()->with(['approvals', 'events.evidences'])->first();
        if (! $version) {
            return;
        }

        if ($report->status === WasteReportStatus::Approved && $version->approvals->isEmpty()) {
            $this->queueRequester($report, 'approved', $progressToken);

            return;
        }

        foreach ($version->approvals as $index => $approval) {
            $token = $approvalTokens[$index] ?? null;
            if ($token) {
                $this->queueApproval($report, $version, $approval, $token);
            }
        }

        $this->queueRequester($report, 'submitted', $progressToken, $manageToken);
    }

    public function queueNextApproval(WasteReport $report, WasteReportVersion $version, WasteApproval $approval, string $token): void
    {
        $this->queueApproval($report, $version, $approval, $token);
    }

    public function queueApprovalReminder(WasteReport $report, WasteReportVersion $version, WasteApproval $approval, string $token): void
    {
        $message = $this->approvalMessage($report, $approval->displayName(), route('waste.public.approval', ['token' => $token]), true);

        if (filled($approval->approver_phone)) {
            $this->queueDelivery($report, $version, 'whatsapp', 'approval_'.$approval->step_order.'_reminder', $approval->approver_phone, $message);
        }

        if (config('waste.notifications.email_enabled', true) && filled($approval->approver_email)) {
            $this->queueDelivery($report, $version, 'email', 'approval_'.$approval->step_order.'_reminder', $approval->approver_email, $message, 'Pengingat approval waste '.$report->uid);
        }
    }

    public function queueRequester(WasteReport $report, string $type, ?string $progressToken = null, ?string $manageToken = null): void
    {
        $message = $this->requesterMessage($report, $type, $progressToken, $manageToken);
        $version = $report->latestVersion;
        $this->queueDelivery($report, $version, 'whatsapp', 'requester_'.$type, $report->reporter_phone, $message);

        if ($type === 'submitted' && $version && $progressToken) {
            $this->queueEvidence($report, $version, 'requester_submitted_evidence', $report->reporter_phone, route('waste.public.progress', ['token' => $progressToken]));
        }

        if (config('waste.notifications.email_enabled', true) && filled($report->reporter_email)) {
            $this->queueDelivery($report, $version, 'email', 'requester_'.$type, $report->reporter_email, $message, 'Status laporan waste '.$report->uid);
        }
    }

    public function retryFailed(WasteReport $report, ?object $user): int
    {
        abort_unless(app(WasteAccessService::class)->canManageBrand($user, $report->brand), 403);

        $deliveries = $report->notifications()->where('status', 'failed')->get();
        foreach ($deliveries as $delivery) {
            $delivery->forceFill([
                'status'     => 'pending',
                'last_error' => null,
                'sent_at'    => null,
            ])->save();
            Queue::push(new SendWasteNotification($delivery->getKey()));
        }

        return $deliveries->count();
    }

    protected function queueApproval(WasteReport $report, WasteReportVersion $version, WasteApproval $approval, string $token): void
    {
        $url = route('waste.public.approval', ['token' => $token]);
        $message = $this->approvalMessage($report, $approval->displayName(), $url);

        if (filled($approval->approver_phone)) {
            $this->queueDelivery($report, $version, 'whatsapp', 'approval_'.$approval->step_order, $approval->approver_phone, $message);
            $this->queueEvidence($report, $version, 'approval_'.$approval->step_order.'_evidence', $approval->approver_phone, $url, $approval->displayName());
        }

        if (config('waste.notifications.email_enabled', true) && filled($approval->approver_email)) {
            $this->queueDelivery($report, $version, 'email', 'approval_'.$approval->step_order, $approval->approver_email, $message, 'Approval waste '.$report->uid);
        }
    }

    protected function queueEvidence(WasteReport $report, WasteReportVersion $version, string $type, string $recipient, string $actionLink, ?string $heading = null): void
    {
        if (trim($recipient) === '') {
            return;
        }

        $version->loadMissing('events.evidences', 'events.lines');

        foreach ($version->events as $event) {
            foreach ($event->evidences as $evidence) {
                $lines = [
                    $this->placeLine($report),
                    $this->materialLines($event),
                ];
                if (filled($heading)) {
                    $lines[] = '';
                    $lines[] = $heading;
                }
                if (filled($actionLink)) {
                    $lines[] = $actionLink;
                }

                $this->queueDelivery($report, $version, 'whatsapp', $type, $recipient, implode("\n", $lines), additionalPayload: [
                    'evidence_id' => $evidence->getKey(),
                ]);
            }
        }
    }

    protected function approvalMessage(WasteReport $report, string $label, string $url, bool $reminder = false): string
    {
        $lines = $reminder ? ['Pengingat'] : [];
        $lines[] = $label;
        $lines[] = $this->placeLine($report);
        $lines[] = '';
        $lines[] = $url;

        return implode("\n", $lines);
    }

    protected function requesterMessage(WasteReport $report, string $type, ?string $progressToken, ?string $manageToken): string
    {
        $materials = $this->reportMaterials($report);
        $lines = [$this->placeLine($report)];

        if ($materials !== '') {
            $lines[] = $materials;
        }

        $lines[] = '';
        $lines[] = match ($type) {
            'approved' => 'Laporan disetujui.',
            'rejected' => 'Laporan ditolak.',
            default    => 'Laporan terkirim.',
        };

        if ($progressToken) {
            $lines[] = route('waste.public.progress', ['token' => $progressToken]);
        }

        if ($manageToken && $type === 'rejected') {
            $lines[] = '';
            $lines[] = 'Perbaiki laporan';
            $lines[] = route('waste.public.manage', ['token' => $manageToken]);
        } elseif ($type === 'rejected') {
            $lines[] = 'Buka tautan status yang dikirim saat pengajuan untuk merevisi laporan.';
        }

        return implode("\n", $lines);
    }

    protected function reportMaterials(WasteReport $report): string
    {
        $version = $report->latestVersion;
        if (! $version) {
            return '';
        }

        $version->loadMissing('events.lines');

        return $version->events
            ->sortBy('sequence')
            ->map(fn (object $event): string => $this->materialLines($event))
            ->filter()
            ->implode("\n");
    }

    protected function materialLines(object $event): string
    {
        return $event->lines
            ->map(function (object $line): string {
                $quantity = rtrim(rtrim(number_format((float) $line->quantity, 4, '.', ''), '0'), '.');
                $unit = filled($line->unit_label) ? $line->unit_label : $line->unit;

                return trim($line->item_name.' '.($quantity === '' ? '0' : $quantity).' '.$unit);
            })
            ->filter()
            ->implode("\n");
    }

    protected function placeLine(WasteReport $report): string
    {
        $report->loadMissing('brand', 'outlet');
        $date = $report->event_date?->locale('id')->translatedFormat('j F Y') ?? '-';

        return $report->brand->name.' / '.$report->outlet->name."\n".$date;
    }

    protected function queueDelivery(
        WasteReport $report,
        ?WasteReportVersion $version,
        string $channel,
        string $type,
        string $recipient,
        string $message,
        ?string $subject = null,
        array $additionalPayload = [],
    ): void {
        if (trim($recipient) === '') {
            return;
        }

        $delivery = $report->notifications()->create([
            'version_id' => $version?->getKey(),
            'channel'    => $channel,
            'type'       => $type,
            'recipient'  => $recipient,
            'payload'    => array_merge(['message' => $message, 'subject' => $subject ?? 'Waste report'], $additionalPayload),
            'status'     => 'pending',
        ]);

        try {
            Queue::push(new SendWasteNotification($delivery->getKey()));
        } catch (Throwable $exception) {
            $delivery->forceFill([
                'status'     => 'failed',
                'last_error' => $exception->getMessage(),
            ])->save();

            report($exception);
        }
    }
}
