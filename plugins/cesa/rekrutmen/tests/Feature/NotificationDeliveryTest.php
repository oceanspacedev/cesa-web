<?php

use Cesa\Rekrutmen\Jobs\SendNotificationDeliveryJob;
use Cesa\Rekrutmen\Jobs\SendScheduledCandidateNotificationJob;
use Cesa\Rekrutmen\Jobs\SendWhatsAppNotification;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Cesa\Rekrutmen\Models\ScheduledNotification;
use Cesa\Rekrutmen\Services\CandidateWhatsAppNotifier;
use Cesa\Rekrutmen\Services\NotificationDeliveryService;
use Cesa\Rekrutmen\Services\ScheduledNotificationService;
use Cesa\Rekrutmen\Services\WhatsAppGateway;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;

beforeEach(function (): void {
    Queue::fake();
    Mail::fake();
    config(['rekrutmen.notifications.whatsapp.throttle.enabled' => false]);
    $this->fakeRekrutmenWhatsAppEngine();
    $this->makeConnectedWhatsAppAccount();
});

function deliveryCandidate(): JobApplication
{
    $pipeline = RekrutmenPipeline::query()->firstOrCreate(['name' => 'Delivery Pipeline']);
    $stage = RekrutmenStage::query()->firstOrCreate([
        'rekrutmen_pipeline_id' => $pipeline->id,
        'name'                  => 'Screening CV',
    ], ['order_column' => 1]);
    $posting = JobPosting::query()->create([
        'title'                 => 'Backend Engineer',
        'slug'                  => 'delivery-'.Str::uuid(),
        'rekrutmen_pipeline_id' => $pipeline->id,
        'location'              => 'Cirebon',
        'is_published'          => true,
    ]);

    return JobApplication::query()->create([
        'job_posting_id'   => $posting->id,
        'full_name'        => 'Kandidat Pengujian',
        'email'            => 'candidate@example.com',
        'whatsapp_number'  => '081234567890',
        'current_stage_id' => $stage->id,
        'status'           => 'in_progress',
    ]);
}

function candidateBatch(JobApplication $candidate, array $overrides = [], bool $dispatch = false): ScheduledNotification
{
    return app(ScheduledNotificationService::class)->schedule(array_merge([
        'request_key'     => (string) Str::uuid(),
        'application_ids' => [$candidate->id],
        'channels'        => ['whatsapp'],
        'subject'         => 'Undangan {nama_pelamar}',
        'body_message'    => 'Halo {nama_pelamar}, jadwal {jadwal}.',
    ], $overrides), dispatch: $dispatch);
}

it('queues each recipient channel without sending during batch creation', function (): void {
    $candidate = deliveryCandidate();
    $batch = candidateBatch($candidate, ['channels' => ['email', 'whatsapp']], true);

    expect($batch->deliveries()->count())->toBe(2);
    Queue::assertPushed(SendNotificationDeliveryJob::class, 2);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    expect(app(ScheduledNotificationService::class)->progress($batch)['stats'])
        ->toMatchArray(['email_pending' => 1, 'whatsapp_pending' => 1]);
});

it('reuses a request key and immutable content snapshot without duplicate records', function (): void {
    $candidate = deliveryCandidate();
    $key = (string) Str::uuid();
    $first = candidateBatch($candidate, ['request_key' => $key]);
    $candidate->update(['full_name' => 'Nama Diubah', 'whatsapp_number' => '081299999999']);
    $second = candidateBatch($candidate, ['request_key' => $key]);

    expect($second->id)->toBe($first->id)
        ->and($second->deliveries()->count())->toBe(1)
        ->and($second->deliveries()->first()->recipient)->toBe('6281234567890')
        ->and($second->deliveries()->first()->payload['text'])->toContain('KANDIDAT PENGUJIAN');
    expect(fn () => candidateBatch($candidate, ['request_key' => $key, 'body_message' => 'Different']))
        ->toThrow(ValidationException::class);
});

it('claims only once when two stale batch instances execute', function (): void {
    $candidate = deliveryCandidate();
    $batch = candidateBatch($candidate);
    $stale = ScheduledNotification::query()->findOrFail($batch->id);
    $this->partialMock(WhatsAppGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('sendText')->once()->andReturn(['success' => true, 'status' => 'sent', 'data' => ['id' => 'message-1']]);
    });

    $service = app(ScheduledNotificationService::class);
    $first = $service->executeScheduled($batch, true);
    $second = $service->executeScheduled($stale, true);
    expect($first)->toBe($second)
        ->and($second['status'])->toBe('sent')
        ->and($batch->deliveries()->first()->attempts)->toBe(1)
        ->and($batch->deliveries()->first()->provider_message_id)->toBe('message-1');
});

it('keeps ambiguous outcomes terminal and never automatically resends', function (): void {
    $batch = candidateBatch(deliveryCandidate());
    $this->partialMock(WhatsAppGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('sendText')->once()->andReturn(['success' => false, 'status' => 'unknown', 'retryable' => false, 'message' => 'Timeout after submission']);
    });

    $service = app(ScheduledNotificationService::class);
    $result = $service->executeScheduled($batch, true);
    $service->executeScheduled($batch, true);
    $service->processDueNotifications();
    expect($result['status'])->toBe('unknown')
        ->and($result['stats']['whatsapp_unknown'])->toBe(1)
        ->and($batch->deliveries()->first()->attempts)->toBe(1);
});

it('retries only a safely rejected channel while preserving successful email', function (): void {
    $batch = candidateBatch(deliveryCandidate(), ['channels' => ['email', 'whatsapp']]);
    $this->partialMock(WhatsAppGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('sendText')->twice()->andReturn(
            ['success' => false, 'status' => 'failed', 'retryable' => true, 'message' => 'Engine refused connection before submission'],
            ['success' => true, 'status' => 'sent'],
        );
    });
    $service = app(ScheduledNotificationService::class);
    $first = $service->executeScheduled($batch, true);
    expect($first['status'])->toBe('pending')->and($first['stats']['email_success'])->toBe(1);
    $this->travel(11)->seconds();
    $second = $service->executeScheduled($batch, true);
    expect($second['status'])->toBe('sent')
        ->and($batch->deliveries()->where('channel', 'email')->first()->attempts)->toBe(1)
        ->and($batch->deliveries()->where('channel', 'whatsapp')->first()->attempts)->toBe(2);
});

it('marks partial delivery truthfully and does not resend the successful recipient', function (): void {
    $first = deliveryCandidate();
    $second = deliveryCandidate();
    $batch = candidateBatch($first, ['application_ids' => [$first->id, $second->id]]);
    $this->partialMock(WhatsAppGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('sendText')->twice()->andReturn(
            ['success' => true, 'status' => 'sent'],
            ['success' => false, 'status' => 'failed', 'retryable' => false, 'message' => 'Recipient unavailable'],
        );
    });
    $service = app(ScheduledNotificationService::class);
    $result = $service->executeScheduled($batch, true);
    $service->executeScheduled($batch, true);
    expect($result['status'])->toBe('partial')
        ->and($result['stats'])->toMatchArray(['whatsapp_success' => 1, 'whatsapp_failed' => 1]);
});

it('does not send future or cancelled batches', function (string $guard): void {
    $batch = candidateBatch(deliveryCandidate(), $guard === 'future' ? ['scheduled_at' => now()->addDay()] : []);
    if ($guard === 'cancelled') {
        $batch->update(['status' => 'cancelled']);
    }
    app(ScheduledNotificationService::class)->executeScheduled($batch, true);
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with(['future', 'cancelled']);

it('recovers an expired sending lease as unknown without calling the provider', function (): void {
    $batch = candidateBatch(deliveryCandidate());
    $delivery = $batch->deliveries()->first();
    $delivery->update(['status' => 'sending', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->subSecond(), 'attempts' => 1]);
    $result = app(ScheduledNotificationService::class)->executeScheduled($batch, true);
    expect($result['status'])->toBe('unknown')->and($delivery->refresh()->claim_token)->toBeNull();
    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/send'));
});

it('prevents an expired owner from overwriting the recovered unknown result', function (): void {
    $batch = candidateBatch(deliveryCandidate());
    $this->partialMock(WhatsAppGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('sendText')->once()->andReturnUsing(function (): array {
            $this->travel(121)->seconds();
            app(NotificationDeliveryService::class)->recoverStale();

            return ['success' => true, 'status' => 'sent'];
        });
    });
    $result = app(ScheduledNotificationService::class)->executeScheduled($batch, true);
    expect($result['status'])->toBe('unknown')->and($result['stats']['whatsapp_success'])->toBe(0);
});

it('advances stage only after successful delivery and preserves manual stage changes', function (bool $manualChange): void {
    $candidate = deliveryCandidate();
    $originalStage = $candidate->current_stage_id;
    $interview = RekrutmenStage::query()->create(['rekrutmen_pipeline_id' => $candidate->jobPosting->rekrutmen_pipeline_id, 'name' => 'Interview HR', 'order_column' => 2]);
    $batch = candidateBatch($candidate, ['template_key' => 'interview']);
    expect($candidate->refresh()->current_stage_id)->toBe($originalStage);
    if ($manualChange) {
        $candidate->update(['status' => 'withdrawn']);
    }
    $result = app(ScheduledNotificationService::class)->executeScheduled($batch, true);
    expect($result['status'])->toBe('sent')
        ->and((int) $candidate->refresh()->current_stage_id)->toBe($manualChange ? (int) $originalStage : (int) $interview->id)
        ->and($candidate->getRawOriginal('status'))->toBe($manualChange ? 'withdrawn' : 'in_progress');
})->with([false, true]);

it('leaves candidate stage unchanged after failed or unknown delivery', function (string $status): void {
    $candidate = deliveryCandidate();
    $batch = candidateBatch($candidate, ['template_key' => 'rejection']);
    $this->partialMock(WhatsAppGateway::class, function (MockInterface $mock) use ($status): void {
        $mock->shouldReceive('sendText')->once()->andReturn(['success' => false, 'status' => $status, 'retryable' => false, 'message' => 'Failed']);
    });
    app(ScheduledNotificationService::class)->executeScheduled($batch, true);
    expect($candidate->refresh()->getRawOriginal('status'))->toBe('in_progress');
})->with(['failed', 'unknown']);

it('throttles at send time per account and leaves a deferred delivery pending', function (): void {
    config(['rekrutmen.notifications.whatsapp.throttle.enabled' => true, 'rekrutmen.notifications.whatsapp.throttle.min_interval_seconds' => 3, 'rekrutmen.notifications.whatsapp.throttle.max_interval_seconds' => 3]);
    $first = candidateBatch(deliveryCandidate());
    $second = candidateBatch(deliveryCandidate());
    $service = app(ScheduledNotificationService::class);
    expect($service->executeScheduled($first, true)['status'])->toBe('sent');
    expect($service->executeScheduled($second, true)['status'])->toBe('pending')
        ->and($second->deliveries()->first()->attempts)->toBe(0);
    $this->travel(3)->seconds();
    expect($service->executeScheduled($second, true)['status'])->toBe('sent');
});

it('uses a valid fallback phone when the preferred field is malformed', function (): void {
    $candidate = new JobApplication;
    $candidate->forceFill(['whatsapp_number' => '   ', 'active_whatsapp' => 'invalid', 'active_phone' => '081234567890']);
    expect(app(CandidateWhatsAppNotifier::class)->resolveCandidatePhone($candidate))->toBe('6281234567890');
});

it('reconciles an interrupted send from the journal and applies stage without resending', function (): void {
    $candidate = deliveryCandidate();
    $batch = candidateBatch($candidate, ['template_key' => 'rejection']);
    $delivery = $batch->deliveries()->first();
    $delivery->update(['status' => 'sending', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->subSecond(), 'attempts' => 1]);
    $this->partialMock(WhatsAppGateway::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('sendText');
        $mock->shouldReceive('engineReady')->once()->andReturnTrue();
        $mock->shouldReceive('messageResult')->once()->andReturn(['status' => 'sent', 'id' => 'confirmed-journal-id']);
    });

    $result = app(ScheduledNotificationService::class)->progress($batch);
    expect($result['status'])->toBe('sent')
        ->and($delivery->refresh()->provider_message_id)->toBe('confirmed-journal-id')
        ->and($candidate->refresh()->getRawOriginal('status'))->toBe('rejected');
    Http::assertNothingSent();
});

it('recovers a stage update interrupted after the sent result was committed', function (): void {
    $candidate = deliveryCandidate();
    $batch = candidateBatch($candidate, ['template_key' => 'rejection']);
    $delivery = $batch->deliveries()->first();
    $delivery->update(['status' => 'sent', 'sent_at' => now(), 'attempts' => 1]);
    app(ScheduledNotificationService::class)->processDueNotifications();
    expect($candidate->refresh()->getRawOriginal('status'))->toBe('rejected')
        ->and($delivery->refresh()->stage_applied_at)->not->toBeNull();
    Http::assertNothingSent();
});

it('fails a missing attachment before the email submission boundary', function (): void {
    $batch = candidateBatch(deliveryCandidate(), ['channels' => ['email']]);
    $delivery = $batch->deliveries()->first();
    $delivery->update(['payload' => array_merge($delivery->payload, ['attachment_path' => 'does-not-exist.pdf'])]);
    $result = app(ScheduledNotificationService::class)->executeScheduled($batch, true);
    expect($result['status'])->toBe('failed')
        ->and($result['stats']['email_failed'])->toBe(1)
        ->and($result['stats']['email_unknown'])->toBe(0);
    Mail::assertNothingSent();
});

it('does not send a snapshot after the candidate has been deleted', function (): void {
    $candidate = deliveryCandidate();
    $batch = candidateBatch($candidate);
    $candidate->delete();
    $result = app(ScheduledNotificationService::class)->executeScheduled($batch, true);
    expect($result['status'])->toBe('failed')
        ->and($result['details'][0]['whatsapp']['status'])->toBe('skipped');
    Http::assertNothingSent();
});

it('keeps the snapshotted sender when the default account changes', function (): void {
    $batch = candidateBatch(deliveryCandidate());
    $senderId = $batch->whatsapp_account_id;
    $this->makeConnectedWhatsAppAccount(['name' => 'Different sender', 'is_default' => true]);
    $this->partialMock(WhatsAppGateway::class, function (MockInterface $mock) use ($senderId): void {
        $mock->shouldReceive('sendText')->once()->withArgs(fn ($account, string $phone, string $message, array $options): bool => $account->id === $senderId && strlen($options['idempotency_key']) === 64)
            ->andReturn(['success' => true, 'status' => 'sent']);
    });
    expect(app(ScheduledNotificationService::class)->executeScheduled($batch, true)['status'])->toBe('sent');
});

it('releases a delayed job that becomes visible before the scheduled time', function (): void {
    $batch = candidateBatch(deliveryCandidate(), ['scheduled_at' => now()->addMinute()]);
    $job = (new SendNotificationDeliveryJob($batch->deliveries()->first()->id))->withFakeQueueInteractions();
    $job->handle(app(NotificationDeliveryService::class));
    $job->assertReleased();
    Http::assertNothingSent();
});

it('serializes a public worker timeout below the actual queue visibility window', function (string $channel, int $expected): void {
    config([
        'rekrutmen.notifications.whatsapp.job_timeout' => 120,
        'rekrutmen.notifications.whatsapp.connection'  => 'redis',
        'queue.connections.redis.retry_after'          => 20,
        'queue.connections.database.retry_after'       => 40,
    ]);
    $job = match ($channel) {
        'legacy'      => new SendWhatsAppNotification(1, '6281234567890', 'Test'),
        'coordinator' => new SendScheduledCandidateNotificationJob(1),
        default       => new SendNotificationDeliveryJob(1, $channel),
    };
    $queue = new class extends SyncQueue
    {
        public function payloadFor(object $job): array
        {
            return json_decode($this->createPayload($job, 'whatsapp'), true, flags: JSON_THROW_ON_ERROR);
        }
    };
    $queue->setContainer(app());
    $payload = $queue->payloadFor($job);
    expect($payload['timeout'])->toBe($expected)
        ->and(unserialize($payload['data']['command'])->timeout)->toBe($expected);
})->with([
    'whatsapp queue'              => ['whatsapp', 15],
    'email queue'                 => ['email', 35],
    'legacy whatsapp queue'       => ['legacy', 15],
    'scheduled coordinator queue' => ['coordinator', 35],
]);

it('rejects an overlapping execution while the first worker is inside the send call', function (): void {
    $batch = candidateBatch(deliveryCandidate());
    $delivery = $batch->deliveries()->first();
    $this->partialMock(WhatsAppGateway::class, function (MockInterface $mock) use ($delivery): void {
        $mock->shouldReceive('sendText')->once()->andReturnUsing(function () use ($delivery): array {
            $overlap = app(NotificationDeliveryService::class)->execute($delivery->fresh());
            expect($overlap->status)->toBe('sending')->and($overlap->attempts)->toBe(1);

            return ['success' => true, 'status' => 'sent', 'data' => ['id' => 'one-send']];
        });
    });
    app(ScheduledNotificationService::class)->executeScheduled($batch, true);
    expect($delivery->fresh()->status)->toBe('sent')->and($delivery->fresh()->attempts)->toBe(1);
});

it('checks an unavailable engine once and still recovers successful stage updates locally', function (): void {
    $candidate = deliveryCandidate();
    $batch = candidateBatch($candidate, ['channels' => ['email', 'whatsapp'], 'template_key' => 'rejection']);
    $email = $batch->deliveries()->where('channel', 'email')->first();
    $whatsapp = $batch->deliveries()->where('channel', 'whatsapp')->first();
    $email->update(['status' => 'sent', 'sent_at' => now()]);
    $whatsapp->update(['status' => 'unknown']);
    $this->partialMock(WhatsAppGateway::class, function (MockInterface $mock): void {
        $mock->shouldReceive('engineReady')->once()->andReturnFalse();
        $mock->shouldNotReceive('messageResult');
        $mock->shouldNotReceive('sendText');
    });

    app(NotificationDeliveryService::class)->reconcile($batch->id);
    expect($candidate->refresh()->getRawOriginal('status'))->toBe('rejected')
        ->and($email->refresh()->stage_applied_at)->not->toBeNull()
        ->and($whatsapp->refresh()->status)->toBe('unknown')
        ->and($whatsapp->last_reconciled_at)->toBeNull();
    Http::assertNothingSent();
});

it('stops journal lookups at the elapsed budget and leaves the remaining rows untouched', function (): void {
    config(['rekrutmen.notifications.delivery.reconcile_budget_seconds' => 3]);
    $batch = candidateBatch(deliveryCandidate());
    $first = $batch->deliveries()->first();
    $first->update(['status' => 'unknown']);
    $second = $first->replicate();
    $second->forceFill(['application_id' => null, 'request_key' => (string) Str::uuid()])->save();
    $third = $first->replicate();
    $third->forceFill(['application_id' => null, 'request_key' => (string) Str::uuid()])->save();
    $this->partialMock(WhatsAppGateway::class, function (MockInterface $mock) use ($first): void {
        $mock->shouldReceive('engineReady')->once()->andReturnTrue();
        $mock->shouldReceive('messageResult')->once()
            ->withArgs(fn ($account, string $key): bool => $key === $first->request_key)
            ->andReturn(['status' => 'unknown']);
        $mock->shouldNotReceive('sendText');
    });
    $service = Mockery::mock(NotificationDeliveryService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('monotonicTime')->times(3)->andReturn(100.0, 100.1, 103.1);
    $service->reconcile($batch->id);

    expect($first->refresh()->last_reconciled_at)->not->toBeNull()
        ->and($second->refresh()->last_reconciled_at)->toBeNull()
        ->and($third->refresh()->last_reconciled_at)->toBeNull();
    Http::assertNothingSent();
});
