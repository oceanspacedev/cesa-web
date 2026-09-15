<?php

namespace Cesa\Rekrutmen\Services;

use Carbon\Carbon;
use Cesa\Rekrutmen\Jobs\SendScheduledCandidateNotificationJob;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\NotificationDelivery;
use Cesa\Rekrutmen\Models\ScheduledNotification;
use Cesa\Rekrutmen\Models\WhatsAppAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScheduledNotificationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function schedule(array $data, ?UploadedFile $attachment = null, ?int $creatorId = null, bool $dispatch = true): ScheduledNotification
    {
        $requestKey = (string) ($data['request_key'] ?? Str::uuid());
        $applicationIds = array_values(array_unique(array_map('intval', (array) ($data['application_ids'] ?? [$data['application_id'] ?? 0]))));
        sort($applicationIds);
        $channels = array_values(array_unique((array) ($data['channels'] ?? ['email'])));
        if ($channels === []) {
            $channels = ['email'];
        }
        sort($channels);
        $candidateSchedules = $data['candidate_schedules'] ?? null;
        if (is_string($candidateSchedules)) {
            $candidateSchedules = json_decode($candidateSchedules, true);
        }

        $values = [
            'creator_id'          => $creatorId,
            'application_ids'     => $applicationIds,
            'channels'            => $channels,
            'whatsapp_account_id' => isset($data['whatsapp_account_id']) ? (int) $data['whatsapp_account_id'] : null,
            'template_key'        => $data['template_key'] ?? null,
            'subject'             => (string) ($data['subject'] ?? ''),
            'body_message'        => (string) ($data['body_message'] ?? ''),
            'schedule'            => $data['schedule'] ?? null,
            'candidate_schedules' => $candidateSchedules,
            'venue_or_method'     => $data['venue_or_method'] ?? null,
            'action_url'          => $data['action_url'] ?? null,
            'action_label'        => $data['action_label'] ?? null,
            'special_note'        => $data['special_note'] ?? null,
            'badge_text'          => $data['badge_text'] ?? 'Notifikasi Rekrutmen',
            'info_box_title'      => $data['info_box_title'] ?? 'Detail Informasi',
        ];
        $fingerprint = hash('sha256', json_encode([
            $values,
            ($data['send_type'] ?? null) === 'immediate' ? null : ($data['scheduled_at'] ?? null),
            $attachment ? hash_file('sha256', $attachment->getRealPath()) : null,
        ], JSON_THROW_ON_ERROR));

        $notification = (new ScheduledNotification)->getConnection()->transaction(function () use ($requestKey, $values, $fingerprint, $data, $attachment): ScheduledNotification {
            $notification = ScheduledNotification::query()->firstOrCreate(['request_key' => $requestKey], $values + [
                'payload_hash' => $fingerprint,
                'scheduled_at' => isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : now(),
                'status'       => ScheduledNotification::STATUS_PENDING,
            ]);

            if ($notification->payload_hash !== $fingerprint) {
                throw ValidationException::withMessages(['request_key' => 'Kunci permintaan sudah digunakan untuk notifikasi yang berbeda.']);
            }
            if ($notification->wasRecentlyCreated) {
                if ($attachment && $attachment->isValid()) {
                    $storage = app(RekrutmenStorage::class);
                    $disk = $storage->disk();
                    $notification->update([
                        'attachment_path' => $storage->storeUploadedFile($attachment, 'rekrutmen/scheduled-attachments', $disk),
                        'attachment_disk' => $disk,
                        'attachment_name' => $attachment->getClientOriginalName(),
                        'attachment_mime' => $attachment->getMimeType(),
                    ]);
                }
                $this->createSnapshots($notification);
            }

            return $notification;
        });

        if ($dispatch) {
            if ($notification->scheduled_at->isFuture()) {
                SendScheduledCandidateNotificationJob::dispatch($notification->id)
                    ->onConnection(app(NotificationDeliveryService::class)->queueConnection('email'))
                    ->delay($notification->scheduled_at)->afterCommit();
            } else {
                $this->executeScheduled($notification);
            }
        }

        return $notification->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function executeScheduled(ScheduledNotification $notification, bool $synchronously = false): array
    {
        $notification->refresh();
        if ($notification->scheduled_at->isFuture()
            || in_array($notification->status, [ScheduledNotification::STATUS_CANCELLED, ScheduledNotification::STATUS_SENT], true)) {
            return $this->progress($notification);
        }

        $this->initializeLegacyNotification($notification);
        $deliveryService = app(NotificationDeliveryService::class);
        $deliveryService->recoverStale();
        foreach ($notification->deliveries()->where('status', NotificationDelivery::STATUS_PENDING)->orderBy('id')->get() as $delivery) {
            if ($synchronously && ! $delivery->available_at->isFuture()) {
                $deliveryService->execute($delivery);
            } else {
                $deliveryService->dispatch($delivery);
            }
        }

        return $this->progress($notification);
    }

    /**
     * @return array{id: int, status: string, stats: array<string, int>, details: array<int, array<string, mixed>>}
     */
    public function progress(ScheduledNotification $notification): array
    {
        app(NotificationDeliveryService::class)->reconcile($notification->id);

        return $notification->getConnection()->transaction(function () use ($notification): array {
            $notification = ScheduledNotification::query()->whereKey($notification->id)->lockForUpdate()->firstOrFail();
            $deliveries = $notification->deliveries()->orderBy('id')->get();
            if ($deliveries->isEmpty()) {
                return [
                    'id'      => $notification->id,
                    'status'  => $notification->status,
                    'stats'   => $notification->results['stats'] ?? $this->emptyStats(count($notification->application_ids)),
                    'details' => $notification->results['details'] ?? [],
                ];
            }

            $stats = $this->emptyStats(count($notification->application_ids));
            $details = [];
            foreach ($deliveries as $delivery) {
                $id = $delivery->application_id;
                $details[$id] ??= ['id' => $id, 'name' => $delivery->recipient_name, 'email' => null, 'whatsapp' => null];
                $channel = $delivery->channel;
                $status = $delivery->status;
                $details[$id][$channel] = [
                    'status'                                        => $status,
                    'success'                                       => $status === NotificationDelivery::STATUS_SENT,
                    'message'                                       => $delivery->error_message,
                    'attempts'                                      => $delivery->attempts,
                    $channel === 'whatsapp' ? 'phone' : 'recipient' => $delivery->recipient,
                    'account_id'                                    => $delivery->whatsapp_account_id,
                    'stage_error'                                   => $delivery->stage_error,
                ];
                if ($status === NotificationDelivery::STATUS_SENT) {
                    $stats[$channel.'_success']++;
                } elseif (in_array($status, [NotificationDelivery::STATUS_PENDING, NotificationDelivery::STATUS_SENDING], true)) {
                    $stats[$channel.'_pending']++;
                } elseif ($status === NotificationDelivery::STATUS_UNKNOWN) {
                    $stats[$channel.'_unknown']++;
                } elseif ($status === NotificationDelivery::STATUS_SKIPPED) {
                    $stats[$channel === 'whatsapp' ? 'skipped_no_phone' : 'skipped_no_email']++;
                } else {
                    $stats[$channel.'_failed']++;
                }
            }

            $successes = $stats['email_success'] + $stats['whatsapp_success'];
            $pending = $stats['email_pending'] + $stats['whatsapp_pending'];
            $unknown = $stats['email_unknown'] + $stats['whatsapp_unknown'];
            $status = match (true) {
                $notification->status === ScheduledNotification::STATUS_CANCELLED     => ScheduledNotification::STATUS_CANCELLED,
                $deliveries->contains('status', NotificationDelivery::STATUS_SENDING) => ScheduledNotification::STATUS_PROCESSING,
                $pending > 0                                                          => ScheduledNotification::STATUS_PENDING,
                $unknown > 0                                                          => ScheduledNotification::STATUS_UNKNOWN,
                $successes === $deliveries->count()                                   => ScheduledNotification::STATUS_SENT,
                $successes > 0                                                        => ScheduledNotification::STATUS_PARTIAL,
                default                                                               => ScheduledNotification::STATUS_FAILED,
            };
            $details = array_values($details);
            $notification->update([
                'status'        => $status,
                'results'       => ['stats' => $stats, 'details' => $details],
                'sent_at'       => $status === ScheduledNotification::STATUS_SENT ? ($notification->sent_at ?? now()) : null,
                'error_message' => $status === ScheduledNotification::STATUS_UNKNOWN
                    ? 'Ada pengiriman yang hasilnya belum pasti. Periksa percakapan sebelum mengirim ulang.' : null,
            ]);

            return ['id' => $notification->id, 'status' => $status, 'stats' => $stats, 'details' => $details];
        });
    }

    public function processDueNotifications(): int
    {
        $service = app(NotificationDeliveryService::class);
        $service->recoverStale();
        $service->reconcile();
        $notifications = ScheduledNotification::query()
            ->whereIn('status', [ScheduledNotification::STATUS_PENDING, ScheduledNotification::STATUS_PROCESSING, ScheduledNotification::STATUS_UNKNOWN])
            ->where('scheduled_at', '<=', now())->get();
        foreach ($notifications as $notification) {
            $this->executeScheduled($notification);
        }
        foreach (NotificationDelivery::query()->whereNull('scheduled_notification_id')
            ->where('status', NotificationDelivery::STATUS_PENDING)->where('available_at', '<=', now())->get() as $delivery) {
            $service->dispatch($delivery);
        }

        return $notifications->count();
    }

    protected function initializeLegacyNotification(ScheduledNotification $notification): void
    {
        $notification->getConnection()->transaction(function () use ($notification): void {
            $locked = ScheduledNotification::query()->whereKey($notification->id)->lockForUpdate()->firstOrFail();
            if ($locked->deliveries()->exists()) {
                return;
            }
            if ($locked->status === ScheduledNotification::STATUS_PENDING) {
                $this->createSnapshots($locked);
            } elseif ($locked->status === ScheduledNotification::STATUS_PROCESSING) {
                $locked->update([
                    'status'        => ScheduledNotification::STATUS_UNKNOWN,
                    'error_message' => 'Pengiriman lama belum memiliki catatan per penerima; periksa hasil sebelum mengirim ulang.',
                ]);
            }
        });
    }

    protected function createSnapshots(ScheduledNotification $notification): void
    {
        $applications = JobApplication::query()->with(['jobPosting', 'currentStage'])
            ->whereIn('id', $notification->application_ids)->get()->keyBy('id');
        $account = in_array('whatsapp', $notification->channels, true)
            ? WhatsAppAccount::resolveForSend($notification->whatsapp_account_id) : null;
        $accountId = $notification->whatsapp_account_id ?? $account?->id;
        $notification->update(['whatsapp_account_id' => $accountId]);

        foreach ($notification->application_ids as $applicationId) {
            $application = $applications->get($applicationId);
            $payload = $application ? $this->candidatePayload($notification, $application) : [];
            $stage = $application ? app(CandidateNotificationStageService::class)->snapshot($application, $notification->template_key) : null;
            foreach ($notification->channels as $channel) {
                $recipient = $application ? ($channel === 'whatsapp'
                    ? app(CandidateWhatsAppNotifier::class)->resolveCandidatePhone($application) : $application->email) : null;
                $notification->deliveries()->create([
                    'application_id'      => $applicationId,
                    'request_key'         => hash('sha256', 'candidate:'.($notification->request_key ?? $notification->id).':'.$applicationId.':'.$channel),
                    'channel'             => $channel,
                    'recipient'           => $recipient ?: null,
                    'recipient_name'      => $application?->full_name ?? 'Kandidat #'.$applicationId,
                    'whatsapp_account_id' => $channel === 'whatsapp' ? $accountId : null,
                    'payload'             => $payload,
                    'stage_snapshot'      => $stage,
                    'status'              => $recipient ? NotificationDelivery::STATUS_PENDING : NotificationDelivery::STATUS_SKIPPED,
                    'error_message'       => $recipient ? null : 'Alamat tujuan tidak tersedia atau tidak valid.',
                    'available_at'        => $notification->scheduled_at,
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function candidatePayload(ScheduledNotification $notification, JobApplication $application): array
    {
        $custom = $notification->candidate_schedules[$application->id] ?? null;
        $schedule = is_array($custom) ? ($custom['schedule'] ?? $notification->schedule)
            : (is_string($custom) && $custom !== '' ? $custom : $notification->schedule);
        $venue = is_array($custom) ? (($custom['venue_or_method'] ?? null) ?: $notification->venue_or_method) : $notification->venue_or_method;
        $url = trim((string) (is_array($custom) ? (($custom['action_url'] ?? null) ?: $notification->action_url) : $notification->action_url));
        if ($url !== '' && ! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://'.$url;
        }
        $position = $application->jobPosting?->title ?? 'Lowongan Kerja';
        $location = $application->jobPosting?->location ?? 'Indonesia';
        $replace = [
            '{nama_pelamar}' => $application->full_name,
            '{posisi}'       => $position,
            '{perusahaan}'   => 'OCEAN SPACE',
            '{lokasi}'       => $location,
            '{jadwal}'       => (string) $schedule,
            '{schedule}'     => (string) $schedule,
            '{link_aksi}'    => $url,
        ];
        $subject = strtr($notification->subject, $replace);
        $body = strtr($notification->body_message, $replace);
        $data = [
            'subject'         => $subject,
            'body_message'    => $body,
            'schedule'        => $schedule,
            'venue_or_method' => $venue,
            'action_url'      => $url,
            'action_label'    => $notification->action_label,
            'special_note'    => $notification->special_note,
        ];
        $info = [
            ['label' => 'Posisi Lowongan', 'value' => $position],
            ['label' => 'Perusahaan', 'value' => 'OCEAN SPACE'],
        ];
        foreach (['Penempatan' => $location, 'Jadwal / Waktu' => $schedule, 'Metode / Lokasi' => $venue, 'Tautan / Link Akses' => $url] as $label => $value) {
            if (filled($value)) {
                $info[] = ['label' => $label, 'value' => $value];
            }
        }

        return [
            'text'            => app(CandidateWhatsAppNotifier::class)->buildCandidateMessage($application, $data),
            'subject'         => $subject,
            'attachment_path' => $notification->attachment_path,
            'attachment_disk' => $notification->attachment_disk ?? 'local',
            'attachment_name' => $notification->attachment_name,
            'attachment_mime' => $notification->attachment_mime,
            'email_data'      => [
                'subject'        => $subject,
                'badge_text'     => $notification->badge_text,
                'position_title' => $position,
                'recipient_name' => $application->full_name,
                'body_message'   => $body,
                'info_box_title' => $notification->info_box_title,
                'info_items'     => $info,
                'action_url'     => $url,
                'action_label'   => $notification->action_label,
                'special_note'   => $notification->special_note,
                'logo_url'       => 'https://oceanspace.co.id/images/logo-color.png',
                'has_attachment' => filled($notification->attachment_path),
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function emptyStats(int $total): array
    {
        return [
            'total'            => $total,
            'email_success'    => 0,
            'email_failed'     => 0,
            'email_pending'    => 0,
            'email_unknown'    => 0,
            'whatsapp_success' => 0,
            'whatsapp_failed'  => 0,
            'whatsapp_pending' => 0,
            'whatsapp_unknown' => 0,
            'skipped_no_email' => 0,
            'skipped_no_phone' => 0,
        ];
    }
}
