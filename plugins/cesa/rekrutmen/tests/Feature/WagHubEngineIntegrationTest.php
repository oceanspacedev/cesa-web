<?php

use Cesa\Rekrutmen\Services\WhatsAppEngineClient;
use Cesa\Rekrutmen\Services\WhatsAppEngineProcess;
use Cesa\Rekrutmen\Services\WhatsAppGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'rekrutmen.notifications.whatsapp.engine_driver' => 'wag_hub',
        'rekrutmen.notifications.whatsapp.engine_url'    => 'https://hub.example.test/api/v1/engine',
        'rekrutmen.notifications.whatsapp.engine_token'  => 'engine-secret',
        'rekrutmen.notifications.whatsapp.api_key'       => 'separate-hub-token',
        'rekrutmen.notifications.whatsapp.auto_start'    => true,
        'rekrutmen.notifications.whatsapp.enabled'       => true,
    ]);

    Http::preventStrayRequests();
});

it('uses the engine bearer credential for the complete remote session lifecycle', function (): void {
    $account = $this->makeConnectedWhatsAppAccount();
    $baseUrl = 'https://hub.example.test/api/v1/engine';
    $sessionUrl = $baseUrl.'/sessions/'.$account->sessionId();

    Http::fake([
        $baseUrl.'/health'                => Http::response(['ok' => true, 'engine' => 'wag-hub']),
        $baseUrl.'/sessions'              => Http::response(['ok' => true, 'status' => 'pairing', 'pairing_code' => '12345678']),
        $sessionUrl.'/send'               => Http::response(['ok' => true, 'status' => 'sent', 'message_id' => 'remote-message']),
        $sessionUrl.'/messages/request-1' => Http::response(['ok' => true, 'status' => 'sent', 'message_id' => 'remote-message']),
        $sessionUrl                       => Http::response(['ok' => true, 'status' => 'connected', 'phone' => '6281234567890']),
    ]);

    $gateway = app(WhatsAppGateway::class);

    expect($gateway->connect($account, 'pairing', '6281234567890')['data']['pairing_code'])->toBe('12345678')
        ->and($gateway->session($account)['delivery_ready'])->toBeTrue()
        ->and($gateway->sendText($account, '081234567890', 'Hello', ['idempotency_key' => 'request-1'])['status'])->toBe('sent')
        ->and($gateway->messageResult($account, 'request-1')['message_id'])->toBe('remote-message')
        ->and($gateway->disconnect($account)['success'])->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === $baseUrl.'/sessions'
        && $request->method() === 'POST'
        && $request['id'] === $account->sessionId()
        && $request['mode'] === 'pairing'
        && $request['phone'] === '6281234567890');
    Http::assertSent(fn (Request $request): bool => $request->url() === $sessionUrl.'/send'
        && $request['phone'] === '6281234567890'
        && $request['text'] === 'Hello'
        && $request['idempotency_key'] === 'request-1');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request['logout'] === true);
    Http::assertNotSent(fn (Request $request): bool => ! $request->hasHeader('Authorization', 'Bearer engine-secret')
        || str_contains($request->url(), 'engine-secret')
        || ! str_starts_with($request->url(), $baseUrl));
});

it('retains missing-host health details and never treats an error response as ready', function (): void {
    Http::fake(['*/health' => Http::response([
        'ok'         => true,
        'engine'     => 'wag-hub',
        'waha_ready' => false,
        'message'    => 'Engine host unavailable.',
    ], 503)]);

    $client = app(WhatsAppEngineClient::class);

    expect($client->health())->toMatchArray(['ok' => false, 'waha_ready' => false, 'message' => 'Engine host unavailable.'])
        ->and($client->isReady())->toBeFalse();
});

it('preserves uncertain remote sends without retrying or falling back to another sender', function (): void {
    Http::fake([
        '*/health' => Http::response(['ok' => true]),
        '*/send'   => Http::response(['ok' => false, 'status' => 'unknown', 'retryable' => false]),
    ]);

    $result = app(WhatsAppGateway::class)->sendText($this->makeConnectedWhatsAppAccount(), '6281234567890', 'Hello');

    expect($result)->toMatchArray(['success' => false, 'status' => 'unknown', 'retryable' => false]);
    Http::assertSentCount(2);
});

it('never starts or installs a local process for external engines even when forced', function (string $driver, string $url): void {
    config([
        'rekrutmen.notifications.whatsapp.engine_driver' => $driver,
        'rekrutmen.notifications.whatsapp.engine_url'    => $url,
    ]);

    Http::fake(['*' => Http::response(['ok' => false], 503)]);

    $process = app(WhatsAppEngineProcess::class);

    expect(app(WhatsAppEngineClient::class)->isLocalEngine())->toBeFalse()
        ->and($process->ensureRunning(true))->toBeFalse()
        ->and($process->installDependencies())->toBeFalse();
    expect(fn () => $process->start())->toThrow(RuntimeException::class, 'service terpisah');
})->with([
    'shared hub'                   => ['wag_hub', 'https://hub.example.test/api/v1/engine'],
    'loopback hub'                 => ['wag_hub', 'http://127.0.0.1:3318'],
    'old remote URL configuration' => ['local', 'https://hub.example.test/engine/t/legacy-secret'],
    'local reverse proxy path'     => ['local', 'http://127.0.0.1:8000/engine'],
]);

it('checks external readiness from the engine command without installing dependencies', function (array $options): void {
    Http::fake(['*/health' => Http::response(['ok' => true])]);

    $this->artisan('rekrutmen:whatsapp-engine', $options)
        ->expectsOutput('Service WhatsApp eksternal siap.')
        ->assertSuccessful();

    Http::assertSentCount(1);
})->with([
    'foreground command' => [[]],
    'forced ensure'      => [['--ensure' => true]],
]);

it('refuses dependency installation for the external engine', function (): void {
    $this->artisan('rekrutmen:whatsapp-engine', ['--install' => true])
        ->expectsOutput('Engine WhatsApp eksternal dikelola oleh service terpisah; instalasi Node.js di CESA tidak diperlukan.')
        ->assertFailed();

    Http::assertNothingSent();
});

it('reports remote outages without instructing users to start the bundled engine', function (): void {
    Http::fake(['*/health' => Http::response(['ok' => false], 503)]);

    $this->artisan('rekrutmen:whatsapp-engine', ['--ensure' => true])
        ->expectsOutput('WAG Hub belum siap. Periksa WAG_URL, WAG_TOKEN, dan layanan WhatsApp di WAG Hub.')
        ->assertFailed();

    $result = app(WhatsAppGateway::class)->connect($this->makeConnectedWhatsAppAccount());

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('WAG Hub')->not->toContain('Node.js');
});

it('keeps the legacy local engine usable without authentication', function (): void {
    config([
        'rekrutmen.notifications.whatsapp.engine_driver' => 'local',
        'rekrutmen.notifications.whatsapp.engine_url'    => 'http://127.0.0.1:3318',
        'rekrutmen.notifications.whatsapp.engine_token'  => null,
    ]);
    Http::fake(['http://127.0.0.1:3318/health' => Http::response(['ok' => true])]);

    $client = app(WhatsAppEngineClient::class);

    expect($client->isLocalEngine())->toBeTrue()
        ->and($client->isReady())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

it('resolves shared engine settings while preserving explicit recruitment overrides', function (array $overrides, array $expected): void {
    $values = array_merge([
        'WAG_URL'                              => null,
        'WAG_TOKEN'                            => null,
        'WAG_ENGINE_URL'                       => 'https://hub.example.test/api/v1/engine',
        'WAG_ENGINE_TOKEN'                     => 'shared-engine-token',
        'REKRUTMEN_WHATSAPP_ENGINE_DRIVER'     => null,
        'REKRUTMEN_WHATSAPP_ENGINE_URL'        => null,
        'REKRUTMEN_WHATSAPP_ENGINE_TOKEN'      => null,
        'REKRUTMEN_WHATSAPP_ENGINE_AUTO_START' => null,
    ], $overrides);
    $repository = Env::getRepository();
    $original = [];

    foreach ($values as $key => $value) {
        $original[$key] = $repository->get($key);
        $repository->clear($key);

        if ($value !== null) {
            $repository->set($key, $value);
        }
    }

    try {
        $settings = require base_path('plugins/cesa/rekrutmen/config/rekrutmen.php');

        expect($settings['notifications']['whatsapp'])->toMatchArray($expected);
    } finally {
        foreach ($original as $key => $value) {
            $repository->clear($key);

            if ($value !== null) {
                $repository->set($key, $value);
            }
        }
    }
})->with([
    'shared remote configuration'        => [[], ['engine_driver' => 'wag_hub', 'engine_url' => 'https://hub.example.test/api/v1/engine', 'engine_token' => 'shared-engine-token', 'auto_start' => false]],
    'explicit recruitment configuration' => [[
        'REKRUTMEN_WHATSAPP_ENGINE_URL'   => 'https://recruitment-hub.example.test/engine',
        'REKRUTMEN_WHATSAPP_ENGINE_TOKEN' => 'recruitment-engine-token',
    ], ['engine_url' => 'https://recruitment-hub.example.test/engine', 'engine_token' => 'recruitment-engine-token']],
    'explicit local driver'                            => [['REKRUTMEN_WHATSAPP_ENGINE_DRIVER' => 'local'], ['engine_driver' => 'local', 'engine_url' => 'http://127.0.0.1:3318', 'engine_token' => null, 'auto_start' => true]],
    'unconfigured service stays remote'                => [['WAG_ENGINE_URL' => null, 'WAG_ENGINE_TOKEN' => null], ['engine_driver' => 'wag_hub', 'engine_url' => null, 'engine_token' => null, 'auto_start' => false]],
    'two value setup including blank legacy overrides' => [['WAG_URL' => 'https://hub.test/', 'WAG_TOKEN' => 'app-token', 'WAG_ENGINE_URL' => '', 'WAG_ENGINE_TOKEN' => '', 'REKRUTMEN_WHATSAPP_ENGINE_URL' => '', 'REKRUTMEN_WHATSAPP_ENGINE_TOKEN' => ''], ['engine_driver' => 'wag_hub', 'engine_url' => 'https://hub.test/api/v2', 'engine_token' => 'app-token', 'auto_start' => false]],
    'unconfigured remote driver'                       => [['WAG_ENGINE_URL' => null, 'REKRUTMEN_WHATSAPP_ENGINE_DRIVER' => 'wag_hub'], ['engine_driver' => 'wag_hub', 'engine_url' => null, 'auto_start' => false]],
]);

it('does not send requests when the external URL is missing', function (): void {
    config(['rekrutmen.notifications.whatsapp.engine_url' => null]);

    expect(app(WhatsAppEngineClient::class)->isReady())->toBeFalse();
    Http::assertNothingSent();
});
