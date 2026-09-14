<?php

namespace Cesa\Rekrutmen\Models;

use Cesa\Rekrutmen\Concerns\ConfiguresRekrutmenMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RequestManPowerApprovalRequestedNotification extends Notification implements ShouldQueue
{
    use ConfiguresRekrutmenMail;
    use Queueable;

    public function __construct(
        private readonly RequestManPower $requestManPower,
        private readonly RequestManPowerApproval $approval,
    ) {
        $this->onQueue(config('rekrutmen.notifications.queue', 'notifications'));
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureRekrutmenMail(
            (new MailMessage)
                ->subject(__('rekrutmen::mail/request-man-power-approval-request.subject'))
                ->action(
                    __('rekrutmen::mail/request-man-power-approval-request.action'),
                    $this->approval->buildApprovalUrl(),
                )
                ->view('rekrutmen::mail.request-man-power-approval-request', [
                    'request'      => $this->requestManPower,
                    'approverName' => $this->approval->approver_name,
                    'summary'      => $this->buildSummary(),
                    'actionUrl'    => $this->approval->buildApprovalUrl(),
                    'progressUrl'  => $this->requestManPower->getPublicProgressUrl(),
                ])
        );
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function buildSummary(): array
    {
        return [
            [
                'label' => __('rekrutmen::mail/request-man-power-approval-request.summary_fields.submission_id'),
                'value' => $this->requestManPower->status_response_id ?? '-',
            ],
            [
                'label' => __('rekrutmen::mail/request-man-power-approval-request.summary_fields.submission_date'),
                'value' => $this->requestManPower->getTanggalPengajuanFormattedAttribute(),
            ],
            [
                'label' => __('rekrutmen::mail/request-man-power-approval-request.summary_fields.applicant'),
                'value' => $this->requestManPower->nama_pengaju ?? '-',
            ],
            [
                'label' => __('rekrutmen::mail/request-man-power-approval-request.summary_fields.position'),
                'value' => $this->requestManPower->posisi_dibutuhkan ?? '-',
            ],
            [
                'label' => __('rekrutmen::mail/request-man-power-approval-request.summary_fields.requirement'),
                'value' => $this->requestManPower->status_kebutuhan?->getLabel() ?? '-',
            ],
            [
                'label' => __('rekrutmen::mail/request-man-power-approval-request.summary_fields.division'),
                'value' => $this->requestManPower->division_name ?? '-',
            ],
            [
                'label' => __('rekrutmen::mail/request-man-power-approval-request.summary_fields.business_entity'),
                'value' => $this->requestManPower->business_entity_name ?? '-',
            ],
            [
                'label' => __('rekrutmen::mail/request-man-power-approval-request.summary_fields.estimated_join'),
                'value' => $this->requestManPower->getEstimasiTanggalJoinFormattedAttribute(),
            ],
        ];
    }
}
