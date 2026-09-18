<?php

namespace Cesa\Rekrutmen\Http\Requests;

use Cesa\Rekrutmen\Enums\StatusKebutuhan;
use Cesa\Rekrutmen\Models\RequestManPower;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class SubmitPublicRequestManPowerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'nama_pengaju'               => ['required', 'string', 'max:255'],
            'email_address'              => ['required', 'email', 'max:255'],
            'posisi_pengaju'             => ['required', 'string', 'max:255'],
            'company_id'                 => ['bail', 'required', 'integer', Rule::exists('companies', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'division_id'                => ['bail', 'required', 'integer', Rule::exists('rekrutmen_divisions', 'id')->where('company_id', $this->integer('company_id'))->where('is_active', true)->whereNull('deleted_at')],
            'status_kebutuhan'           => ['required', Rule::enum(StatusKebutuhan::class)],
            'nama_karyawan_replacement'  => ['required_if:status_kebutuhan,'.StatusKebutuhan::REPLACEMENT->value, 'nullable', 'string', 'max:255'],
            'posisi_dibutuhkan'          => ['required', 'string', 'max:255'],
            'level_pekerjaan'            => ['required', 'string', Rule::in(RequestManPower::LEVEL_PEKERJAAN_OPTIONS)],
            'jumlah_karyawan_dibutuhkan' => ['required', 'integer', 'min:1'],
            'lokasi_penempatan'          => ['required', 'string', 'max:255'],
            'estimasi_tanggal_join'      => ['required', 'date'],
            'job_description'            => ['required', 'string'],
            'requirements_kualifikasi'   => ['required', 'string'],
            'keterangan'                 => ['nullable', 'string'],
        ];

        $recaptcha = config('rekrutmen.security.recaptcha', []);

        if (Arr::get($recaptcha, 'enabled', false) && filled(Arr::get($recaptcha, 'site_key')) && filled(Arr::get($recaptcha, 'secret_key'))) {
            $rules['recaptcha_token'] = ['required', 'string'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nama_karyawan_replacement.required_if' => __('rekrutmen::livewire/public-request-man-power-form.errors.nama_karyawan_replacement_required'),
            'recaptcha_token.required'              => __('rekrutmen::livewire/public-request-man-power-form.errors.recaptcha_required'),
            'company_id.exists'                     => 'Pilih badan usaha yang aktif.',
            'division_id.exists'                    => 'Pilih divisi aktif dari badan usaha yang dipilih.',
            'status_kebutuhan.enum'                 => 'Pilih status kebutuhan yang tersedia.',
        ];
    }
}
