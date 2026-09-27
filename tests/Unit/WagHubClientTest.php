<?php

use App\Services\WhatsApp\WagHubClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'wag.url'          => 'https://hub.test',
        'wag.token'        => 'app-token',
        'wag.engine_url'   => 'https://hub.test/api/v1/engine',
        'wag.engine_token' => 'app-token',
    ]);
});

it('uses cached application config for hub requests and all session actions independently of recruitment', function (): void {
    config(['rekrutmen.notifications.whatsapp.engine_url' => 'https://wrong.test']);
    Http::fake(['https://hub.test/*' => Http::response(['ok' => true, 'status' => 'sent'])]);
    $hub = app(WagHubClient::class);
    $engine = $hub->engine();

    expect($engine->isReady())->toBeTrue();
    $hub->sendMessage('081234567890', 'Hello');
    $hub->validateNumber('081234567890');
    $engine->startSession('sales-1');
    $engine->session('sales-1');
    $engine->sendText('sales-1', '081234567890', 'Hello', 'order-1');
    $engine->message('sales-1', 'order-1');
    $engine->logout('sales-1');

    Http::assertSentCount(8);
    Http::assertNotSent(fn (Request $request): bool => ! $request->hasHeader('Authorization', 'Bearer app-token')
        || ! str_starts_with($request->url(), 'https://hub.test/'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request['logout'] === true);
});

it('honors explicitly constructed credentials for session operations', function (): void {
    Http::fake(['https://another.test/*' => Http::response(['ok' => true])]);
    $hub = new WagHubClient('https://another.test/', 'another-token');

    expect($hub->engine()->isReady())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://another.test/health/ready'
        && $request->hasHeader('Authorization', 'Bearer another-token'));
});

it('refuses requests with incomplete credentials', function (): void {
    config(['wag.engine_token' => '']);
    $engine = app(WagHubClient::class)->engine();

    expect($engine->isReady())->toBeFalse();
    expect(fn () => $engine->startSession('sales-1'))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('reports hub readiness from app config for gateway gating', function (): void {
    config(['wag.url' => '', 'wag.token' => '']);
    expect((new WagHubClient)->isHubConfigured())->toBeFalse();

    config(['wag.url' => 'https://hub.test', 'wag.token' => 'app-token']);
    expect((new WagHubClient)->isHubConfigured())->toBeTrue();
    expect((new WagHubClient)->isConfigured())->toBeTrue();
});

it('keeps unknown sends uncertain and exposes missing journal entries without resending', function (): void {
    Http::fake([
        '*/send'       => Http::response('Bad gateway', 502),
        '*/messages/*' => Http::response(['error_code' => 'message_not_found'], 404),
    ]);
    $engine = app(WagHubClient::class)->engine();
    expect($engine->sendText('sales-1', '081234567890', 'Hello', 'order-1'))
        ->toMatchArray(['status' => 'unknown', 'retryable' => false]);
    expect($engine->message('sales-1', 'order-1'))->toBeNull();
    Http::assertSentCount(2);
});

it('checks remote readiness with the generic status command', function (): void {
    Http::fake(['*/health' => Http::response(['ok' => true])]);
    $this->artisan('wag:status')->assertSuccessful();
    Http::assertSentCount(1);
});

it('reports an unavailable service without starting a local engine', function (): void {
    Http::fake(['*/health' => Http::response(['ok' => false], 503)]);
    $this->artisan('wag:status')->assertFailed();
    Http::assertSentCount(1);
});

it('uploads private bytes and sends an image through the hub even when v2 is configured', function (): void {
    config(['wag.engine_url' => 'https://hub.test/api/v2']);
    $attachmentId = '11111111-1111-4111-8111-111111111111';
    Http::fake([
        'https://hub.test/api/v1/attachments' => Http::response(['data' => ['id' => $attachmentId, 'kind' => 'image']], 201),
        'https://hub.test/api/v1/messages'    => Http::response(['data' => ['id' => '22222222-2222-4222-8222-222222222222', 'status' => 'queued']], 202),
    ]);

    $client = app(WagHubClient::class);
    $uploadedId = $client->uploadAttachment('private-photo-contents', 'bukti.jpg', 'image/jpeg');
    $response = $client->sendMessage('081234567890', 'Bukti foto Waste', [
        'force_hub'        => true,
        'attachment_type'  => 'image',
        'attachment'       => ['id' => $uploadedId],
        'purpose'          => 'transactional',
        'idempotency_key'  => 'waste-report-1-photo-1',
        'client_reference' => 'waste-report-1',
    ]);

    expect($uploadedId)->toBe($attachmentId)
        ->and(data_get($response, 'data.status'))->toBe('queued');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hub.test/api/v1/attachments'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer app-token')
        && str_starts_with((string) ($request->header('Content-Type')[0] ?? ''), 'multipart/form-data; boundary=')
        && str_contains($request->body(), 'private-photo-contents')
        && str_contains($request->body(), 'bukti.jpg'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hub.test/api/v1/messages'
        && $request->hasHeader('Idempotency-Key', 'waste-report-1-photo-1')
        && $request['recipient']['value'] === '081234567890'
        && $request['message'] === [
            'type'       => 'image',
            'text'       => 'Bukti foto Waste',
            'attachment' => ['id' => $attachmentId],
        ]
        && $request['purpose'] === 'transactional'
        && $request['mode'] === 'async'
        && $request['route_key'] === 'default'
        && $request['client_reference'] === 'waste-report-1');
});

it('rejects hub message and upload errors instead of treating them as sent', function (): void {
    config(['wag.engine_url' => 'https://hub.test/api/v2']);
    Http::fake([
        'https://hub.test/api/v1/attachments' => Http::response(['message' => 'Lampiran ditolak.'], 422),
        'https://hub.test/api/v1/messages'    => Http::response(['message' => 'The given data was invalid.', 'error' => ['code' => 'validation_failed']], 422),
    ]);

    $client = app(WagHubClient::class);
    expect(fn (): string => $client->uploadAttachment('private-photo-contents', 'bukti.jpg', 'image/jpeg'))
        ->toThrow(RuntimeException::class, 'Lampiran ditolak.');
    expect(fn (): array => $client->sendMessage('081234567890', 'Waste', ['force_hub' => true, 'purpose' => 'transactional']))
        ->toThrow(RuntimeException::class, 'The given data was invalid.');
    Http::assertSentCount(2);
});

it('rejects an attachment response without an id before attempting a message', function (): void {
    Http::fake(['https://hub.test/api/v1/attachments' => Http::response(['data' => ['kind' => 'image']], 201)]);

    expect(fn (): string => app(WagHubClient::class)->uploadAttachment('private-photo-contents', 'bukti.jpg', 'image/jpeg'))
        ->toThrow(RuntimeException::class, 'WAG Hub tidak mengembalikan ID lampiran yang valid.');
    Http::assertSentCount(1);
});
