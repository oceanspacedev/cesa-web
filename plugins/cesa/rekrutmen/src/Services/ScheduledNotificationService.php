<?php

namespace Cesa\Rekrutmen\Services;

use Carbon\Carbon;
use Cesa\Rekrutmen\Jobs\SendScheduledCandidateNotificationJob;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\ScheduledNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ScheduledNotificationService
{
    /**
     * Schedule a notification for one or multiple candidates.
     *
     * @param  array<string, mixed>  $data
     */
    public function schedule(array $data, ?UploadedFile $attachment = null, ?int $creatorId = null): ScheduledNotification
    {
        $attachmentPath = null;
        $attachmentName = null;
        $attachmentMime = null;

        if ($attachment && $attachment->isValid()) {
            $attachmentName = $attachment->getClientOriginalName();
            $attachmentMime = $attachment->getMimeType();
            $attachmentPath = $attachment->store('rekrutmen/scheduled-attachments', 'local');
        }

        $scheduledAt = Carbon::parse($data['scheduled_at']);

        $channels = (array) ($data['channels'] ?? ['email']);
        if (empty($channels)) {
            $channels = ['email'];
        }

        $applicationIds = (array) ($data['application_ids'] ?? []);
        if (empty($applicationIds) && ! empty($data['application_id'])) {
            $applicationIds = [(int) $data['application_id']];
        }

        $notification = ScheduledNotification::create([
            'creator_id'           => $creatorId,
            'application_ids'      => array_values(array_map('intval', $applicationIds)),
            'channels'             => array_values($channels),
            'whatsapp_account_id'  => isset($data['whatsapp_account_id']) && is_numeric($data['whatsapp_account_id'])
                ? (int) $data['whatsapp_account_id']
                : null,
            'subject'              => (string) ($data['subject'] ?? ''),
            'body_message'         => (string) ($data['body_message'] ?? ''),
            'schedule'             => $data['schedule'] ?? null,
            'candidate_schedules'  => $data['candidate_schedules'] ?? null,
            'venue_or_method'      => $data['venue_or_method'] ?? null,
            'action_url'           => $data['action_url'] ?? null,
            'action_label'         => $data['action_label'] ?? null,
            'special_note'         => $data['special_note'] ?? null,
            'badge_text'           => $data['badge_text'] ?? 'Notifikasi Rekrutmen',
            'info_box_title'       => $data['info_box_title'] ?? 'Detail Informasi',
            'attachment_path'      => $attachmentPath,
            'attachment_name'      => $attachmentName,
            'attachment_mime'      => $attachmentMime,
            'scheduled_at'         => $scheduledAt,
            'status'               => ScheduledNotification::STATUS_PENDING,
        ]);

        // If scheduled time has already passed or is right now, execute immediately
        if (now()->gte($scheduledAt)) {
            $this->executeScheduled($notification);
        } elseif (config('queue.default') !== 'sync') {
            // Dispatch delayed queue job if queue worker is available
            $delayInSeconds = max(0, now()->diffInSeconds($scheduledAt, false));
            SendScheduledCandidateNotificationJob::dispatch($notification->id)->delay($delayInSeconds);
        }

        return $notification;
    }

    /**
     * Execute a specific scheduled notification record.
     *
     * @return array<string, mixed>
     */
    public function executeScheduled(ScheduledNotification $notification): array
    {
        if ($notification->status === ScheduledNotification::STATUS_SENT) {
            return $notification->results ?? [];
        }

        $notification->update(['status' => ScheduledNotification::STATUS_PROCESSING]);

        $applications = JobApplication::with(['jobPosting', 'currentStage'])
            ->whereIn('id', $notification->application_ids)
            ->get();

        $channels = (array) $notification->channels;
        $waNotifier = app(CandidateWhatsAppNotifier::class);

        $stats = [
            'total'            => $applications->count(),
            'email_success'    => 0,
            'email_failed'     => 0,
            'whatsapp_success' => 0,
            'whatsapp_failed'  => 0,
            'skipped_no_email' => 0,
            'skipped_no_phone' => 0,
        ];

        $details = [];

        $attachmentRealPath = null;
        if (! empty($notification->attachment_path) && Storage::disk('local')->exists($notification->attachment_path)) {
            $attachmentRealPath = Storage::disk('local')->path($notification->attachment_path);
        }

        $candidateSchedules = is_array($notification->candidate_schedules) ? $notification->candidate_schedules : [];

        foreach ($applications as $application) {
            $candidateName = $application->full_name;
            $jobTitle = $application->jobPosting?->title ?? ($application->position ?? 'Lowongan Kerja');
            $companyName = 'OCEAN SPACE';
            $location = $application->jobPosting?->location ?? 'Indonesia';

            $appCustom = $candidateSchedules[$application->id] ?? $candidateSchedules[(string) $application->id] ?? null;

            $appSchedule = is_array($appCustom)
                ? ($appCustom['schedule'] ?? $notification->schedule)
                : (is_string($appCustom) && ! empty($appCustom) ? $appCustom : $notification->schedule);

            $appVenue = is_array($appCustom) && ! empty($appCustom['venue_or_method'])
                ? $appCustom['venue_or_method']
                : $notification->venue_or_method;

            $actionUrl = trim($notification->action_url ?? '');
            if (is_array($appCustom) && ! empty($appCustom['action_url'])) {
                $actionUrl = trim($appCustom['action_url']);
            }
            if (! empty($actionUrl) && ! str_starts_with($actionUrl, 'http://') && ! str_starts_with($actionUrl, 'https://')) {
                $actionUrl = 'https://'.$actionUrl;
            }

            $subject = str_replace(
                ['{nama_pelamar}', '{posisi}', '{perusahaan}', '{lokasi}', '{jadwal}', '{schedule}'],
                [$candidateName, $jobTitle, $companyName, $location, (string) $appSchedule, (string) $appSchedule],
                $notification->subject
            );

            $bodyMessage = str_replace(
                ['{nama_pelamar}', '{posisi}', '{perusahaan}', '{lokasi}', '{link_aksi}', '{jadwal}', '{schedule}'],
                [$candidateName, $jobTitle, $companyName, $location, $actionUrl, (string) $appSchedule, (string) $appSchedule],
                $notification->body_message
            );

            $appDetail = [
                'id'       => $application->id,
                'name'     => $candidateName,
                'email'    => null,
                'whatsapp' => null,
            ];

            // 1. WhatsApp Channel
            if (in_array('whatsapp', $channels, true)) {
                $waResult = $waNotifier->send($application, [
                    'subject'              => $subject,
                    'body_message'         => $bodyMessage,
                    'schedule'             => $appSchedule,
                    'venue_or_method'      => $appVenue,
                    'action_url'           => $actionUrl,
                    'action_label'         => $notification->action_label,
                    'special_note'         => $notification->special_note,
                    'whatsapp_account_id'  => $notification->whatsapp_account_id,
                ]);

                if ($waResult['success']) {
                    $stats['whatsapp_success']++;
                    $appDetail['whatsapp'] = ['success' => true, 'phone' => $waResult['phone'] ?? null];
                } else {
                    $stats['whatsapp_failed']++;
                    $appDetail['whatsapp'] = ['success' => false, 'message' => $waResult['message']];
                }
            }

            // 2. Email Channel
            if (in_array('email', $channels, true)) {
                if (empty($application->email)) {
                    $stats['skipped_no_email']++;
                    $appDetail['email'] = ['success' => false, 'message' => 'Alamat email kosong'];
                } else {
                    $infoItems = [];
                    $infoItems[] = ['label' => 'Posisi Lowongan', 'value' => $jobTitle];
                    $infoItems[] = ['label' => 'Perusahaan', 'value' => $companyName];
                    if (! empty($location)) {
                        $infoItems[] = ['label' => 'Penempatan', 'value' => $location];
                    }
                    if (! empty($appSchedule)) {
                        $infoItems[] = ['label' => 'Jadwal / Waktu', 'value' => $appSchedule];
                    }
                    if (! empty($appVenue)) {
                        $infoItems[] = ['label' => 'Metode / Lokasi', 'value' => $appVenue];
                    }
                    if (! empty($actionUrl)) {
                        $infoItems[] = ['label' => 'Tautan / Link Akses', 'value' => $actionUrl];
                    }

                    try {
                        app(RekrutmenMailer::class)->send('rekrutmen::mail.candidate-stage-notification', [
                            'subject'        => $subject,
                            'badge_text'     => $notification->badge_text ?? 'Notifikasi Rekrutmen',
                            'position_title' => $jobTitle,
                            'recipient_name' => $candidateName,
                            'body_message'   => $bodyMessage,
                            'info_box_title' => $notification->info_box_title ?? 'Detail Informasi',
                            'info_items'     => $infoItems,
                            'action_url'     => $actionUrl,
                            'action_label'   => $notification->action_label,
                            'special_note'   => $notification->special_note,
                            'logo_url'       => 'https://oceanspace.co.id/images/logo-color.png',
                            'has_attachment' => ! empty($attachmentRealPath),
                        ], function ($message) use ($application, $subject, $attachmentRealPath, $notification) {
                            $message->to($application->email, $application->full_name)
                                ->subject($subject);

                            if ($attachmentRealPath) {
                                $message->attach($attachmentRealPath, [
                                    'as'   => $notification->attachment_name ?: 'document.pdf',
                                    'mime' => $notification->attachment_mime ?: 'application/pdf',
                                ]);
                            }
                        });

                        $stats['email_success']++;
                        $appDetail['email'] = ['success' => true, 'recipient' => $application->email];
                    } catch (Throwable $e) {
                        Log::error("Failed sending scheduled email to {$application->email}: ".$e->getMessage());
                        $stats['email_failed']++;
                        $appDetail['email'] = ['success' => false, 'message' => $e->getMessage()];
                    }
                }
            }

            $details[] = $appDetail;
        }

        $isSuccessful = ($stats['email_success'] > 0 || $stats['whatsapp_success'] > 0 || $stats['total'] === 0);

        $notification->update([
            'status'     => $isSuccessful ? ScheduledNotification::STATUS_SENT : ScheduledNotification::STATUS_FAILED,
            'sent_at'    => now(),
            'results'    => [
                'stats'   => $stats,
                'details' => $details,
            ],
        ]);

        return $stats;
    }

    /**
     * Process all scheduled notifications that are due.
     */
    public function processDueNotifications(): int
    {
        $dueNotifications = ScheduledNotification::due()->get();
        $processedCount = 0;

        foreach ($dueNotifications as $notification) {
            try {
                $this->executeScheduled($notification);
                $processedCount++;
            } catch (Throwable $e) {
                Log::error("Error executing scheduled notification #{$notification->id}: ".$e->getMessage());
                $notification->update([
                    'status'        => ScheduledNotification::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        return $processedCount;
    }
}
