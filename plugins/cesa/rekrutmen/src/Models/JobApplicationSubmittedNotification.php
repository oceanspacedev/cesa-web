<?php

namespace Cesa\Rekrutmen\Models;

use Cesa\Rekrutmen\Concerns\ConfiguresRekrutmenMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JobApplicationSubmittedNotification extends Notification implements ShouldQueue
{
    use ConfiguresRekrutmenMail;
    use Queueable;

    public function __construct(private readonly JobApplication $jobApplication)
    {
        $this->onQueue(config('rekrutmen.notifications.queue', 'notifications'));
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $position = $this->jobApplication->jobPosting?->title;

        $message = (new MailMessage)
            ->subject(
                is_string($position) && $position !== ''
                    ? __('rekrutmen::mail/job-application-submitted.subject', ['position' => $position])
                    : __('rekrutmen::mail/job-application-submitted.subject_generic')
            )
            ->view('rekrutmen::mail.job-application-submitted', [
                'heading'        => __('rekrutmen::mail/job-application-submitted.heading'),
                'greeting'       => __('rekrutmen::mail/job-application-submitted.greeting', ['name' => $this->jobApplication->full_name]),
                'body'           => __('rekrutmen::mail/job-application-submitted.body'),
                'summaryHeading' => __('rekrutmen::mail/job-application-submitted.summary_heading'),
                'summary'        => $this->buildSummary(),
                'progressUrl'    => null,
                'actionLabel'    => null,
                'footerNote'     => __('rekrutmen::mail/job-application-submitted.footer_note'),
            ]);

        return $this->configureRekrutmenMail($message);
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function buildSummary(): array
    {
        return [
            [
                'label' => __('rekrutmen::mail/job-application-submitted.summary_fields.application_id'),
                'value' => (string) $this->jobApplication->getKey(),
            ],
            [
                'label' => __('rekrutmen::mail/job-application-submitted.summary_fields.submission_date'),
                'value' => $this->jobApplication->created_at?->translatedFormat('d F Y H:i') ?? '-',
            ],
            [
                'label' => __('rekrutmen::mail/job-application-submitted.summary_fields.position'),
                'value' => $this->jobApplication->jobPosting?->title ?? '-',
            ],
        ];
    }
}
