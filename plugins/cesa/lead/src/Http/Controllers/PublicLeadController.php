<?php

namespace Cesa\Lead\Http\Controllers;

use Cesa\Lead\Models\Lead;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Throwable;
use Webkul\PluginManager\Package;

class PublicLeadController extends Controller
{
    protected const WHATSAPP_VALIDATION_STATUS_SUCCESS = 'success';

    protected const WHATSAPP_VALIDATION_STATUS_NOT_REGISTERED = 'not_registered';

    protected const WHATSAPP_VALIDATION_STATUS_INVALID = 'invalid';

    protected const WHATSAPP_VALIDATION_STATUS_RATE_LIMITED = 'rate_limited';

    protected const WHATSAPP_VALIDATION_STATUS_FAILED = 'failed';

    public function index(Request $request): View
    {
        if (! Package::isPluginInstalled('lead')) {
            abort(404);
        }

        $whatsappConfig = config('lead.whatsapp_validation', []);
        $whatsappEnabled = (bool) Arr::get($whatsappConfig, 'enabled', false)
            && filled(config('lead.whatsapp_validation.endpoint'))
            && filled(Arr::get($whatsappConfig, 'token'));

        $recaptchaConfig = config('lead.security.recaptcha', []);
        $recaptchaEnabled = (bool) Arr::get($recaptchaConfig, 'enabled', false)
            && filled(Arr::get($recaptchaConfig, 'site_key'))
            && filled(Arr::get($recaptchaConfig, 'secret_key'));

        $storeBranches = array_values(config('lead.store_branches', []));

        $storeTeamPositions = [
            ['value' => 'Kepala Toko', 'label' => __('lead::filament/resources/lead.options.store_team_position.kepala_toko')],
            ['value' => 'Promotor', 'label' => __('lead::filament/resources/lead.options.store_team_position.promotor')],
            ['value' => 'Kasir', 'label' => __('lead::filament/resources/lead.options.store_team_position.kasir')],
            ['value' => 'Frontliner', 'label' => __('lead::filament/resources/lead.options.store_team_position.frontliner')],
        ];

        $phoneTransactionRanges = [
            ['value' => 'Harga di bawah 2 juta', 'label' => __('lead::filament/resources/lead.options.phone_transaction_range.below_2m')],
            ['value' => 'Harga 2 - 3 juta', 'label' => __('lead::filament/resources/lead.options.phone_transaction_range.2m_3m')],
            ['value' => 'Harga 3 - 4 juta', 'label' => __('lead::filament/resources/lead.options.phone_transaction_range.3m_4m')],
            ['value' => 'Harga 4 - 7 juta', 'label' => __('lead::filament/resources/lead.options.phone_transaction_range.4m_7m')],
            ['value' => 'Harga di atas 7 juta', 'label' => __('lead::filament/resources/lead.options.phone_transaction_range.above_7m')],
        ];

        $config = [
            'storeBranches'          => $storeBranches,
            'storeTeamPositions'     => $storeTeamPositions,
            'phoneTransactionRanges' => $phoneTransactionRanges,
            'whatsapp'               => [
                'enabled'     => $whatsappEnabled,
                'countryCode' => (string) Arr::get($whatsappConfig, 'country_code', '62'),
            ],
            'recaptcha'              => [
                'enabled' => $recaptchaEnabled,
                'siteKey' => Arr::get($recaptchaConfig, 'site_key'),
                'action'  => Arr::get($recaptchaConfig, 'action', 'lead_request'),
            ],
            'i18n'                   => [
                'title'                  => __('lead::views/public-lead-form.title'),
                'description'            => __('lead::views/public-lead-form.description'),
                'requiredNote'           => __('lead::views/public-lead-form.required'),
                'pageInfo'               => __('lead::views/public-lead-form.pagination.single_page', ['current' => 1, 'total' => 1]),
                'submit'                 => __('lead::views/public-lead-form.actions.submit'),
                'submitting'             => 'Mengirim...',
                'fields'                 => [
                    'name'                   => __('lead::filament/resources/lead.fields.name'),
                    'phone'                  => __('lead::filament/resources/lead.fields.phone'),
                    'address'                => __('lead::filament/resources/lead.fields.address'),
                    'sales_person'           => __('lead::filament/resources/lead.fields.sales_person'),
                    'store_team_position'    => __('lead::filament/resources/lead.fields.store_team_position'),
                    'store_branch'           => __('lead::filament/resources/lead.fields.store_branch'),
                    'phone_transaction_range'=> __('lead::filament/resources/lead.fields.phone_transaction_range'),
                ],
                'placeholders'           => [
                    'name'                   => __('lead::filament/resources/lead.form.placeholders.name'),
                    'phone'                  => __('lead::filament/resources/lead.form.placeholders.phone'),
                    'address'                => __('lead::filament/resources/lead.form.placeholders.address'),
                    'sales_person'           => __('lead::filament/resources/lead.form.placeholders.sales_person'),
                    'store_team_position'    => __('lead::filament/resources/lead.form.placeholders.choose'),
                    'store_branch'           => __('lead::filament/resources/lead.form.placeholders.store_branch'),
                    'phone_transaction_range'=> __('lead::filament/resources/lead.form.placeholders.phone_transaction_range'),
                ],
                'whatsapp'               => [
                    'action'          => __('lead::views/public-lead-form.whatsapp_validation.action'),
                    'hint'            => __('lead::views/public-lead-form.whatsapp_validation.hint'),
                    'success'         => __('lead::views/public-lead-form.whatsapp_validation.success'),
                    'not_registered'  => __('lead::views/public-lead-form.whatsapp_validation.not_registered'),
                    'invalid'         => __('lead::views/public-lead-form.whatsapp_validation.invalid'),
                    'rate_limited'    => __('lead::views/public-lead-form.whatsapp_validation.rate_limited'),
                    'failed'          => __('lead::views/public-lead-form.whatsapp_validation.failed'),
                    'requiredSuccess' => __('lead::views/public-lead-form.whatsapp_validation.required_success'),
                ],
                'validation'             => [
                    'required'     => 'Pertanyaan ini wajib diisi.',
                    'phone_format' => __('lead::filament/resources/lead.validation.phone_format'),
                    'phone_unique' => __('lead::filament/resources/lead.validation.phone_unique'),
                ],
                'messages'               => [
                    'success' => __('lead::views/public-lead-form.messages.success'),
                    'generic' => __('lead::views/public-lead-form.messages.generic'),
                ],
            ],
            'routes'                 => [
                'submit'        => route('lead.public.api.submit'),
                'checkWhatsapp' => route('lead.public.api.check-whatsapp'),
            ],
        ];

        return view('lead::public-form', compact('config'));
    }

    public function submit(Request $request): JsonResponse
    {
        if (! Package::isPluginInstalled('lead')) {
            return response()->json(['message' => 'Plugin not found'], 404);
        }

        $input = $request->all();
        $input['phone'] = Lead::normalizePhone((string) ($input['phone'] ?? ''));

        $rules = [
            'name'                    => ['required', 'string', 'max:255'],
            'phone'                   => [
                'required',
                'string',
                'max:15',
                'regex:/^62[0-9]{8,}$/',
                'unique:leads,phone',
            ],
            'address'                 => ['required', 'string'],
            'sales_person'            => ['required', 'string', 'max:255'],
            'store_team_position'     => ['required', 'string', 'in:Kepala Toko,Promotor,Kasir,Frontliner'],
            'store_branch'            => ['required', 'string', 'in:'.implode(',', config('lead.store_branches', []))],
            'phone_transaction_range' => ['nullable', 'string'],
        ];

        $messages = [
            'name.required'                => 'Nama lengkap wajib diisi.',
            'phone.required'               => __('lead::filament/resources/lead.validation.phone_required'),
            'phone.regex'                  => __('lead::filament/resources/lead.validation.phone_format'),
            'phone.unique'                 => __('lead::filament/resources/lead.validation.phone_unique'),
            'address.required'             => 'Alamat lengkap wajib diisi.',
            'sales_person.required'        => 'Sales person wajib diisi.',
            'store_team_position.required' => 'Jabatan tim toko wajib dipilih.',
            'store_branch.required'        => 'Cabang toko wajib dipilih.',
        ];

        $recaptchaConfig = config('lead.security.recaptcha', []);
        $recaptchaEnabled = (bool) Arr::get($recaptchaConfig, 'enabled', false)
            && filled(Arr::get($recaptchaConfig, 'site_key'))
            && filled(Arr::get($recaptchaConfig, 'secret_key'));

        if ($recaptchaEnabled) {
            $rules['recaptcha_token'] = ['required', 'string'];
            $messages['recaptcha_token.required'] = __('lead::views/public-lead-form.recaptcha.required');
        }

        $validator = Validator::make($input, $rules, $messages);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

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

    public function checkWhatsApp(Request $request): JsonResponse
    {
        $phone = Lead::normalizePhone((string) $request->input('phone'));

        if (blank($phone)) {
            return response()->json([
                'status'  => self::WHATSAPP_VALIDATION_STATUS_INVALID,
                'message' => __('lead::filament/resources/lead.validation.phone_required'),
            ], 422);
        }

        if (Lead::withTrashed()->where('phone', $phone)->exists()) {
            return response()->json([
                'status'  => self::WHATSAPP_VALIDATION_STATUS_INVALID,
                'message' => __('lead::filament/resources/lead.validation.phone_unique'),
            ], 422);
        }

        $whatsappConfig = config('lead.whatsapp_validation', []);
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
