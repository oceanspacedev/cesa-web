<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Enums\WhatsAppAccountStatus;
use Cesa\Rekrutmen\Services\WhatsAppGateway;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

class WhatsAppGatewayHardeningTest extends RekrutmenTestCase
{
    public function test_ambiguous_send_failure_is_not_retried_or_marked_disconnected(): void
    {
        config([
            'rekrutmen.notifications.whatsapp.engine_url'   => 'http://127.0.0.1:3318',
            'rekrutmen.notifications.whatsapp.engine_token' => 'test-engine-token',
            'rekrutmen.notifications.whatsapp.auto_start'   => false,
            'rekrutmen.notifications.whatsapp.enabled'      => true,
        ]);

        $account = $this->makeConnectedWhatsAppAccount();
        $attempts = 0;

        Http::fake(function (HttpRequest $request) use (&$attempts) {
            if (str_contains($request->url(), '/health')) {
                return Http::response(['ok' => true], 200);
            }

            if (str_contains($request->url(), '/send')) {
                $attempts++;

                if ($attempts === 1) {
                    return Http::response(['ok' => false, 'message' => 'cURL error 28: Operation timed out'], 500);
                }

                return Http::response(['ok' => true, 'status' => 'sent'], 200);
            }

            return Http::response(['ok' => true, 'status' => 'connected'], 200);
        });

        $result = app(WhatsAppGateway::class)->sendText($account, '6281299990000', 'Tes hardening');

        $this->assertFalse($result['success']);
        $this->assertSame('unknown', $result['status']);
        $this->assertSame(WhatsAppAccountStatus::Connected, $account->fresh()?->status);
        $this->assertSame(1, $attempts);
    }

    public function test_unavailable_session_is_returned_to_queue_without_inline_retry(): void
    {
        config([
            'rekrutmen.notifications.whatsapp.engine_url'   => 'http://127.0.0.1:3318',
            'rekrutmen.notifications.whatsapp.engine_token' => 'test-engine-token',
            'rekrutmen.notifications.whatsapp.auto_start'   => false,
            'rekrutmen.notifications.whatsapp.enabled'      => true,
        ]);

        $account = $this->makeConnectedWhatsAppAccount();

        Http::fake(function (HttpRequest $request) {
            if (str_contains($request->url(), '/health')) {
                return Http::response(['ok' => true], 200);
            }

            if (str_contains($request->url(), '/send')) {
                return Http::response([
                    'ok'        => false,
                    'status'    => 'failed',
                    'retryable' => true,
                    'message'   => 'Nomor WhatsApp belum terhubung. Scan QR atau minta kode pairing di pengaturan rekrutmen.',
                ], 409);
            }

            return Http::response(['ok' => true, 'status' => 'disconnected'], 200);
        });

        $result = app(WhatsAppGateway::class)->sendText($account, '6281299990000', 'Tes hardening');

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $result['status']);
        $this->assertTrue($result['retryable']);
    }

    public function test_authoritative_disconnected_status_is_not_hidden_by_a_cached_connection(): void
    {
        $this->fakeRekrutmenWhatsAppEngine([
            'status' => 'disconnected',
            'error'  => null,
            'phone'  => null,
        ]);

        $account = $this->makeConnectedWhatsAppAccount([
            'phone_number' => '6287815742597',
        ]);

        $payload = app(WhatsAppGateway::class)->session($account);

        $this->assertSame(WhatsAppAccountStatus::Disconnected, $account->fresh()?->status);
        $this->assertFalse($payload['delivery_ready']);
        $this->assertSame('6287815742597', $account->fresh()?->phone_number);
        $this->assertSame('disconnected', $payload['status']);
    }
}
