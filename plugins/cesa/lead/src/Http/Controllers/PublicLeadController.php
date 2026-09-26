<?php

namespace Cesa\Lead\Http\Controllers;

use Cesa\Lead\Http\Requests\CheckPublicLeadWhatsAppRequest;
use Cesa\Lead\Http\Requests\SubmitPublicLeadRequest;
use Cesa\Lead\Models\Lead;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;
use Webkul\PluginManager\Package;

class PublicLeadController extends Controller
{
    protected const WHATSAPP_VALIDATION_STATUS_SUCCESS = 'success';

    protected const WHATSAPP_VALIDATION_STATUS_NOT_REGISTERED = 'not_registered';

    protected const WHATSAPP_VALIDATION_STATUS_INVALID = 'invalid';

    protected const WHATSAPP_VALIDATION_STATUS_RATE_LIMITED = 'rate_limited';

    protected const WHATSAPP_VALIDATION_STATUS_FAILED = 'failed';

    public function submit(SubmitPublicLeadRequest $request): JsonResponse
    {
        $input = $request->validated();

        $recaptchaConfig = config('lead.security.recaptcha', []);
        $recaptchaEnabled = (bool) Arr::get($recaptchaConfig, 'enabled', false)
            && filled(Arr::get($recaptchaConfig, 'site_key'))
            && filled(Arr::get($recaptchaConfig, 'secret_key'));

        if ($recaptchaEnabled) {
            $token = $request->input('recaptcha_token');
            if (! $this->verifyRecaptcha($token, $recaptchaConfig)) {
                return response()->json([
                    'message' => __('lead::views/public-lead-form.recaptcha.failed'),
                    'errors'  => ['recaptcha_token' => [__('lead::views/public-lead-form.recaptcha.failed')]],
                ], 422);
            }
        }

        $whatsappConfig = config('lead.whatsapp_validation', []);
        $whatsappEnabled = (bool) Arr::get($whatsappConfig, 'enabled', false)
            && filled(config('lead.whatsapp_validation.endpoint'))
            && filled(Arr::get($whatsappConfig, 'token'));

        if ($whatsappEnabled) {
            $phone = $input['phone'];
            if (! $this->hasSuccessfulWhatsAppValidation($phone, $whatsappConfig)) {
                return response()->json([
                    'message' => __('lead::views/public-lead-form.whatsapp_validation.required_success'),
                    'errors'  => ['phone' => [__('lead::views/public-lead-form.whatsapp_validation.required_success')]],
                ], 422);
            }
        }

        try {
            $lead = Lead::create([
                'name'                    => $input['name'],
                'phone'                   => $input['phone'],
                'address'                 => $input['address'],
                'sales_person'            => $input['sales_person'],
                'store_team_position'     => $input['store_team_position'],
                'store_branch'            => $input['store_branch'],
                'phone_transaction_range' => $input['phone_transaction_range'] ?? null,
                'creator_id'              => null,
            ]);

            return response()->json([
                'success'      => true,
                'redirect_url' => $lead->getPublicProgressUrl(),
                'message'      => __('lead::views/public-lead-form.messages.success'),
            ]);
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'leads_phone_unique')) {
                return response()->json([
                    'message' => __('lead::filament/resources/lead.validation.phone_unique'),
                    'errors'  => ['phone' => [__('lead::filament/resources/lead.validation.phone_unique')]],
                ], 422);
            }

            Log::error('Public lead Vue submission failed (query exception)', ['exception' => $e]);

            return response()->json(['message' => __('lead::views/public-lead-form.messages.generic')], 500);
        } catch (Throwable $e) {
            Log::error('Public lead Vue submission failed', ['exception' => $e]);

            return response()->json(['message' => __('lead::views/public-lead-form.messages.generic')], 500);
        }
    }

    public function checkWhatsApp(CheckPublicLeadWhatsAppRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');

        $whatsappConfig = config('lead.whatsapp_validation', []);

        if (! Arr::get($whatsappConfig, 'enabled', false) || blank(Arr::get($whatsappConfig, 'endpoint')) || blank(Arr::get($whatsappConfig, 'token'))) {
            return response()->json([
                'status'  => self::WHATSAPP_VALIDATION_STATUS_FAILED,
                'message' => __('lead::views/public-lead-form.whatsapp_validation.failed'),
            ], 422);
        }

        $result = $this->requestWhatsAppValidation($phone, $whatsappConfig);

        $errorMessage = match ($result['status']) {
            self::WHATSAPP_VALIDATION_STATUS_NOT_REGISTERED => __('lead::views/public-lead-form.whatsapp_validation.not_registered'),
            self::WHATSAPP_VALIDATION_STATUS_INVALID        => __('lead::views/public-lead-form.whatsapp_validation.invalid'),
            self::WHATSAPP_VALIDATION_STATUS_RATE_LIMITED   => __('lead::views/public-lead-form.whatsapp_validation.rate_limited'),
            self::WHATSAPP_VALIDATION_STATUS_FAILED         => __('lead::views/public-lead-form.whatsapp_validation.failed'),
            default                                         => null,
        };

        return response()->json([
            'status'  => $result['status'],
            'phone'   => $phone,
            'message' => $errorMessage ?: __('lead::views/public-lead-form.whatsapp_validation.success'),
        ]);
    }

    protected function hasSuccessfulWhatsAppValidation(string $phone, array $config): bool
    {
        $cacheKey = sprintf('lead:whatsapp-validation:%s', $phone);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && ($cached['status'] ?? '') === self::WHATSAPP_VALIDATION_STATUS_SUCCESS) {
            return true;
        }

        $result = $this->requestWhatsAppValidation($phone, $config);

        return ($result['status'] ?? '') === self::WHATSAPP_VALIDATION_STATUS_SUCCESS;
    }

    protected function requestWhatsAppValidation(string $phone, array $config): array
    {
        $cacheTtl = (int) Arr::get($config, 'cache_ttl', 300);
        $cacheKey = sprintf('lead:whatsapp-validation:%s', $phone);

        if ($cacheTtl > 0) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $maxAttempts = (int) Arr::get($config, 'rate_limit.max_attempts', 10);
        $decaySeconds = (int) Arr::get($config, 'rate_limit.decay', 60);
        $rateLimitKey = sprintf('lead:whatsapp-validation:%s:%s', request()?->ip() ?: 'guest', $phone);

        if ($maxAttempts > 0 && RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            return ['status' => self::WHATSAPP_VALIDATION_STATUS_RATE_LIMITED];
        }

        if ($maxAttempts > 0) {
            RateLimiter::hit($rateLimitKey, $decaySeconds);
        }

        $endpoint = config('lead.whatsapp_validation.endpoint');
        $token = Arr::get($config, 'token');
        $timeout = (int) Arr::get($config, 'timeout', 5);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'Authorization' => 'Bearer '.$token,
                ])
                ->post(rtrim((string) $endpoint, '/').'/api/v1/number-checks', [
                    'recipient' => [
                        'type'  => 'phone',
                        'value' => $phone,
                    ],
                    'route_key' => 'default',
                ]);

            if ($response->status() === 422) {
                $result = ['status' => self::WHATSAPP_VALIDATION_STATUS_INVALID];
            } elseif ($response->successful()) {
                $payload = $response->json();
                $isRegistered = Arr::get($payload, 'data.registered');

                if ($isRegistered === true) {
                    $result = ['status' => self::WHATSAPP_VALIDATION_STATUS_SUCCESS];
                } elseif ($isRegistered === false || Arr::get($payload, 'data.status') === 'not_registered') {
                    $result = ['status' => self::WHATSAPP_VALIDATION_STATUS_NOT_REGISTERED];
                } else {
                    $result = ['status' => self::WHATSAPP_VALIDATION_STATUS_FAILED];
                }
            } else {
                $result = ['status' => self::WHATSAPP_VALIDATION_STATUS_FAILED];
            }
        } catch (Throwable $e) {
            Log::warning('WhatsApp validation request exception', ['error' => $e->getMessage()]);
            $result = ['status' => self::WHATSAPP_VALIDATION_STATUS_FAILED];
        }

        if ($cacheTtl > 0 && in_array($result['status'], [
            self::WHATSAPP_VALIDATION_STATUS_SUCCESS,
            self::WHATSAPP_VALIDATION_STATUS_NOT_REGISTERED,
            self::WHATSAPP_VALIDATION_STATUS_INVALID,
        ], true)) {
            Cache::put($cacheKey, $result, now()->addSeconds($cacheTtl));
        }

        return $result;
    }

    protected function verifyRecaptcha(string $token, array $config): bool
    {
        $secretKey = Arr::get($config, 'secret_key');
        $expectedAction = Arr::get($config, 'action', 'lead_request');
        $scoreThreshold = (float) Arr::get($config, 'score_threshold', 0.5);
        $timeout = (int) Arr::get($config, 'timeout', 5);

        try {
            $response = Http::asForm()
                ->timeout($timeout)
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret'   => $secretKey,
                    'response' => $token,
                    'remoteip' => request()?->ip(),
                ]);

            if (! $response->successful()) {
                return false;
            }

            $body = $response->json();
            $success = (bool) Arr::get($body, 'success', false);
            $action = (string) Arr::get($body, 'action', '');
            $score = (float) Arr::get($body, 'score', 0.0);

            if (! $success) {
                return false;
            }

            if (filled($expectedAction) && $action !== $expectedAction) {
                return false;
            }

            if ($scoreThreshold > 0.0 && $score < $scoreThreshold) {
                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('Recaptcha verification exception', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
