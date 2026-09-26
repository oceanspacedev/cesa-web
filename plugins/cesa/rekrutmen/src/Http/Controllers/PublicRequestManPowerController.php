<?php

namespace Cesa\Rekrutmen\Http\Controllers;

use Cesa\Rekrutmen\Enums\StatusKebutuhan;
use Cesa\Rekrutmen\Http\Requests\SubmitPublicRequestManPowerRequest;
use Cesa\Rekrutmen\Models\Division;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Services\RecaptchaVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublicRequestManPowerController extends Controller
{
    public function submit(SubmitPublicRequestManPowerRequest $request): JsonResponse
    {
        $input = $request->validated();
        $isReplacement = $input['status_kebutuhan'] === StatusKebutuhan::REPLACEMENT->value;

        $recaptcha = config('rekrutmen.security.recaptcha', []);
        $recaptchaEnabled = (bool) Arr::get($recaptcha, 'enabled', false)
            && filled(Arr::get($recaptcha, 'site_key'))
            && filled(Arr::get($recaptcha, 'secret_key'));

        if ($recaptchaEnabled) {
            $token = (string) $request->input('recaptcha_token');
            $service = new RecaptchaVerificationService;
            $isValid = $service->verify(
                $token,
                Arr::get($recaptcha, 'secret_key'),
                Arr::get($recaptcha, 'action', 'request_man_power'),
                request()?->getHost(),
                (float) Arr::get($recaptcha, 'score_threshold', 0.0),
                (int) Arr::get($recaptcha, 'timeout', 5),
                request()?->ip()
            );

            if (! $isValid) {
                return response()->json([
                    'message' => __('rekrutmen::livewire/public-request-man-power-form.errors.recaptcha_failed'),
                    'errors'  => ['recaptcha_token' => [__('rekrutmen::livewire/public-request-man-power-form.errors.recaptcha_failed')]],
                ], 422);
            }
        }

        try {
            $dataToSave = Arr::except($input, ['recaptcha_token']);
            $dataToSave['tanggal_pengajuan'] = now()->toDateString();

            if (! $isReplacement) {
                $dataToSave['nama_karyawan_replacement'] = null;
            }

            $rmp = RequestManPower::create($dataToSave);

            $rmp->sendSubmittedNotification();
            $rmp->sendApprovalRequestNotifications();

            $statusLabel = $rmp->status_kebutuhan instanceof StatusKebutuhan
                ? $rmp->status_kebutuhan->getLabel()
                : (string) $rmp->status_kebutuhan;

            return response()->json([
                'success'           => true,
                'redirect_url'      => $rmp->getPublicProgressUrl(),
                'recent_submission' => [
                    'id'                 => $rmp->getKey(),
                    'status_response_id' => $rmp->status_response_id,
                    'progress_url'       => $rmp->getPublicProgressUrl(),
                    'posisi_dibutuhkan'  => $rmp->posisi_dibutuhkan,
                    'nama_pengaju'       => $rmp->nama_pengaju,
                    'status_kebutuhan'   => $statusLabel,
                    'nama_replacement'   => $rmp->nama_karyawan_replacement,
                ],
                'message'           => __('rekrutmen::livewire/public-request-man-power-form.notifications.success.body'),
            ]);
        } catch (Throwable $e) {
            Log::error('Public request man power Vue submission failed', ['exception' => $e]);

            return response()->json([
                'message' => __('rekrutmen::livewire/public-request-man-power-form.errors.system'),
            ], 500);
        }
    }
}
