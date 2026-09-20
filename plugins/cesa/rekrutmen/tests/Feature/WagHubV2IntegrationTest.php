<?php

use App\Jobs\ProcessWagEvent;
use App\Models\WagEventInbox;
use App\Models\WagIntegration;
use App\Models\WagMessageRequest;
use App\Services\WhatsApp\WagHubIntegration;
use Cesa\FormTransfer\Jobs\SendWhatsAppNotification;
use Cesa\Rekrutmen\Enums\WhatsAppAccountStatus;
use Cesa\Rekrutmen\Models\WhatsAppAccount;
use Cesa\Rekrutmen\Services\HubWhatsAppGateway;
use Cesa\Rekrutmen\Services\WhatsAppEngineClient;
use Cesa\Rekrutmen\Services\WhatsAppGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Models\User;

beforeEach(function (): void {
    config([
        'app.key'                                       => 'base64:'.base64_encode(str_repeat('a', 32)),
        'rekrutmen.notifications.whatsapp.engine_url'   => 'https://hub.test/api/v2',
        'rekrutmen.notifications.whatsapp.engine_token' => 'secret',
        'rekrutmen.notifications.whatsapp.enabled'      => true,
        'wag.engine_url'                                => 'https://hub.test/api/v2',
        'wag.engine_token'                              => 'secret',
    ]);
    Http::preventStrayRequests();
    $this->snapshot = [
        'id'                => (string) Str::uuid(), 'name' => 'Shared account', 'status' => 'WORKING',
        'phone_number'      => '+628123456789', 'desired_state' => 'RUNNING', 'revision' => 10,
        'observed_at'       => now()->toIso8601String(), 'stale' => false, 'engine_available' => true,
        'pending_operation' => null, 'last_error' => null,
    ];
    WagIntegration::current()->update(['installation_id' => 'installation-one', 'webhook_secret' => 'webhook-secret']);
});

it('projects only newer hub revisions and preserves business references', function (): void {
    $account = $this->makeConnectedWhatsAppAccount(['is_default' => false]);
    $id = $account->id;
    $snapshot = array_merge($this->snapshot, ['external_reference' => 'rekrutmen-'.$id]);
    $service = app(WagHubIntegration::class);
    $service->project($snapshot);
    $service->project(array_merge($snapshot, ['revision' => 9, 'status' => 'STOPPED', 'phone_number' => null]));
    expect($account->fresh()->hub_session_id)->toBe($snapshot['id'])
        ->and($account->fresh()->status)->toBe(WhatsAppAccountStatus::Connected)
        ->and($account->fresh()->hub_revision)->toBe(10)
        ->and(WhatsAppAccount::query()->count())->toBe(1);
});

it('selects the first working account only and never replaces a removed default', function (): void {
    $service = app(WagHubIntegration::class);
    $account = $service->project(array_merge($this->snapshot, ['status' => 'SCAN_QR_CODE', 'auth_method' => 'qr', 'phone_number' => null]));
    expect($account->is_default)->toBeFalse();
    $account = $service->project(array_merge($this->snapshot, ['revision' => 11]));
    expect($account->is_default)->toBeTrue();
    $service->removeProjection($account);
    $replacement = $service->project(array_merge($this->snapshot, ['id' => (string) Str::uuid()]));
    expect($replacement->is_default)->toBeFalse()->and(WhatsAppAccount::resolveForSend())->toBeNull();
});

it('reports hub outages as stale without inventing a logout', function (): void {
    $account = app(WagHubIntegration::class)->project($this->snapshot);
    Http::fake(['*' => Http::response(['message' => 'unavailable'], 503)]);
    $payload = app(HubWhatsAppGateway::class)->session($account);
    expect($payload)->toMatchArray(['status' => 'unknown', 'hub_status' => 'WORKING', 'stale' => true, 'delivery_ready' => false])
        ->and($account->fresh()->hub_status)->toBe('WORKING');
    $result = app(HubWhatsAppGateway::class)->lifecycle($account, 'logout');
    expect($result['success'])->toBeFalse()->and($account->fresh()->hub_status)->toBe('WORKING');
});

it('waits for delete completion and keeps the local account reference until then', function (): void {
    $account = app(WagHubIntegration::class)->project($this->snapshot);
    $operation = ['id' => 'delete-op', 'action' => 'delete', 'status' => 'pending'];
    Http::fake([
        '*/sessions/*'   => Http::response(['data' => ['session' => array_merge($this->snapshot, ['revision' => 11, 'desired_state' => 'STOPPED', 'pending_operation' => $operation]), 'operation' => $operation]], 202),
        '*/operations/*' => Http::response(['data' => ['id' => 'delete-op', 'status' => 'completed']]),
    ]);
    $gateway = app(HubWhatsAppGateway::class);
    expect($gateway->lifecycle($account, 'delete')['success'])->toBeTrue()
        ->and(WhatsAppAccount::query()->find($account->id))->not->toBeNull();
    expect($gateway->session($account->fresh())['deleted'])->toBeTrue()
        ->and(WhatsAppAccount::withTrashed()->find($account->id)->trashed())->toBeTrue()
        ->and(WagIntegration::current()->default_selection_required)->toBeTrue();
});

it('requires both valid HMAC and installation ownership before committing an event', function (): void {
    Queue::fake();
    $event = ['id' => 'event-one', 'installation_id' => 'installation-one', 'event' => 'session.status', 'session_id' => $this->snapshot['id'], 'revision' => 10, 'occurred_at' => now()->toIso8601String(), 'data' => $this->snapshot];
    $body = json_encode($event);
    $timestamp = (string) time();
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WAG_TIMESTAMP' => $timestamp, 'HTTP_X_WAG_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, 'webhook-secret')];
    $this->call('POST', '/api/integrations/wag/webhook', [], [], [], $headers, $body)->assertSuccessful();
    $this->call('POST', '/api/integrations/wag/webhook', [], [], [], $headers, $body)->assertSuccessful();
    expect(WagEventInbox::query()->count())->toBe(1)->and(WagEventInbox::query()->first()->processed_at)->toBeNull();
    $this->call('POST', '/api/integrations/wag/webhook', [], [], [], $headers, $body.' ')->assertUnauthorized();
    $this->call('POST', '/api/integrations/wag/webhook', [], [], [], array_merge($headers, ['HTTP_X_WAG_TIMESTAMP' => (string) (time() - 301)]), $body)->assertUnauthorized();
    $event['installation_id'] = 'another-tenant';
    $body = json_encode($event);
    $headers['HTTP_X_WAG_SIGNATURE'] = hash_hmac('sha256', $timestamp.'.'.$body, 'webhook-secret');
    $this->call('POST', '/api/integrations/wag/webhook', [], [], [], $headers, $body)->assertForbidden();
    expect(WagEventInbox::query()->count())->toBe(1);
});

it('processes duplicated and out-of-order events once without regressing the snapshot', function (): void {
    $service = app(WagHubIntegration::class);
    $event = $service->ingest(['id' => 'new-event', 'event' => 'session.status', 'installation_id' => 'installation-one', 'session_id' => $this->snapshot['id'], 'revision' => 10, 'data' => $this->snapshot]);
    $job = new ProcessWagEvent($event->id);
    $job->handle($service);
    $job->handle($service);
    $old = $service->ingest(['id' => 'old-event', 'event' => 'session.status', 'installation_id' => 'installation-one', 'session_id' => $this->snapshot['id'], 'revision' => 5, 'data' => array_merge($this->snapshot, ['revision' => 5, 'status' => 'STOPPED'])]);
    (new ProcessWagEvent($old->id))->handle($service);
    expect(WhatsAppAccount::query()->sole()->hub_status)->toBe('WORKING')
        ->and($event->fresh()->processed_at)->not->toBeNull();
});

it('stores send intent before transport and recovers the same idempotent message after a lost response', function (): void {
    $client = app(WhatsAppEngineClient::class);
    $attempts = 0;
    Http::fake(function ($request) use (&$attempts) {
        if ($request->method() === 'POST') {
            expect(WagMessageRequest::query()->count())->toBe(1);
            expect($request->header('Idempotency-Key'))->toBe(['same-key']);
            $attempts++;
            if ($attempts === 1) {
                throw new ConnectionException('lost response');
            }

            return Http::response(['data' => ['id' => 'message-one', 'transport_status' => 'processing']], 202);
        }

        return Http::response(['data' => ['id' => 'message-one', 'transport_status' => 'accepted']]);
    });
    expect(fn () => $client->sendText($this->snapshot['id'], '628123456789', 'Hello', 'same-key'))->toThrow(ConnectionException::class);
    expect($client->message($this->snapshot['id'], 'same-key')['status'])->toBe('unknown');
    expect($client->message($this->snapshot['id'], 'same-key')['status'])->toBe('sent');
    expect($attempts)->toBe(2)->and(WagMessageRequest::query()->sole()->hub_message_id)->toBe('message-one');
    expect(fn () => $client->sendText($this->snapshot['id'], '628123456789', 'Different', 'same-key'))->toThrow(RuntimeException::class);
});

it('does not infer connection from accepted sends or restart paused accounts', function (): void {
    $account = app(WagHubIntegration::class)->project(array_merge($this->snapshot, ['status' => 'STARTING']));
    Http::fake([
        '*/health/ready' => Http::response(['ready' => true]),
        '*/messages'     => Http::response(['data' => ['id' => 'message-one', 'transport_status' => 'accepted']], 202),
    ]);
    expect(app(WhatsAppGateway::class)->sendText($account, '628123456789', 'Hello')['success'])->toBeTrue()
        ->and($account->fresh()->hub_status)->toBe('STARTING')
        ->and($account->fresh()->status)->toBe(WhatsAppAccountStatus::Connecting);
    $account->forceFill(['desired_state' => 'STOPPED'])->save();
    expect(app(WhatsAppGateway::class)->sendText($account, '628123456789', 'Again')['success'])->toBeFalse();
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/start'));
});

it('configures encrypted backend credentials and automatically registers the webhook', function (): void {
    Http::fake([
        '*/integration'         => Http::response(['data' => ['id' => 'installation-one', 'capacity' => ['used' => 1, 'configured_limit' => 5, 'maximum_allowed' => 10]]]),
        '*/integration/webhook' => Http::response(['data' => ['configured' => true]]),
        '*/sessions'            => Http::response(['data' => [$this->snapshot]]),
    ]);
    app(WagHubIntegration::class)->configure('https://hub.test/', 'admin-token');
    expect(WagIntegration::current()->token)->toBe('admin-token')
        ->and(WagIntegration::current()->getRawOriginal('token'))->not->toBe('admin-token')
        ->and(WagIntegration::current()->toArray())->not->toHaveKey('token');
    Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/integration/webhook') && $request['secret'] === 'webhook-secret');
    expect(WhatsAppAccount::query()->sole()->hub_session_id)->toBe($this->snapshot['id']);
});

it('protects administrator setup and capacity from ordinary users', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $this->actingAs($user);
    $this->putJson('/rekrutmen/api/settings/whatsapp/integration', ['url' => 'https://hub.test', 'token' => 'secret'])->assertForbidden();
    $this->patchJson('/rekrutmen/api/settings/whatsapp/capacity', ['configured_limit' => 4])->assertForbidden();
    Http::assertNothingSent();
});

it('creates an account without a phone or label and keeps repeated connect requests on one hub session', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    Permission::findOrCreate('manage_rekrutmen_whatsapp', 'web');
    $user->givePermissionTo('manage_rekrutmen_whatsapp');
    $this->actingAs($user);
    $snapshot = array_merge($this->snapshot, ['status' => 'SCAN_QR_CODE', 'auth_method' => 'qr', 'phone_number' => null]);
    Http::fake([
        '*/sessions'   => Http::response(['data' => ['session' => $snapshot, 'operation' => null]], 202),
        '*/auth/qr'    => Http::response(['data' => ['id' => 'challenge', 'qr' => 'data:image/png;base64,abc', 'expires_at' => now()->addMinute()->toIso8601String()]]),
        '*/start'      => Http::response(['data' => ['session' => $snapshot, 'operation' => null]], 202),
        '*/sessions/*' => Http::response(['data' => $snapshot]),
    ]);
    $response = $this->postJson('/rekrutmen/api/settings/whatsapp/accounts/connect', ['request_key' => 'browser-one', 'mode' => 'qr'])->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson('/rekrutmen/api/settings/whatsapp/accounts/connect', ['request_key' => 'browser-one', 'mode' => 'qr'])->assertCreated()->assertJsonPath('data.id', $response->json('data.id'));
    expect(WhatsAppAccount::query()->count())->toBe(1)->and(WhatsAppAccount::query()->sole()->is_default)->toBeFalse();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sessions') && $request->hasHeader('Idempotency-Key') && ! isset($request['phone']) && $request['start'] === true);
});

it('keeps historical unknown messages on the legacy read-only ledger', function (): void {
    $account = app(WagHubIntegration::class)->project($this->snapshot);
    Http::fake(['*/api/v1/engine/sessions/rekrutmen-'.$account->id.'/messages/historic' => Http::response(['ok' => true, 'status' => 'sent', 'message_id' => 'old-message'])]);
    expect(app(WhatsAppGateway::class)->messageResult($account, 'historic')['message_id'])->toBe('old-message');
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    expect(WagMessageRequest::query()->count())->toBe(0);
});

it('preserves every distinct inbound message event regardless of shared session revision', function (): void {
    $service = app(WagHubIntegration::class);
    foreach (['inbound-one', 'inbound-two'] as $id) {
        $event = $service->ingest(['id' => $id, 'event' => 'message', 'installation_id' => 'installation-one', 'session_id' => $this->snapshot['id'], 'revision' => 5, 'data' => ['id' => $id, 'text' => 'Hello']]);
        (new ProcessWagEvent($event->id))->handle($service);
    }
    expect(WagEventInbox::query()->whereNotNull('processed_at')->count())->toBe(2);
});

it('pins all product notification jobs to the sender selected when they were queued', function (string $jobClass): void {
    $service = app(WagHubIntegration::class);
    $original = $service->project($this->snapshot);
    $job = new $jobClass('628123456789', 'Hello', 'unused-legacy-url', 'unused-legacy-token', 10);
    $replacement = $service->project(array_merge($this->snapshot, ['id' => (string) Str::uuid(), 'name' => 'Replacement']));
    $replacement->forceFill(['is_default' => true])->save();
    Http::fake(['*/messages*' => Http::response(['data' => ['id' => 'message-one', 'transport_status' => 'queued']], 202)]);
    $job->handle();
    $job->handle();
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/sessions/'.$original->hub_session_id.'/messages'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/sessions/'.$replacement->hub_session_id.'/messages'));
    expect(WagMessageRequest::query()->count())->toBe(1);
})->with([
    'form transfer'  => [SendWhatsAppNotification::class],
    'exit clearance' => [Cesa\ExitClearance\Jobs\SendWhatsAppNotification::class],
]);

it('keeps a paused account default but requires explicit replacement after unlinking', function (): void {
    $account = app(WagHubIntegration::class)->project($this->snapshot);
    Http::fake(fn ($request) => Http::response(['data' => ['session' => array_merge($this->snapshot, ['revision' => 11, 'desired_state' => 'STOPPED']), 'operation' => ['id' => 'operation', 'action' => str_ends_with($request->url(), '/stop') ? 'stop' : 'logout', 'status' => 'pending']]], 202));
    $gateway = app(HubWhatsAppGateway::class);
    expect($gateway->lifecycle($account, 'stop')['success'])->toBeTrue()->and($account->fresh()->is_default)->toBeTrue();
    expect($gateway->lifecycle($account->fresh(), 'logout')['success'])->toBeTrue()->and($account->fresh()->is_default)->toBeFalse()
        ->and(WagIntegration::current()->default_selection_required)->toBeTrue();
});

it('replays the canonical hub event envelope and advances the durable cursor', function (): void {
    $event = ['id' => 'replay-one', 'installation_id' => 'installation-one', 'event' => 'message', 'session_id' => $this->snapshot['id'], 'revision' => 10, 'data' => ['text' => 'Hello']];
    Http::fake(['*/events*' => Http::response(['data' => ['events' => [$event], 'next_cursor' => '42', 'has_more' => false]])]);
    app(WagHubIntegration::class)->replay(app(WhatsAppEngineClient::class));
    expect(WagEventInbox::query()->sole()->event_id)->toBe('replay-one')->and(WagIntegration::current()->event_cursor)->toBe('42');
});

it('does not tombstone a session created while an older list request is in flight', function (): void {
    $service = app(WagHubIntegration::class);
    Http::fake(function ($request) use ($service) {
        if (str_ends_with($request->url(), '/integration')) {
            return Http::response(['data' => ['id' => 'installation-one']]);
        }
        $service->project($this->snapshot);

        return Http::response(['data' => []]);
    });
    $service->refresh(app(WhatsAppEngineClient::class));
    expect(WhatsAppAccount::query()->sole()->hub_session_id)->toBe($this->snapshot['id']);
});

it('confirms list omissions individually and preserves newer webhook observations', function (): void {
    $service = app(WagHubIntegration::class);
    $account = $service->project($this->snapshot);
    Http::fake(function ($request) use ($service) {
        if (str_ends_with($request->url(), '/integration')) {
            return Http::response(['data' => ['id' => 'installation-one']]);
        }
        if (str_ends_with($request->url(), '/sessions')) {
            return Http::response(['data' => []]);
        }
        $service->project(array_merge($this->snapshot, ['revision' => 11]));

        return Http::response(['message' => 'not found'], 404);
    });
    $service->refresh(app(WhatsAppEngineClient::class));
    expect($account->fresh()->hub_revision)->toBe(11)->and($account->fresh()->trashed())->toBeFalse();
});

it('advances receipts with the same session revision and ignores older acknowledgements', function (): void {
    $message = WagMessageRequest::query()->create(['session_id' => $this->snapshot['id'], 'request_key' => 'ack-request', 'payload_hash' => str_repeat('a', 64), 'payload' => [], 'hub_message_id' => 'message-one', 'result' => ['transport_status' => 'accepted']]);
    $service = app(WagHubIntegration::class);
    foreach (['delivered', 'read', 'delivered'] as $index => $ack) {
        $event = $service->ingest(['id' => 'ack-'.$index, 'event' => 'message.ack', 'installation_id' => 'installation-one', 'session_id' => $this->snapshot['id'], 'revision' => 10, 'data' => ['message_id' => 'message-one', 'ack' => $ack]]);
        (new ProcessWagEvent($event->id))->handle($service);
    }
    expect($message->fresh()->result)->toMatchArray(['transport_status' => 'accepted', 'status' => 'sent', 'receipt_status' => 'read']);
});

it('applies a hub deletion event once and never resurrects it from an old list', function (): void {
    $service = app(WagHubIntegration::class);
    $account = $service->project($this->snapshot);
    $service->project(array_merge($this->snapshot, ['revision' => 11, 'deleted' => true]));
    $service->project($this->snapshot);
    expect(WhatsAppAccount::withTrashed()->find($account->id)->trashed())->toBeTrue()->and(WhatsAppAccount::query()->count())->toBe(0);
});

it('retains a tombstone when deletion is observed before initial discovery', function (): void {
    $service = app(WagHubIntegration::class);
    $service->project(array_merge($this->snapshot, ['revision' => 11, 'deleted' => true]));
    $service->project($this->snapshot);
    expect(WhatsAppAccount::query()->count())->toBe(0)
        ->and(WhatsAppAccount::withTrashed()->sole()->hub_session_id)->toBe($this->snapshot['id'])
        ->and(WhatsAppAccount::withTrashed()->sole()->trashed())->toBeTrue();
});

it('does not replace fresher observations with older events at the same revision', function (): void {
    $service = app(WagHubIntegration::class);
    $newer = array_merge($this->snapshot, ['observed_at' => now()->addSeconds(10)->toIso8601String()]);
    $account = $service->project($newer);
    $service->project(array_merge($this->snapshot, ['engine_available' => false, 'stale' => true]));
    expect($account->fresh()->hub_snapshot['observed_at'])->toBe($newer['observed_at'])
        ->and($account->fresh()->hub_stale)->toBeFalse();
});

it('does not choose a working observation that already has teardown requested as default', function (): void {
    $account = app(WagHubIntegration::class)->project(array_merge($this->snapshot, ['desired_state' => 'STOPPED', 'pending_operation' => ['id' => 'pause', 'action' => 'stop', 'status' => 'pending']]));
    expect($account->is_default)->toBeFalse();
});

it('uploads private media with its type and a correct multipart content type', function (): void {
    Http::fake(function ($request) {
        expect($request->header('Content-Type')[0] ?? '')->toStartWith('multipart/form-data; boundary=');
        expect($request->body())->toContain('document');

        return Http::response(['data' => ['id' => 'media-one']], 201);
    });
    $file = UploadedFile::fake()->create('sample.pdf', 1, 'application/pdf');
    expect(app(WhatsAppEngineClient::class)->uploadMedia($file->path(), 'document', 'sample.pdf')['id'])->toBe('media-one');
    Http::assertSentCount(1);
});
