<?php

namespace Cesa\Lead\Http\Requests;

use Cesa\Lead\Enums\PhoneTransactionRange;
use Cesa\Lead\Enums\StoreTeamPosition;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class SubmitPublicLeadRequest extends CheckPublicLeadWhatsAppRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            ...parent::rules(),
            'name'                    => ['required', 'string', 'max:255'],
            'address'                 => ['required', 'string'],
            'sales_person'            => ['required', 'string', 'max:255'],
            'store_team_position'     => ['required', Rule::enum(StoreTeamPosition::class)],
            'store_branch'            => ['required', 'string', Rule::in(config('lead.store_branches', []))],
            'phone_transaction_range' => ['nullable', Rule::enum(PhoneTransactionRange::class)],
        ];

        $recaptcha = config('lead.security.recaptcha', []);

        if (Arr::get($recaptcha, 'enabled', false) && filled(Arr::get($recaptcha, 'site_key')) && filled(Arr::get($recaptcha, 'secret_key'))) {
            $rules['recaptcha_token'] = ['required', 'string'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'name.required'                => 'Nama lengkap wajib diisi.',
            'address.required'             => 'Alamat lengkap wajib diisi.',
            'sales_person.required'        => 'Sales person wajib diisi.',
            'store_team_position.required' => 'Jabatan tim toko wajib dipilih.',
            'store_branch.required'        => 'Cabang toko wajib dipilih.',
            'phone_transaction_range.enum' => 'Pilih kisaran harga transaksi yang tersedia.',
            'recaptcha_token.required'     => __('lead::views/public-lead-form.recaptcha.required'),
        ];
    }
}
