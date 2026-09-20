<?php

namespace Cesa\FormTransfer\Jobs;

use App\Services\WhatsApp\WagHubClient;
use Cesa\Rekrutmen\Models\WhatsAppAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SendWhatsAppNotification implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries;

    protected ?string $hubSessionId = null;

    protected ?string $requestKey = null;

    /**
     * The timeout in seconds for the WhatsApp HTTP request.
     */
    protected int $timeout;

    /**
     * The backoff intervals in seconds between retries.
     *
     * @var array<int, int>
     */
    protected array $backoff;

    public function __construct(
        protected string $phone,
        protected string $message,
        protected string $endpoint,
        protected string $apiKey,
        ?int $timeout = null,
    ) {
        $queue = config('form-transfer.notifications.whatsapp.queue')
            ?? config('form-transfer.notifications.queue')
            ?? 'whatsapp';

        $this->onQueue($queue);

        if ($connection = config('form-transfer.notifications.whatsapp.connection')) {
            $this->onConnection($connection);
        }

        $this->tries = (int) (config('form-transfer.notifications.whatsapp.tries') ?? 3);
        $this->timeout = $timeout ?? (int) (config('form-transfer.notifications.whatsapp.timeout') ?? 10);
        $this->backoff = $this->resolveBackoff();
        $this->requestKey = (string) Str::uuid();
        if (app(WagHubClient::class)->engine()->isV2()) {
            $this->hubSessionId = WhatsAppAccount::resolveForSend()?->hub_session_id;
        }
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $client = app(WagHubClient::class);
            if (! $client->engine()->isV2()) {
                $client = new WagHubClient($this->endpoint, $this->apiKey, rtrim($this->endpoint, '/').'/api/v1/engine', $this->apiKey);
            }
            $key = $this->requestKey ?? 'form-transfer-'.($this->job?->getJobId() ?? 'legacy');
            $result = $client->sendMessage($this->phone, $this->message, [
                'session_id'       => $this->hubSessionId, 'idempotency_key' => $key,
                'client_reference' => 'form-transfer', 'timeout' => $this->timeout,
            ]);
            if (($result['status'] ?? '') === 'failed') {
                throw new \RuntimeException('Hub rejected the WhatsApp message.');
            }
        } catch (Throwable $exception) {
            Log::error('Failed to send WhatsApp notification for transfer approval.', [
                'provider' => 'waghub',
                'phone'    => $this->phone,
                'error'    => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Determine the backoff intervals for the job retry attempts.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return $this->backoff;
    }

    /**
     * Define tags for queue monitoring systems like Horizon.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'form-transfer',
            'whatsapp',
            'transfer-request',
        ];
    }

    /**
     * Resolve the backoff configuration.
     *
     * @return array<int, int>
     */
    protected function resolveBackoff(): array
    {
        $backoff = config('form-transfer.notifications.whatsapp.backoff');

        if (is_array($backoff) && ! empty($backoff)) {
            return array_map(static fn ($interval): int => (int) $interval, $backoff);
        }

        return [10, 30, 60];
    }
}
