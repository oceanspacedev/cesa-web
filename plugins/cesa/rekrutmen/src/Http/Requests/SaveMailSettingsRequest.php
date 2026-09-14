<?php

namespace Cesa\Rekrutmen\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMailSettingsRequest extends FormRequest
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
            'enabled'          => ['required', 'boolean'],
            'transport'        => ['required', 'string', Rule::in(['smtp', 'log', 'sendmail'])],
            'host'             => ['nullable', 'required_if:transport,smtp', 'string', 'max:255'],
            'port'             => ['nullable', 'required_if:transport,smtp', 'integer', 'min:1', 'max:65535'],
            'encryption'       => ['nullable', 'string', Rule::in(['tls', 'ssl', 'none'])],
            'username'         => ['nullable', 'string', 'max:255'],
            'password'         => ['nullable', 'string', 'max:255'],
            'timeout'          => ['nullable', 'integer', 'min:1', 'max:120'],
            'from_address'     => ['nullable', 'required_if:enabled,true', 'email', 'max:255'],
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
            'from_address.required_if' => 'Alamat pengirim wajib diisi jika SMTP rekrutmen diaktifkan.',
            'host.required_if'         => 'Host SMTP wajib diisi.',
            'port.required_if'         => 'Port SMTP wajib diisi.',
        ];
    }
}
