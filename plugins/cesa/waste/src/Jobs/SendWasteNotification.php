<?php

namespace Cesa\Waste\Jobs;

use App\Services\WhatsApp\WagHubClient;
use Cesa\Waste\Models\WasteEvidence;
use Cesa\Waste\Models\WasteNotificationDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SendWasteNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue((string) config('waste.notifications.queue', 'whatsapp'));
    }

    public function handle(WagHubClient $client): void
    {
        $delivery = WasteNotificationDelivery::query()->with('report')->findOrFail($this->deliveryId);

        if ($delivery->status === 'sent') {
            return;
        }

        $delivery->increment('attempts');
        $delivery->refresh();

        try {
            $payload = $delivery->payload ?? [];
            if ($delivery->channel === 'whatsapp') {
                $requestKey = 'waste-'.$delivery->report->uid.'-'.$delivery->getKey();
                $options = [
                    'client_reference' => $requestKey,
                    'purpose'          => 'transactional',
                    'mode'             => 'async',
                    'route_key'        => (string) config('waste.notifications.route_key', 'default'),
                    'idempotency_key'  => $requestKey,
                    'force_hub'        => true,
                ];

                if (isset($payload['evidence_id'])) {
                    $options['attachment_type'] = 'image';
                    $options['attachment'] = ['id' => $this->attachmentId($delivery, $payload, $client)];
                }

                $result = $client->sendMessage($delivery->recipient, (string) ($payload['message'] ?? ''), $options);
                $status = $result['data']['status'] ?? $result['status'] ?? null;

                if (($result['ok'] ?? true) === false || isset($result['error']) || ! in_array($status, ['queued', 'processing', 'provider_accepted', 'accepted', 'sent', 'delivered'], true) || blank($result['data']['id'] ?? null)) {
                    throw new RuntimeException('WAG Hub menolak notifikasi WhatsApp.');
                }

                $payload = $delivery->payload ?? [];
                $payload['hub_message_id'] = $result['data']['id'];
                $payload['hub_status'] = $status;
                $delivery->payload = $payload;
            } elseif ($delivery->channel === 'email') {
                Mail::raw((string) ($payload['message'] ?? ''), function ($message) use ($delivery, $payload): void {
                    $message->to($delivery->recipient)->subject((string) ($payload['subject'] ?? 'Waste report'));
                });
            }

            $delivery->forceFill(['status' => 'sent', 'sent_at' => now(), 'last_error' => null])->save();
        } catch (Throwable $exception) {
            $delivery->forceFill(['status' => 'failed', 'last_error' => $exception->getMessage()])->save();
            Log::error('Waste notification failed.', [
                'delivery_id' => $delivery->getKey(),
                'channel'     => $delivery->channel,
                'error'       => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    protected function attachmentId(WasteNotificationDelivery $delivery, array $payload, WagHubClient $client): string
    {
        if (filled($payload['attachment_id'] ?? null)) {
            return (string) $payload['attachment_id'];
        }

        $evidence = WasteEvidence::query()
            ->whereKey((int) $payload['evidence_id'])
            ->whereHas('event.version', fn ($query) => $query
                ->whereKey($delivery->version_id)
                ->where('report_id', $delivery->report_id))
            ->firstOrFail();

        $extension = match ($evidence->mime_type) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => throw new RuntimeException('Format foto bukti tidak didukung WAG Hub.'),
        };

        $disk = Storage::disk((string) config('waste.attachments.disk', 'local'));
        if (! $disk->exists($evidence->path)) {
            throw new RuntimeException('Foto bukti waste tidak ditemukan.');
        }

        $contents = $disk->get($evidence->path);
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('Foto bukti waste tidak dapat dibaca.');
        }

        $attachmentId = $client->uploadAttachment(
            $contents,
            'bukti-waste-'.$evidence->getKey().'.'.$extension,
            (string) $evidence->mime_type,
        );

        $payload['attachment_id'] = $attachmentId;
        $delivery->forceFill(['payload' => $payload])->save();

        return $attachmentId;
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }
}
