<?php

namespace Cesa\ExitClearance\Services;

use App\Services\WhatsApp\WagHubClient;
use Cesa\ExitClearance\Jobs\SendWhatsAppNotification;
use Cesa\ExitClearance\Models\Approver;
use Cesa\ExitClearance\Models\Request;
use Cesa\ExitClearance\Notifications\ApprovalRequestNotification;
use Cesa\ExitClearance\Notifications\RequestStatusNotification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

class ExitClearanceNotificationService
{
    public function __construct(
        protected ExitClearanceRequestService $requestService,
    ) {}

    public function notifyApprovers(Request $request): void
    {
        $request->loadMissing('approvers', 'department');

        foreach ($request->approvers as $approver) {
            $this->notifyApprover($request, $approver);
        }
    }

    public function notifyPendingApprovers(Request $request, ?int $approverId = null): int
    {
        $request->loadMissing('approvers', 'department');

        $pendingApprovers = $request->approvers->filter(function ($approver) use ($approverId): bool {
            $status = $this->requestService->normalizeApprovalStatus($approver->pivot?->status);

            if ($status !== ExitClearanceRequestService::APPROVAL_PENDING) {
                return false;
            }

            if ($approverId === null) {
                return true;
            }

            return (int) $approver->getKey() === $approverId;
        });

        foreach ($pendingApprovers as $approver) {
            $this->notifyApprover($request, $approver);
        }

        return $pendingApprovers->count();
    }

    public function notifyApprover(Request $request, Approver $approver): void
    {
        $request->loadMissing('department');

        $summary = $this->requestService->buildSummary($request);
        $approvals = $this->requestService->buildApprovals($request);
        $progressUrl = $this->buildProgressUrl($request);
        $actionUrl = $this->buildApprovalUrl($request, $approver);

        if (! empty($approver->email) && config('exit-clearance.notifications.mail.enabled', true)) {
            $notification = new ApprovalRequestNotification(
                $request,
                $approver,
                $summary,
                $approvals,
                $actionUrl,
                $progressUrl,
            );

            $delaySeconds = app(MailThrottleService::class)->getDispatchDelaySeconds();

            if ($delaySeconds > 0) {
                $notification->delay(now()->addSeconds($delaySeconds));
            }

            Notification::route('mail', $approver->email)
                ->notify($notification);
        }

        $this->sendWhatsApp(
            $approver->phone,
            $this->buildApproverWhatsAppMessage($request, $approver, $actionUrl, $progressUrl)
        );
    }

    public function notifyRequester(Request $request, string $statusLabel): void
    {
        $summary = $this->requestService->buildSummary($request);
        $approvals = $this->requestService->buildApprovals($request);
        $progressUrl = $this->buildProgressUrl($request);

        if (! empty($request->email) && config('exit-clearance.notifications.mail.enabled', true)) {
            $notification = new RequestStatusNotification(
                $request,
                $statusLabel,
                $summary,
                $approvals,
                $progressUrl,
            );

            $delaySeconds = app(MailThrottleService::class)->getDispatchDelaySeconds();

            if ($delaySeconds > 0) {
                $notification->delay(now()->addSeconds($delaySeconds));
            }

            Notification::route('mail', $request->email)
                ->notify($notification);
        }

        $this->sendWhatsApp(
            $request->phone,
            $this->buildRequesterWhatsAppMessage($request, $statusLabel, $progressUrl)
        );
    }

    public function notifyRequesterIfFinal(Request $request): void
    {
        $status = $this->requestService->normalizeFormStatus($request->form_status);

        if (! in_array($status, ['approved', 'rejected'], true)) {
            return;
        }

        $this->notifyRequester($request, $this->requestService->formatFormStatus($request->form_status));
    }

    public function buildApprovalUrl(Request $request, Approver $approver): string
    {
        return URL::signedRoute('exit-clearance.public.approval', [
            'request'  => $request->getKey(),
            'approver' => $approver->getKey(),
        ]);
    }

    public function buildProgressUrl(Request $request): string
    {
        if (empty($request->form_response_id)) {
            return url('exit-clearance');
        }

        return route('exit-clearance.public.progress', [
            'response' => $request->form_response_id,
        ]);
    }

    protected function buildApproverWhatsAppMessage(
        Request $request,
        Approver $approver,
        string $actionUrl,
        string $progressUrl,
    ): string {
        $request->loadMissing('department');

        $statusLabel = $this->requestService->formatFormStatus($request->form_status);
        $lines = [
            '*📣 '.__('exit-clearance::notifications.whatsapp.approver.heading', [
                'uid' => $request->form_uid ?? '-',
            ]).'*',
            '',
            '*'.__('exit-clearance::notifications.whatsapp.labels.requester_name').':* '.($request->name ?? '-'),
            '*'.__('exit-clearance::notifications.whatsapp.labels.division').':* '.($request->department?->name ?? '-'),
            '*'.__('exit-clearance::notifications.whatsapp.labels.approval_step').':* '.($approver->title ?? '-'),
            '*'.__('exit-clearance::notifications.whatsapp.labels.approver_name').':* '.($approver->name ?? '-'),
            '*'.__('exit-clearance::notifications.whatsapp.labels.status').':* '.$statusLabel,
            '',
            '*'.__('exit-clearance::notifications.whatsapp.labels.approval_link').':*',
            $actionUrl,
        ];

        return implode("\n", $lines);
    }

    protected function buildRequesterWhatsAppMessage(Request $request, string $statusLabel, string $progressUrl): string
    {
        $request->loadMissing('department');

        $lines = [
            '*📣 '.__('exit-clearance::notifications.whatsapp.requester.heading', [
                'uid' => $request->form_uid ?? '-',
            ]).'*',
            '',
            '*'.__('exit-clearance::notifications.whatsapp.labels.requester_name').':* '.($request->name ?? '-'),
            '*'.__('exit-clearance::notifications.whatsapp.labels.division').':* '.($request->department?->name ?? '-'),
            '*'.__('exit-clearance::notifications.whatsapp.labels.status').':* '.$statusLabel,
            '',
            '*'.__('exit-clearance::notifications.whatsapp.labels.progress_link').':*',
            $progressUrl,
        ];

        return implode("\n", $lines);
    }

    protected function sendWhatsApp(?string $phone, ?string $message): void
    {
        if (! $phone || ! $message) {
            return;
        }

        $config = config('exit-clearance.notifications.whatsapp', []);

        if (! Arr::get($config, 'enabled')) {
            return;
        }

        $endpoint = Arr::get($config, 'endpoint');
        $apiKey = Arr::get($config, 'api_key');
        $hub = app(WagHubClient::class);
        if ($hub->engine()->isV2() && $hub->engine()->isConfigured()) {
            $endpoint = $hub->engine()->baseUrl();
            $apiKey = 'shared-integration';
        }
        if (! $endpoint || ! $apiKey) {
            Log::warning('Exit clearance WhatsApp notification skipped due to missing configuration.', [
                'endpoint' => $endpoint,
                'api_key'  => $apiKey ? 'configured' : 'missing',
            ]);

            return;
        }

        $formattedPhone = $this->formatPhone($phone);

        if (! $formattedPhone) {
            Log::warning('Exit clearance WhatsApp notification skipped due to invalid phone.', [
                'phone' => $phone,
            ]);

            return;
        }

        $timeout = (int) ($config['timeout'] ?? 10);
        $delaySeconds = app(WhatsAppThrottleService::class)->getDispatchDelaySeconds();

        $pendingDispatch = SendWhatsAppNotification::dispatch(
            $formattedPhone,
            $message,
            $endpoint,
            $apiKey,
            $timeout,
        );

        if ($delaySeconds > 0) {
            $pendingDispatch->delay($delaySeconds);
        }
    }

    protected function formatPhone(string $phone): ?string
    {
        $trimmed = trim($phone);

        if ($trimmed === '') {
            return null;
        }

        $digitsOnly = preg_replace('/[^\d]/', '', $trimmed);

        if (! is_string($digitsOnly) || $digitsOnly === '') {
            return null;
        }

        if (str_starts_with($digitsOnly, '62')) {
            return $digitsOnly;
        }

        if (str_starts_with($digitsOnly, '0')) {
            return '62'.substr($digitsOnly, 1);
        }

        if (str_starts_with($digitsOnly, '8')) {
            return '62'.$digitsOnly;
        }

        return $digitsOnly;
    }
}
