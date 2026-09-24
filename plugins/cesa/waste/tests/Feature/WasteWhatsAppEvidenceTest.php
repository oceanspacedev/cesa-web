<?php

use App\Services\WhatsApp\WagHubClient;
use Cesa\Waste\Jobs\SendWasteNotification;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteNotificationDelivery;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Models\WasteWorkflow;
use Cesa\Waste\Services\WasteNotificationService;
use Cesa\Waste\Services\WasteReportService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config([
        'wag.url'          => 'https://hub.test',
        'wag.token'        => 'test-token',
        'wag.engine_url'   => 'https://hub.test/api/v2',
        'wag.engine_token' => 'test-token',
    ]);
    Http::preventStrayRequests();
});

it('queues every evidence photo for the requester and active external approver only on submission', function (): void {
    $result = whatsappEvidenceReport();
    $report = $result['report'];
    $notificationService = app(WasteNotificationService::class);

    $notificationService->queueSubmission($report, $result['progress_token'], $result['manage_token'], $result['approval_tokens']);

    $evidenceIds = $report->latestVersion->events()->with('evidences')->get()
        ->flatMap(fn ($event) => $event->evidences->pluck('id'))
        ->sort()
        ->values()
        ->all();
    $requesterPhotos = $report->notifications()->where('type', 'requester_submitted_evidence')->get();
    $approverPhotos = $report->notifications()->where('type', 'approval_1_evidence')->get();

    expect($evidenceIds)->toHaveCount(2)
        ->and($requesterPhotos)->toHaveCount(2)
        ->and($approverPhotos)->toHaveCount(2)
        ->and($requesterPhotos->pluck('recipient')->unique()->all())->toBe(['081234567890'])
        ->and($approverPhotos->pluck('recipient')->unique()->all())->toBe(['089876543210'])
        ->and($requesterPhotos->pluck('payload')->pluck('evidence_id')->sort()->values()->all())->toBe($evidenceIds)
        ->and($approverPhotos->pluck('payload')->pluck('evidence_id')->sort()->values()->all())->toBe($evidenceIds)
        ->and($approverPhotos->first()->payload['message'])->toContain('Jchicken / Ciledug, 22/09/2026')
        ->and($report->notifications()->where('type', 'requester_submitted')->count())->toBe(1)
        ->and($report->notifications()->where('type', 'approval_1')->count())->toBe(1);

    Queue::assertPushed(SendWasteNotification::class, 6);

    $notificationService->queueRequester($report, 'approved');

    expect($report->notifications()->where('type', 'requester_submitted_evidence')->count())->toBe(2)
        ->and($report->notifications()->where('type', 'requester_approved_evidence')->count())->toBe(0)
        ->and($report->notifications()->where('type', 'requester_approved')->count())->toBe(1);
});

it('uploads private evidence and sends its image ID through the Hub v1 API', function (): void {
    $delivery = whatsappEvidenceDelivery();
    $evidence = $delivery->report->latestVersion->events()->with('evidences')->firstOrFail()->evidences->firstOrFail();
    $attachmentId = '15e7674d-4317-4264-a79a-e1d4cf2be615';

    Http::fake([
        'https://hub.test/api/v1/attachments' => Http::response(['data' => ['id' => $attachmentId]], 201),
        'https://hub.test/api/v1/messages'    => Http::response(['data' => ['id' => '2145d3b6-c4fd-4884-b0db-c2a751c39c40', 'status' => 'accepted']], 202),
    ]);

    (new SendWasteNotification($delivery->getKey()))->handle(app(WagHubClient::class));

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hub.test/api/v1/attachments'
        && $request->method() === 'POST'
        && str_contains((string) $request->header('Content-Type')[0], 'multipart/form-data')
        && str_contains($request->body(), 'bukti-waste-'.$evidence->getKey().'.jpg')
        && $request->hasHeader('Authorization', 'Bearer test-token'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hub.test/api/v1/messages'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->hasHeader('Idempotency-Key', 'waste-'.$delivery->report->uid.'-'.$delivery->getKey())
        && $request['recipient'] === ['type' => 'phone', 'value' => $delivery->recipient]
        && $request['message']['type'] === 'image'
        && $request['message']['text'] === $delivery->payload['message']
        && $request['message']['attachment']['id'] === $attachmentId
        && $request['purpose'] === 'transactional'
        && $request['mode'] === 'async'
        && $request['route_key'] === 'default'
        && $request['client_reference'] === 'waste-'.$delivery->report->uid.'-'.$delivery->getKey()
        && ! str_contains($request->body(), '/waste/evidence/'));

    expect($delivery->fresh()->status)->toBe('sent')
        ->and($delivery->fresh()->payload['attachment_id'])->toBe($attachmentId)
        ->and($delivery->fresh()->payload['hub_message_id'])->toBe('2145d3b6-c4fd-4884-b0db-c2a751c39c40')
        ->and($delivery->fresh()->payload['hub_status'])->toBe('accepted');

    (new SendWasteNotification($delivery->getKey()))->handle(app(WagHubClient::class));
    Http::assertSentCount(2);
    expect($delivery->fresh()->attempts)->toBe(1);
});

it('does not mark an upload error as sent or submit a message without evidence', function (): void {
    $delivery = whatsappEvidenceDelivery();
    Http::fake([
        'https://hub.test/api/v1/attachments' => Http::response(['error' => 'invalid image'], 422),
        'https://hub.test/api/v1/messages'    => Http::response(['ok' => true, 'status' => 'accepted'], 202),
    ]);

    expect(fn () => (new SendWasteNotification($delivery->getKey()))->handle(app(WagHubClient::class)))
        ->toThrow(RuntimeException::class);

    expect($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->payload)->not->toHaveKey('attachment_id');
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://hub.test/api/v1/messages');
});

it('rejects a failed Hub message even when its HTTP response is successful', function (): void {
    $delivery = whatsappEvidenceDelivery();
    Http::fake([
        'https://hub.test/api/v1/attachments' => Http::response(['data' => ['id' => '84adf16b-d171-41e8-9e98-70db64fd1743']], 201),
        'https://hub.test/api/v1/messages'    => Http::response(['data' => ['id' => '2aa7fc51-8ec9-471b-9640-70e9c4193817', 'status' => 'failed']], 202),
    ]);

    expect(fn () => (new SendWasteNotification($delivery->getKey()))->handle(app(WagHubClient::class)))
        ->toThrow(RuntimeException::class, 'WAG Hub menolak notifikasi WhatsApp.');

    expect($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->payload['attachment_id'])->toBe('84adf16b-d171-41e8-9e98-70db64fd1743');
});

it('reuses an uploaded attachment and the same message idempotency key after a send error', function (): void {
    $delivery = whatsappEvidenceDelivery();
    $attachmentId = 'd2188cca-0227-4d48-84ba-828becb0c6e9';
    Http::fake([
        'https://hub.test/api/v1/attachments' => Http::response(['data' => ['id' => $attachmentId]], 201),
        'https://hub.test/api/v1/messages'    => Http::sequence()
            ->push(['error' => 'unavailable'], 503)
            ->push(['data' => ['id' => 'd17fc296-60ae-497a-bdb0-8353543e053d', 'status' => 'accepted']], 202),
    ]);

    expect(fn () => (new SendWasteNotification($delivery->getKey()))->handle(app(WagHubClient::class)))
        ->toThrow(RuntimeException::class);

    expect($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->payload['attachment_id'])->toBe($attachmentId);

    (new SendWasteNotification($delivery->getKey()))->handle(app(WagHubClient::class));

    expect($delivery->fresh()->status)->toBe('sent')
        ->and($delivery->fresh()->attempts)->toBe(2);
    Http::assertSentCount(3);
    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'https://hub.test/api/v1/attachments'))->toHaveCount(1);
    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'https://hub.test/api/v1/messages')
        ->map(fn (array $record) => $record[0]->header('Idempotency-Key')[0])
        ->values()
        ->all())->toBe([
            'waste-'.$delivery->report->uid.'-'.$delivery->getKey(),
            'waste-'.$delivery->report->uid.'-'.$delivery->getKey(),
        ]);
});

function whatsappEvidenceDelivery(): WasteNotificationDelivery
{
    $result = whatsappEvidenceReport();
    app(WasteNotificationService::class)->queueSubmission(
        $result['report'],
        $result['progress_token'],
        $result['manage_token'],
        $result['approval_tokens'],
    );

    return $result['report']->notifications()
        ->where('type', 'approval_1_evidence')
        ->firstOrFail();
}

/**
 * @return array{report: WasteReport, progress_token: string, manage_token: string, approval_tokens: array<int, string>}
 */
function whatsappEvidenceReport(): array
{
    $brand = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG', 'slug' => 'jchicken-ciledug',
        'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $item = WasteItem::query()->create([
        'brand_id'  => $brand->id, 'code' => 'B001', 'name' => 'Chicken Popcorn', 'unit' => 'GR',
        'item_type' => 'Bahan Baku', 'is_active' => true, 'source_status' => 'review',
    ]);
    $category = WasteCategory::query()->create([
        'brand_id' => $brand->id, 'code' => 'SPOIL', 'name' => 'Spoil', 'is_active' => true,
    ]);
    WasteSection::seedDefaults();
    WasteWorkflow::query()->create([
        'brand_id'  => $brand->id, 'name' => 'Supervisor review',
        'steps'     => [['label' => 'Supervisor', 'name' => 'Supervisor', 'phone' => '089876543210']],
        'is_active' => true,
    ]);

    return app(WasteReportService::class)->submit($brand, $outlet, [
        'event_date' => '2026-09-22', 'reporter_name' => 'Field User', 'reporter_phone' => '081234567890',
        'events'     => [[
            'section' => 'BAR', 'category_id' => $category->id, 'reason' => 'Produk rusak',
            'lines'   => [['item_id' => $item->id, 'quantity' => '1.25']],
        ]],
    ], [0 => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')]]);
}
