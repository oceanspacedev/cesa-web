<?php

namespace Cesa\Rekrutmen\Services;

use Cesa\Rekrutmen\Jobs\SendNotificationDeliveryJob;
use Cesa\Rekrutmen\Models\NotificationDelivery;
use Cesa\Rekrutmen\Models\ScheduledNotification;
use Cesa\Rekrutmen\Models\WhatsAppAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class NotificationDeliveryService
{
    public function queueConnection(string $channel = 'whatsapp'): string
    {
        $connection = $channel === 'whatsapp' ? config('rekrutmen.notifications.whatsapp.connection') : null;
        $connection = $connection ?: config('queue.default', 'database');

        return in_array(config('queue.connections.'.$connection.'.driver'), ['sync', 'null'], true) ? 'database' : $connection;
    }

    public function jobTimeout(string $channel = 'whatsapp'): int
    {
        $requested = max(1, (int) config('rekrutmen.notifications.whatsapp.job_timeout', 60));
        $retryAfter = config('queue.connections.'.$this->queueConnection($channel).'.retry_after');

        return is_numeric($retryAfter) ? min($requested, max(1, (int) $retryAfter - 5)) : $requested;
    }

    public function dispatch(NotificationDelivery $delivery): void
    {
        $queuedAt = now();
        $claimed = NotificationDelivery::query()->whereKey($delivery->id)
            ->where('status', NotificationDelivery::STATUS_PENDING)
            ->where(function (Builder $query): void {
                $query->whereNull('queued_at')->orWhere('queued_at', '<=', now()->subMinutes(10));
            })
            ->update(['queued_at' => $queuedAt]);

        if (! $claimed) {
            return;
        }

        try {
            SendNotificationDeliveryJob::dispatch($delivery->id, $delivery->channel)
                ->onConnection($this->queueConnection($delivery->channel))
                ->onQueue($delivery->channel === 'whatsapp'
                    ? config('rekrutmen.notifications.whatsapp.queue', 'whatsapp')
                    : config('rekrutmen.notifications.queue', 'notifications'))
                ->delay($delivery->available_at)
                ->afterCommit();
        } catch (Throwable $exception) {
            NotificationDelivery::query()->whereKey($delivery->id)->where('status', NotificationDelivery::STATUS_PENDING)
                ->where('queued_at', $queuedAt)->update(['queued_at' => null]);
            throw $exception;
        }
    }

    public function execute(NotificationDelivery $delivery): NotificationDelivery
    {
        $this->recoverStale($delivery->id);
        $delivery->refresh();

        if ($delivery->status !== NotificationDelivery::STATUS_PENDING || $delivery->available_at->isFuture()) {
            return $delivery;
        }

        if ($delivery->scheduled_notification_id) {
            $notification = $delivery->notification;
            if (! $notification || $notification->status === ScheduledNotification::STATUS_CANCELLED) {
                NotificationDelivery::query()->whereKey($delivery->id)->where('status', NotificationDelivery::STATUS_PENDING)
                    ->update(['status' => NotificationDelivery::STATUS_CANCELLED, 'queued_at' => null]);

                return $delivery->refresh();
            }
            if ($notification->scheduled_at->isFuture()) {
                return $delivery;
            }
        }

        $token = (string) Str::uuid();
        $claimed = NotificationDelivery::query()->whereKey($delivery->id)
            ->where('status', NotificationDelivery::STATUS_PENDING)->where('available_at', '<=', now())
            ->increment('attempts', 1, [
                'status'           => NotificationDelivery::STATUS_SENDING,
                'claim_token'      => $token,
                'claimed_at'       => now(),
                'lease_expires_at' => now()->addSeconds(max(
                    (int) config('rekrutmen.notifications.whatsapp.job_timeout', 60) + 30,
                    (int) config('rekrutmen.notifications.delivery.lease_seconds', 120),
                )),
                'queued_at' => null,
            ]);

        if (! $claimed) {
            return $delivery->refresh();
        }

        $delivery->refresh();
        $externalCallStarted = false;
        try {
            if ($delivery->application_id && ! $delivery->application()->exists()) {
                $this->finish($delivery, $token, NotificationDelivery::STATUS_SKIPPED, 'Kandidat sudah dihapus.');
            } elseif (! $delivery->recipient) {
                $this->finish($delivery, $token, NotificationDelivery::STATUS_SKIPPED, 'Alamat tujuan tidak tersedia atau tidak valid.');
            } elseif ($delivery->channel === 'whatsapp') {
                $account = WhatsAppAccount::query()->active()->whereKey($delivery->whatsapp_account_id)->first();
                if (! $account) {
                    $this->finish($delivery, $token, NotificationDelivery::STATUS_FAILED, 'Nomor WhatsApp pengirim yang dipilih tidak tersedia.');
                } else {
                    $delay = app(WhatsAppThrottleService::class)->acquireSendSlot($account->id);
                    if ($delay > 0) {
                        $this->owned($delivery, $token)->decrement('attempts', 1, [
                            'status'           => NotificationDelivery::STATUS_PENDING,
                            'available_at'     => now()->addSeconds($delay),
                            'claim_token'      => null,
                            'lease_expires_at' => null,
                        ]);
                    } else {
                        $externalCallStarted = true;
                        $result = app(WhatsAppGateway::class)->sendText($account, $delivery->recipient, (string) ($delivery->payload['text'] ?? ''), [
                            'idempotency_key'  => $delivery->request_key,
                            'client_reference' => $delivery->request_key,
                            'mode'             => 'async',
                            'purpose'          => 'notification',
                        ]);
                        $this->recordResult($delivery, $token, $result);
                    }
                }
            } else {
                $attachment = $this->prepareEmailAttachment($delivery);
                $externalCallStarted = true;
                $this->sendEmail($delivery, $attachment);
                $this->finish($delivery, $token, NotificationDelivery::STATUS_SENT);
            }
        } catch (Throwable $exception) {
            $this->finish($delivery, $token, $externalCallStarted ? NotificationDelivery::STATUS_UNKNOWN : NotificationDelivery::STATUS_FAILED, $exception->getMessage());
        }

        $delivery->refresh();
        if ($delivery->status === NotificationDelivery::STATUS_SENT) {
            $this->applyStage($delivery);
        }
        if ($delivery->scheduled_notification_id && $delivery->notification) {
            app(ScheduledNotificationService::class)->progress($delivery->notification);
        }
        if ($delivery->status === NotificationDelivery::STATUS_PENDING) {
            $this->dispatch($delivery);
        }

        return $delivery;
    }

    public function recoverStale(?int $deliveryId = null): int
    {
        return NotificationDelivery::query()
            ->when($deliveryId, fn (Builder $query): Builder => $query->whereKey($deliveryId))
            ->where('status', NotificationDelivery::STATUS_SENDING)
            ->where('lease_expires_at', '<=', now())
            ->update([
                'status'           => NotificationDelivery::STATUS_UNKNOWN,
                'error_message'    => 'Proses pengiriman terhenti; hasil belum dapat dipastikan. Periksa percakapan sebelum mengirim ulang.',
                'claim_token'      => null,
                'lease_expires_at' => null,
                'queued_at'        => null,
            ]);
    }

    public function reconcile(?int $notificationId = null): void
    {
        $this->recoverStale();
        $unknownDeliveries = NotificationDelivery::query()->with('account')
            ->when($notificationId, fn (Builder $query): Builder => $query->where('scheduled_notification_id', $notificationId))
            ->where('channel', 'whatsapp')->where('status', NotificationDelivery::STATUS_UNKNOWN)
            ->where(function (Builder $query): void {
                $query->whereNull('last_reconciled_at')->orWhere('last_reconciled_at', '<=', now()->subSeconds(10));
            })->orderBy('last_reconciled_at')->orderBy('id')->get();

        if ($unknownDeliveries->isNotEmpty()) {
            $deadline = $this->monotonicTime() + max(0.1, (float) config('rekrutmen.notifications.delivery.reconcile_budget_seconds', 3));
            $gateway = app(WhatsAppGateway::class);
            try {
                $engineReady = $gateway->engineReady();
            } catch (Throwable) {
                $engineReady = false;
            }

            if ($engineReady) {
                foreach ($unknownDeliveries as $delivery) {
                    if ($this->monotonicTime() >= $deadline) {
                        break;
                    }

                    $delivery->update(['last_reconciled_at' => now()]);
                    if (! $delivery->account) {
                        continue;
                    }
                    $result = $gateway->messageResult($delivery->account, $delivery->request_key);
                    $status = $result['status'] ?? null;
                    if (! in_array($status, [NotificationDelivery::STATUS_SENT, NotificationDelivery::STATUS_FAILED], true)) {
                        continue;
                    }
                    NotificationDelivery::query()->whereKey($delivery->id)->where('status', NotificationDelivery::STATUS_UNKNOWN)->update([
                        'status'              => $status,
                        'sent_at'             => $status === NotificationDelivery::STATUS_SENT ? now() : null,
                        'provider_message_id' => $result['id'] ?? $result['message_id'] ?? null,
                        'error_message'       => $status === NotificationDelivery::STATUS_SENT ? null : ($result['message'] ?? 'Pengiriman gagal.'),
                    ]);
                }
            }
        }

        foreach (NotificationDelivery::query()
            ->when($notificationId, fn (Builder $query): Builder => $query->where('scheduled_notification_id', $notificationId))
            ->where('status', NotificationDelivery::STATUS_SENT)->whereNotNull('stage_snapshot')
            ->whereNull('stage_applied_at')->get() as $delivery) {
            $this->applyStage($delivery);
        }
    }

    protected function monotonicTime(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    protected function applyStage(NotificationDelivery $delivery): void
    {
        try {
            app(CandidateNotificationStageService::class)->applyAfterSuccess($delivery);
            $delivery->update(['stage_error' => null]);
        } catch (Throwable $exception) {
            $delivery->update(['stage_error' => $exception->getMessage()]);
            Log::error('Recruitment notification sent but stage update failed.', ['delivery_id' => $delivery->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function recordResult(NotificationDelivery $delivery, string $token, array $result): void
    {
        if ($result['success'] ?? false) {
            $providerId = $result['data']['id'] ?? $result['data']['message_id'] ?? null;
            $this->finish($delivery, $token, NotificationDelivery::STATUS_SENT, null, is_string($providerId) ? $providerId : null);

            return;
        }

        $status = ($result['status'] ?? 'failed') === 'failed' ? NotificationDelivery::STATUS_FAILED : NotificationDelivery::STATUS_UNKNOWN;
        if ($status === NotificationDelivery::STATUS_FAILED && ($result['retryable'] ?? false)
            && $delivery->attempts < max(1, (int) config('rekrutmen.notifications.whatsapp.tries', 3))) {
            $backoff = config('rekrutmen.notifications.whatsapp.backoff', [10, 30, 60]);
            $delay = max(1, (int) ($backoff[$delivery->attempts - 1] ?? 60));
            $this->owned($delivery, $token)->update([
                'status'           => NotificationDelivery::STATUS_PENDING,
                'available_at'     => now()->addSeconds($delay),
                'error_message'    => $result['message'] ?? 'Pengiriman ditunda.',
                'claim_token'      => null,
                'lease_expires_at' => null,
            ]);

            return;
        }

        $this->finish($delivery, $token, $status, $result['message'] ?? 'Pengiriman gagal.');
    }

    protected function finish(NotificationDelivery $delivery, string $token, string $status, ?string $message = null, ?string $providerId = null): void
    {
        $this->owned($delivery, $token)->update([
            'status'              => $status,
            'error_message'       => $message,
            'provider_message_id' => $providerId,
            'sent_at'             => $status === NotificationDelivery::STATUS_SENT ? now() : null,
            'claim_token'         => null,
            'lease_expires_at'    => null,
        ]);
    }

    protected function owned(NotificationDelivery $delivery, string $token): Builder
    {
        return NotificationDelivery::query()->whereKey($delivery->id)
            ->where('status', NotificationDelivery::STATUS_SENDING)->where('claim_token', $token);
    }

    /**
     * @return array{contents: string, name: string, mime: string}|null
     */
    protected function prepareEmailAttachment(NotificationDelivery $delivery): ?array
    {
        $payload = $delivery->payload;
        $path = $payload['attachment_path'] ?? null;
        if ($path === null || $path === '') {
            return null;
        }

        $storage = app(RekrutmenStorage::class);
        $disk = $payload['attachment_disk'] ?? 'local';
        $path = is_string($path) ? $storage->normalizePath($path) : null;
        if ($path === null || ! is_string($disk) || $disk === '' || $storage->resolveDisk($path, $disk) === null) {
            throw new \RuntimeException('Lampiran notifikasi tidak ditemukan.');
        }

        $contents = Storage::disk($disk)->get($path);
        if (! is_string($contents)) {
            throw new \RuntimeException('Lampiran notifikasi tidak dapat dibaca.');
        }

        return [
            'contents' => $contents,
            'name'     => $payload['attachment_name'] ?? 'document.pdf',
            'mime'     => $payload['attachment_mime'] ?? 'application/pdf',
        ];
    }

    /**
     * @param  array{contents: string, name: string, mime: string}|null  $attachment
     */
    protected function sendEmail(NotificationDelivery $delivery, ?array $attachment): void
    {
        $payload = $delivery->payload;
        app(RekrutmenMailer::class)->send('rekrutmen::mail.candidate-stage-notification', $payload['email_data'], function ($message) use ($delivery, $payload, $attachment): void {
            $message->to($delivery->recipient, $delivery->recipient_name)->subject($payload['subject']);
            if ($attachment) {
                $message->attachData($attachment['contents'], $attachment['name'], [
                    'mime' => $attachment['mime'],
                ]);
            }
        });
    }
}
