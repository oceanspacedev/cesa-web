<?php

namespace Cesa\Waste\Services;

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
        $message = "Pengingat: approval waste {$report->uid} masih menunggu tindakan.\n"
            .route('waste.public.approval', ['token' => $token]);

        if (filled($approval->approver_phone)) {
            $this->queueDelivery($report, $version, 'whatsapp', 'approval_'.$approval->step_order.'_reminder', $approval->approver_phone, $message);
        }

        if (config('waste.notifications.email_enabled', true) && filled($approval->approver_email)) {
            $this->queueDelivery($report, $version, 'email', 'approval_'.$approval->step_order.'_reminder', $approval->approver_email, $message, 'Pengingat approval waste '.$report->uid);
        }
    }

    public function queueRequester(WasteReport $report, string $type, ?string $progressToken = null, ?string $manageToken = null): void
    {
        $links = [];
        if ($progressToken) {
            $links[] = 'Status: '.route('waste.public.progress', ['token' => $progressToken]);
        }
        if ($manageToken && $type === 'rejected') {
            $links[] = 'Revisi: '.route('waste.public.manage', ['token' => $manageToken]);
        } elseif ($type === 'rejected') {
            $links[] = 'Buka tautan status yang dikirim saat pengajuan untuk merevisi laporan.';
        }

        $message = "Laporan waste {$report->uid} berstatus {$type}.\n".implode("\n", $links);
        $version = $report->latestVersion;
        $this->queueDelivery($report, $version, 'whatsapp', 'requester_'.$type, $report->reporter_phone, $message);

        if ($type === 'submitted' && $version) {
            $this->queueEvidence($report, $version, 'requester_submitted_evidence', $report->reporter_phone, implode("\n", $links));
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
        $message = "Approval waste {$report->uid} membutuhkan tindakan.\n".route('waste.public.approval', ['token' => $token]);

        if (filled($approval->approver_phone)) {
            $this->queueDelivery($report, $version, 'whatsapp', 'approval_'.$approval->step_order, $approval->approver_phone, $message);
            $this->queueEvidence($report, $version, 'approval_'.$approval->step_order.'_evidence', $approval->approver_phone, route('waste.public.approval', ['token' => $token]));
        }

        if (config('waste.notifications.email_enabled', true) && filled($approval->approver_email)) {
            $this->queueDelivery($report, $version, 'email', 'approval_'.$approval->step_order, $approval->approver_email, $message, 'Approval waste '.$report->uid);
        }
    }

    protected function queueEvidence(WasteReport $report, WasteReportVersion $version, string $type, string $recipient, string $actionLink): void
    {
        if (trim($recipient) === '') {
            return;
        }

        $version->loadMissing('events.evidences');
        $report->loadMissing('brand', 'outlet');

        foreach ($version->events as $event) {
            foreach ($event->evidences as $index => $evidence) {
                $caption = 'Bukti foto waste '.$report->brand->name.' / '.$report->outlet->name
                    .', '.($report->event_date?->format('d/m/Y') ?? '-')
                    .', kejadian '.((int) $event->sequence + 1).', foto '.($index + 1)
                    .".\nLaporan: {$report->uid}\n".$actionLink;
                $this->queueDelivery($report, $version, 'whatsapp', $type, $recipient, $caption, additionalPayload: [
                    'evidence_id' => $evidence->getKey(),
                ]);
            }
        }
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
