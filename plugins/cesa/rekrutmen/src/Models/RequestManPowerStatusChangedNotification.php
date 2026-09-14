<?php

namespace Cesa\Rekrutmen\Models;

use Cesa\Rekrutmen\Concerns\ConfiguresRekrutmenMail;
use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RequestManPowerStatusChangedNotification extends Notification implements ShouldQueue
{
    use ConfiguresRekrutmenMail;
    use Queueable;

    public function __construct(
        private readonly RequestManPower $requestManPower,
        private readonly ?RequestManPowerStatus $fromStatus,
        private readonly RequestManPowerStatus $toStatus,
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
                ->subject(__('rekrutmen::mail/request-man-power-status-changed.subject'))
                ->action(
                    __('rekrutmen::mail/request-man-power-status-changed.view_progress'),
                    $this->requestManPower->getPublicProgressUrl(),
                )
                ->view('rekrutmen::mail.request-man-power-status-changed', [
                    'request'     => $this->requestManPower,
                    'summary'     => $this->buildSummary(),
                    'progressUrl' => $this->requestManPower->getPublicProgressUrl(),
                ])
        );
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function buildSummary(): array
    {
        $summary = [
            [
                'label' => __('rekrutmen::mail/request-man-power-status-changed.summary_fields.submission_id'),
                'value' => $this->requestManPower->status_response_id ?? '-',
            ],
            [
                'label' => __('rekrutmen::mail/request-man-power-status-changed.summary_fields.applicant'),
                'value' => $this->requestManPower->nama_pengaju ?? '-',
            ],
            [
                'label' => __('rekrutmen::mail/request-man-power-status-changed.summary_fields.position'),
                'value' => $this->requestManPower->posisi_dibutuhkan ?? '-',
            ],
            [
                'label' => __('rekrutmen::mail/request-man-power-status-changed.summary_fields.latest_status'),
                'value' => $this->toStatus->getLabel(),
            ],
        ];

        if ($this->fromStatus) {
            $summary[] = [
                'label' => __('rekrutmen::mail/request-man-power-status-changed.summary_fields.previous_status'),
                'value' => $this->fromStatus->getLabel(),
            ];
        }

        if ($this->toStatus === RequestManPowerStatus::HOLD && filled($this->requestManPower->hold_reason)) {
            $summary[] = [
                'label' => __('rekrutmen::mail/request-man-power-status-changed.summary_fields.hold_reason'),
                'value' => $this->requestManPower->hold_reason,
            ];
        }

        $summary[] = [
            'label' => __('rekrutmen::mail/request-man-power-status-changed.summary_fields.division'),
            'value' => $this->requestManPower->division_name ?? '-',
        ];

        $summary[] = [
            'label' => __('rekrutmen::mail/request-man-power-status-changed.summary_fields.business_entity'),
            'value' => $this->requestManPower->business_entity_name ?? '-',
        ];

        return $summary;
    }
}
