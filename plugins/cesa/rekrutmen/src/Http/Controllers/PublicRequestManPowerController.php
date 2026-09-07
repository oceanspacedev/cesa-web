<?php

namespace Cesa\Rekrutmen\Http\Controllers;

use Cesa\Rekrutmen\Enums\StatusKebutuhan;
use Cesa\Rekrutmen\Models\Division;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Services\RecaptchaVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;
use Webkul\Support\Models\Company;

class PublicRequestManPowerController extends Controller
{
    public function index(Request $request): View
    {
        $companies = Company::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name])
            ->all();

        $divisions = Division::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'company_id'])
            ->map(fn (Division $d) => [
                'id'         => $d->id,
                'name'       => $d->name,
                'company_id' => $d->company_id,
            ])
            ->all();

        $statusKebutuhanOptions = [
            [
                'value' => StatusKebutuhan::NEW_HIRING->value,
                'label' => StatusKebutuhan::NEW_HIRING->getLabel() ?: 'Penambahan Baru',
            ],
            [
                'value' => StatusKebutuhan::REPLACEMENT->value,
                'label' => StatusKebutuhan::REPLACEMENT->getLabel() ?: 'Penggantian Karyawan',
            ],
        ];

        $levelPekerjaanOptions = collect(RequestManPower::getTranslatedLevelPekerjaanOptions())
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();

        $recaptcha = config('rekrutmen.security.recaptcha', []);
        $recaptchaEnabled = (bool) Arr::get($recaptcha, 'enabled', false)
            && filled(Arr::get($recaptcha, 'site_key'))
            && filled(Arr::get($recaptcha, 'secret_key'));

        $config = [
            'companies'              => $companies,
            'divisions'              => $divisions,
            'statusKebutuhanOptions' => $statusKebutuhanOptions,
            'levelPekerjaanOptions'  => $levelPekerjaanOptions,
            'recaptcha'              => [
                'enabled' => $recaptchaEnabled,
                'siteKey' => Arr::get($recaptcha, 'site_key'),
                'action'  => Arr::get($recaptcha, 'action', 'request_man_power'),
            ],
            'i18n'                   => [
                'title'         => __('rekrutmen::livewire/public-request-man-power-form.header.title'),
                'description'   => __('rekrutmen::livewire/public-request-man-power-form.header.description'),
                'requiredNote'  => __('rekrutmen::livewire/public-request-man-power-form.header.required'),
                'pageInfo'      => __('rekrutmen::livewire/public-request-man-power-form.pagination.single_page', ['current' => 1, 'total' => 1]),
                'submit'        => __('rekrutmen::livewire/public-request-man-power-form.actions.submit'),
                'submitting'    => 'Mengirim...',
                'fields'        => [
                    'nama_pengaju'               => __('rekrutmen::livewire/public-request-man-power-form.fields.nama_pengaju'),
                    'email_address'              => __('rekrutmen::livewire/public-request-man-power-form.fields.email_address'),
                    'posisi_pengaju'             => __('rekrutmen::livewire/public-request-man-power-form.fields.posisi_pengaju'),
                    'company_id'                 => __('rekrutmen::livewire/public-request-man-power-form.fields.company_id'),
                    'division_id'                => __('rekrutmen::livewire/public-request-man-power-form.fields.division_id'),
                    'status_kebutuhan'           => __('rekrutmen::livewire/public-request-man-power-form.fields.status_kebutuhan'),
                    'nama_karyawan_replacement'  => __('rekrutmen::livewire/public-request-man-power-form.fields.nama_karyawan_replacement'),
                    'posisi_dibutuhkan'          => __('rekrutmen::livewire/public-request-man-power-form.fields.posisi_dibutuhkan'),
                    'level_pekerjaan'            => __('rekrutmen::livewire/public-request-man-power-form.fields.level_pekerjaan'),
                    'jumlah_karyawan_dibutuhkan' => __('rekrutmen::livewire/public-request-man-power-form.fields.jumlah_karyawan_dibutuhkan'),
                    'lokasi_penempatan'          => __('rekrutmen::livewire/public-request-man-power-form.fields.lokasi_penempatan'),
                    'estimasi_tanggal_join'      => __('rekrutmen::livewire/public-request-man-power-form.fields.estimasi_tanggal_join'),
                    'job_description'            => __('rekrutmen::livewire/public-request-man-power-form.fields.job_description'),
                    'requirements_kualifikasi'   => __('rekrutmen::livewire/public-request-man-power-form.fields.requirements_kualifikasi'),
                    'keterangan'                 => __('rekrutmen::livewire/public-request-man-power-form.fields.keterangan'),
                ],
                'placeholders'  => [
                    'nama_pengaju'               => __('rekrutmen::livewire/public-request-man-power-form.placeholders.nama_pengaju'),
                    'email_address'              => __('rekrutmen::livewire/public-request-man-power-form.placeholders.email_address'),
                    'posisi_pengaju'             => __('rekrutmen::livewire/public-request-man-power-form.placeholders.posisi_pengaju'),
                    'company_id'                 => 'Pilih badan usaha / perusahaan',
                    'division_id'                => 'Pilih divisi',
                    'status_kebutuhan'           => 'Pilih status kebutuhan',
                    'nama_karyawan_replacement'  => __('rekrutmen::livewire/public-request-man-power-form.placeholders.nama_karyawan_replacement'),
                    'posisi_dibutuhkan'          => __('rekrutmen::livewire/public-request-man-power-form.placeholders.posisi_dibutuhkan'),
                    'level_pekerjaan'            => 'Pilih level pekerjaan',
                    'lokasi_penempatan'          => __('rekrutmen::livewire/public-request-man-power-form.placeholders.lokasi_penempatan'),
                    'job_description'            => __('rekrutmen::livewire/public-request-man-power-form.placeholders.job_description'),
                    'requirements_kualifikasi'   => __('rekrutmen::livewire/public-request-man-power-form.placeholders.requirements_kualifikasi'),
                    'keterangan'                 => __('rekrutmen::livewire/public-request-man-power-form.placeholders.keterangan'),
                ],
                'helperTexts'   => [
                    'nama_karyawan_replacement' => __('rekrutmen::livewire/public-request-man-power-form.helper_texts.nama_karyawan_replacement'),
                ],
                'notifications' => [
                    'validation_title' => __('rekrutmen::livewire/public-request-man-power-form.notifications.validation.title'),
                    'validation_body'  => __('rekrutmen::livewire/public-request-man-power-form.notifications.validation.body'),
                    'success_title'    => __('rekrutmen::livewire/public-request-man-power-form.notifications.success.title'),
                    'success_body'     => __('rekrutmen::livewire/public-request-man-power-form.notifications.success.body'),
                ],
                'errors'        => [
                    'replacement_required' => __('rekrutmen::livewire/public-request-man-power-form.errors.nama_karyawan_replacement_required'),
                    'system'               => __('rekrutmen::livewire/public-request-man-power-form.errors.system'),
                    'recaptcha_required'   => __('rekrutmen::livewire/public-request-man-power-form.errors.recaptcha_required'),
                    'recaptcha_failed'     => __('rekrutmen::livewire/public-request-man-power-form.errors.recaptcha_failed'),
                ],
            ],
            'routes'                 => [
                'submit' => route('rekrutmen.public.request-man-power.api.submit'),
            ],
        ];

        return view('rekrutmen::public-form', compact('config'));
    }

    public function submit(Request $request): JsonResponse
    {
        $input = $request->all();

        $rules = [
            'nama_pengaju'               => ['required', 'string', 'max:255'],
            'email_address'              => ['required', 'email', 'max:255'],
            'posisi_pengaju'             => ['required', 'string', 'max:255'],
            'company_id'                 => ['required', 'integer', 'exists:companies,id'],
            'division_id'                => ['required', 'integer', 'exists:rekrutmen_divisions,id'],
            'status_kebutuhan'           => ['required', 'string'],
            'posisi_dibutuhkan'          => ['required', 'string', 'max:255'],
            'level_pekerjaan'            => ['required', 'string', Rule::in(RequestManPower::LEVEL_PEKERJAAN_OPTIONS)],
            'jumlah_karyawan_dibutuhkan' => ['required', 'integer', 'min:1'],
            'lokasi_penempatan'          => ['required', 'string', 'max:255'],
            'estimasi_tanggal_join'      => ['required', 'date'],
            'job_description'            => ['required', 'string'],
            'requirements_kualifikasi'   => ['required', 'string'],
            'keterangan'                 => ['nullable', 'string'],
        ];

        $isReplacement = in_array($input['status_kebutuhan'] ?? null, [
            StatusKebutuhan::REPLACEMENT->value,
            StatusKebutuhan::REPLACEMENT->name,
            'Replacement',
            'replacement',
        ], true);

        if ($isReplacement) {
            $rules['nama_karyawan_replacement'] = ['required', 'string', 'max:255'];
        } else {
            $rules['nama_karyawan_replacement'] = ['nullable', 'string', 'max:255'];
        }

        $recaptcha = config('rekrutmen.security.recaptcha', []);
        $recaptchaEnabled = (bool) Arr::get($recaptcha, 'enabled', false)
            && filled(Arr::get($recaptcha, 'site_key'))
            && filled(Arr::get($recaptcha, 'secret_key'));

        if ($recaptchaEnabled) {
            $rules['recaptcha_token'] = ['required', 'string'];
        }

        $messages = [
            'nama_karyawan_replacement.required' => __('rekrutmen::livewire/public-request-man-power-form.errors.nama_karyawan_replacement_required'),
            'recaptcha_token.required'           => __('rekrutmen::livewire/public-request-man-power-form.errors.recaptcha_required'),
        ];

        $validator = Validator::make($input, $rules, $messages);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('rekrutmen::livewire/public-request-man-power-form.notifications.validation.body'),
                'errors'  => $validator->errors(),
            ], 422);
        }

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
