<?php

namespace App\Http\Controllers;

use App\Models\WagIntegration;
use App\Services\WhatsApp\WagHubIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WagWebhookController extends Controller
{
    public function __invoke(Request $request, WagHubIntegration $integration): JsonResponse
    {
        $stored = WagIntegration::current();
        $timestamp = (string) $request->header('X-Wag-Timestamp');
        $signature = (string) $request->header('X-Wag-Signature');
        abort_unless($stored->webhook_secret && ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300, 401);
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $stored->webhook_secret);
        abort_unless(hash_equals($expected, preg_replace('/^sha256=/', '', $signature)), 401);
        $event = Validator::make($request->json()->all(), [
            'id'       => ['required', 'string', 'max:100'], 'installation_id' => ['required', 'string', 'max:100'],
            'event'    => ['required', 'in:message,message.ack,session.status'], 'session_id' => ['required', 'string'],
            'revision' => ['required', 'integer', 'min:0'], 'occurred_at' => ['required', 'date'], 'data' => ['required', 'array'],
        ])->validate();
        abort_unless((string) $event['installation_id'] === (string) $stored->installation_id, 403);
        $integration->ingest($event);

        return response()->json(['received' => true]);
    }
}
