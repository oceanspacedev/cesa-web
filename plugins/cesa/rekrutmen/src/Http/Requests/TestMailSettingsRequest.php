<?php

namespace Cesa\Rekrutmen\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestMailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'recipient'        => ['required', 'email', 'max:255'],
            'enabled'          => ['nullable', 'boolean'],
            'transport'        => ['nullable', 'string', Rule::in(['smtp', 'log', 'sendmail'])],
            'host'             => ['nullable', 'string', 'max:255'],
            'port'             => ['nullable', 'integer', 'min:1', 'max:65535'],
            'encryption'       => ['nullable', 'string', Rule::in(['tls', 'ssl', 'none'])],
            'username'         => ['nullable', 'string', 'max:255'],
            'password'         => ['nullable', 'string', 'max:255'],
            'timeout'          => ['nullable', 'integer', 'min:1', 'max:120'],
            'from_address'     => ['nullable', 'email', 'max:255'],
            'from_name'        => ['nullable', 'string', 'max:255'],
            'reply_to_address' => ['nullable', 'email', 'max:255'],
            'reply_to_name'    => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipient.required' => 'Isi alamat email tujuan tes.',
            'recipient.email'    => 'Alamat email tujuan tes tidak valid.',
        ];
    }
}
