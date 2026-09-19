<?php

use Cesa\Rekrutmen\Enums\WhatsAppAccountStatus;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Cesa\Rekrutmen\Models\WhatsAppAccount;
use Cesa\Rekrutmen\Services\WhatsAppGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;

beforeEach(function (): void {
    config([
        'rekrutmen.notifications.whatsapp.auto_start'   => false,
        'rekrutmen.notifications.whatsapp.enabled'      => true,
        'rekrutmen.notifications.whatsapp.engine_url'   => 'https://hub.test/api/v1/engine',
        'rekrutmen.notifications.whatsapp.engine_token' => 'test-engine-token',
    ]);
    Http::preventStrayRequests();
});

it('preserves explicit QR mode for an account with a saved number', function (): void {
    $this->fakeRekrutmenWhatsAppEngine();
    $account = $this->makeConnectedWhatsAppAccount();
    app(WhatsAppGateway::class)->connect($account, 'qr');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/sessions')
        && $request['mode'] === 'qr' && ! isset($request['phone']));
});

it('never substitutes another sender when the selected account is missing or inactive', function (): void {
    $default = $this->makeConnectedWhatsAppAccount();
    $inactive = $this->makeConnectedWhatsAppAccount(['is_default' => false, 'is_active' => false]);

    expect(WhatsAppAccount::resolveForSend($inactive->id))->toBeNull()
        ->and(WhatsAppAccount::resolveForSend(999999))->toBeNull()
        ->and(WhatsAppAccount::resolveForSend()?->id)->toBe($default->id);

    expect(app(WhatsAppGateway::class)->sendText($inactive, '628123456789', 'Test')['success'])->toBeFalse();
    Http::assertNothingSent();
});

it('reports engine downtime without claiming an account is connected', function (): void {
    $account = $this->makeConnectedWhatsAppAccount();
    Http::fake(['*' => Http::response(['ok' => false], 503)]);

    $result = app(WhatsAppGateway::class)->session($account);
    expect($result['status'])->toBe('unknown')
        ->and($result['engine_ready'])->toBeFalse()
        ->and($result['delivery_ready'])->toBeFalse()
        ->and($result['qr'])->toBeNull();
});

it('reconciles a lost send response without a second send', function (): void {
    $account = $this->makeConnectedWhatsAppAccount();
    $sends = 0;
    Http::fake(function ($request) use (&$sends) {
        if (str_ends_with($request->url(), '/send')) {
            $sends++;
            throw new ConnectionException('Operation timed out');
        }
        if (str_contains($request->url(), '/messages/')) {
            return Http::response(['ok' => true, 'status' => 'sent', 'id' => 'wa-confirmed']);
        }

        return Http::response(['ok' => true]);
    });
    $result = app(WhatsAppGateway::class)->sendText($account, '628123456789', 'Test', ['idempotency_key' => 'request-one']);

    expect($result['status'])->toBe('sent')->and($result['data']['id'])->toBe('wa-confirmed')->and($sends)->toBe(1);
});

it('leaves an ambiguous timeout unknown without automatic resend', function (): void {
    $account = $this->makeConnectedWhatsAppAccount();
    $sends = 0;
    Http::fake(function ($request) use (&$sends) {
        if (str_ends_with($request->url(), '/send')) {
            $sends++;
            throw new ConnectionException('Operation timed out');
        }
        if (str_contains($request->url(), '/messages/')) {
            return Http::response(['ok' => false], 404);
        }

        return Http::response(['ok' => true]);
    });
    $result = app(WhatsAppGateway::class)->sendText($account, '628123456789', 'Test', ['idempotency_key' => 'request-two']);

    expect($result['status'])->toBe('unknown')->and($result['retryable'])->toBeFalse()->and($sends)->toBe(1);
});

it('allows a queue retry only for a definite pre-send failure', function (): void {
    $account = $this->makeConnectedWhatsAppAccount();
    Http::fake(fn ($request) => Http::response(str_ends_with($request->url(), '/send')
        ? ['ok' => false, 'status' => 'failed', 'retryable' => true, 'error_code' => 'not_connected', 'message' => 'Reconnecting']
        : ['ok' => true]));
    $result = app(WhatsAppGateway::class)->sendText($account, '628123456789', 'Test', ['idempotency_key' => 'request-three']);
    expect($result['status'])->toBe('failed')->and($result['retryable'])->toBeTrue();
    Http::assertSentCount(2);
});

it('protects settings while offering a sanitized sender list to candidate operators', function (): void {
    $this->fakeRekrutmenWhatsAppEngine();
    $user = User::factory()->create(['is_active' => true]);
    $this->actingAs($user);
    $this->makeConnectedWhatsAppAccount();
    $this->getJson('/rekrutmen/api/settings/whatsapp')->assertForbidden();
    $this->getJson('/rekrutmen/api/whatsapp/senders')->assertForbidden();

    Permission::findOrCreate('view_any_rekrutmen_job::application', 'web');
    $user->givePermissionTo('view_any_rekrutmen_job::application');
    $this->getJson('/rekrutmen/api/whatsapp/senders')->assertSuccessful()
        ->assertJsonPath('accounts.0.delivery_ready', true)
        ->assertJsonMissingPath('accounts.0.qr')
        ->assertJsonMissingPath('accounts.0.pairing_code')
        ->assertJsonMissingPath('accounts.0.session_id');
    $this->getJson('/rekrutmen/api/settings/whatsapp')->assertForbidden();
});

it('permits only explicit managers or super admins to manage sessions', function (): void {
    $manager = User::factory()->create(['is_active' => true]);
    $manager->givePermissionTo(Permission::findOrCreate('manage_rekrutmen_whatsapp', 'web'));
    $this->actingAs($manager)->getJson('/rekrutmen/api/settings/whatsapp')->assertSuccessful();
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    expect($admin->roles()->where('name', config('filament-shield.super_admin.name', 'super_admin'))->exists())->toBeTrue();
    expect(Gate::has('manage_rekrutmen_whatsapp'))->toBeTrue();
    expect(Gate::forUser($admin)->allows('manage_rekrutmen_whatsapp'))->toBeTrue();
    $this->actingAs($admin)->getJson('/rekrutmen/api/settings/whatsapp')->assertSuccessful();
});

it('allows the production Admin role to manage WhatsApp after receiving the permission', function (): void {
    $this->fakeRekrutmenWhatsAppEngine();
    $permission = Permission::findOrCreate('manage_rekrutmen_whatsapp', 'web');
    $adminRole = Role::findOrCreate('Admin', 'web');
    $adminRole->givePermissionTo($permission);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole($adminRole);

    $this->actingAs($admin)->getJson('/rekrutmen/api/settings/whatsapp')
        ->assertSuccessful()
        ->assertJsonPath('gateway.engine_ready', true);
});

it('rejects malformed pairing before storing any account', function (): void {
    $manager = User::factory()->create(['is_active' => true]);
    $manager->givePermissionTo(Permission::findOrCreate('manage_rekrutmen_whatsapp', 'web'));
    $this->actingAs($manager)->postJson('/rekrutmen/api/settings/whatsapp/accounts/connect', [
        'mode' => 'pairing', 'phone_number' => 'abc',
    ])->assertUnprocessable()->assertJsonValidationErrors('phone_number');
    expect(WhatsAppAccount::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('disables sending but preserves the account when logout cannot be confirmed', function (): void {
    $account = $this->makeConnectedWhatsAppAccount();
    Http::fake(['*' => Http::response(['ok' => false], 503)]);
    $result = app(WhatsAppGateway::class)->disconnect($account);
    expect($result['success'])->toBeFalse()
        ->and($account->fresh()->is_active)->toBeFalse()
        ->and($account->fresh()->status)->toBe(WhatsAppAccountStatus::Disconnected);
});

it('queues a bulk submission once across delayed HTTP retries and exposes its progress', function (): void {
    $this->fakeRekrutmenWhatsAppEngine();
    Queue::fake();
    $user = User::factory()->create(['is_active' => true, 'resource_permission' => PermissionType::GLOBAL]);
    $user->givePermissionTo(Permission::findOrCreate('update_rekrutmen_job::application', 'web'));
    $this->actingAs($user);
    $account = $this->makeConnectedWhatsAppAccount();
    $pipeline = RekrutmenPipeline::query()->create(['name' => 'Delivery pipeline']);
    $stage = RekrutmenStage::query()->create(['rekrutmen_pipeline_id' => $pipeline->id, 'name' => 'Screening', 'order_column' => 1]);
    $posting = JobPosting::query()->create(['title' => 'Staff', 'slug' => 'reliable-delivery', 'rekrutmen_pipeline_id' => $pipeline->id]);
    $candidate = JobApplication::query()->create([
        'job_posting_id'  => $posting->id, 'creator_id' => $user->id,
        'full_name'       => 'Test Candidate', 'email' => 'candidate@example.test',
        'whatsapp_number' => '081234567890', 'current_stage_id' => $stage->id, 'status' => 'in_progress',
    ]);
    $payload = ['request_key' => 'bulk-stable-key', 'application_ids' => [$candidate->id], 'channels' => ['whatsapp'],
        'whatsapp_account_id' => $account->id, 'subject' => 'Invitation', 'body_message' => 'Hello', 'send_type' => 'immediate'];
    $first = $this->postJson('/rekrutmen/api/applications/bulk-send-notification', $payload)->assertAccepted();
    $this->travel(2)->seconds();
    $this->postJson('/rekrutmen/api/applications/bulk-send-notification', $payload)->assertAccepted()
        ->assertJsonPath('batch_id', $first->json('batch_id'));
    $this->assertDatabaseCount('rekrutmen_scheduled_notifications', 1);
    $this->assertDatabaseCount('rekrutmen_notification_deliveries', 1);
    Http::assertNothingSent();
    $this->getJson('/rekrutmen/api/notifications/'.$first->json('batch_id'))->assertSuccessful()
        ->assertJsonPath('details.0.whatsapp.status', 'pending');

    $other = User::factory()->create(['is_active' => true, 'resource_permission' => PermissionType::GLOBAL]);
    $other->givePermissionTo(Permission::findOrCreate('update_rekrutmen_job::application', 'web'));
    $this->actingAs($other)->getJson('/rekrutmen/api/notifications/'.$first->json('batch_id'))->assertForbidden();
    $this->actingAs($user)->postJson('/rekrutmen/api/applications/bulk-send-notification', array_merge($payload, ['body_message' => 'Different']))
        ->assertUnprocessable()->assertJsonValidationErrors('request_key');
    $past = array_merge($payload, ['request_key' => 'fresh-past-key', 'send_type' => 'scheduled', 'scheduled_at' => now()->subMinute()->toIso8601String()]);
    $this->postJson('/rekrutmen/api/applications/bulk-send-notification', $past)->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
    $future = array_merge($payload, ['request_key' => 'future-replay-key', 'send_type' => 'scheduled', 'scheduled_at' => now()->addMinute()->toIso8601String()]);
    $scheduled = $this->postJson('/rekrutmen/api/applications/bulk-send-notification', $future)->assertAccepted();
    $this->travel(2)->minutes();
    $this->postJson('/rekrutmen/api/applications/bulk-send-notification', $future)->assertAccepted()
        ->assertJsonPath('batch_id', $scheduled->json('batch_id'));

});

it('rejects an unauthorized candidate notification without creating a batch', function (): void {
    Queue::fake();
    $user = User::factory()->create(['is_active' => true]);
    $this->actingAs($user);
    $pipeline = RekrutmenPipeline::query()->create(['name' => 'Restricted']);
    $posting = JobPosting::query()->create(['title' => 'Staff', 'slug' => 'restricted', 'rekrutmen_pipeline_id' => $pipeline->id]);
    $candidate = JobApplication::query()->create([
        'job_posting_id' => $posting->id,
        'full_name'      => 'Restricted Candidate', 'email' => 'restricted@example.test', 'status' => 'in_progress',
    ]);
    $this->postJson('/rekrutmen/api/applications/'.$candidate->id.'/send-notification', [
        'subject' => 'Invitation', 'body_message' => 'Hello', 'channels' => ['email'],
    ])->assertForbidden();
    $this->assertDatabaseCount('rekrutmen_scheduled_notifications', 0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('reuses a newly created account when the connect response was lost', function (): void {
    $this->fakeRekrutmenWhatsAppEngine(['status' => 'qr', 'qr' => 'data:image/png;base64,qr']);
    $manager = User::factory()->create(['is_active' => true]);
    $manager->givePermissionTo(Permission::findOrCreate('manage_rekrutmen_whatsapp', 'web'));
    $this->actingAs($manager);
    $payload = ['request_key' => 'connect-unchanged-request', 'name' => 'HR', 'mode' => 'qr'];
    $first = $this->postJson('/rekrutmen/api/settings/whatsapp/accounts/connect', $payload)->assertCreated();
    $this->postJson('/rekrutmen/api/settings/whatsapp/accounts/connect', $payload)->assertCreated()
        ->assertJsonPath('data.id', $first->json('data.id'));
    $this->assertDatabaseCount('rekrutmen_whatsapp_accounts', 1);
    $this->assertNull($first->json('data.connection_request_key'));
});
