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
