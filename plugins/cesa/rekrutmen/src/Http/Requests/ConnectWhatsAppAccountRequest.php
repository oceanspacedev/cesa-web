<?php

namespace Cesa\Rekrutmen\Http\Requests;

use Cesa\Rekrutmen\Services\WhatsAppGateway;
use Illuminate\Foundation\Http\FormRequest;

class ConnectWhatsAppAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage_rekrutmen_whatsapp') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('phone_number')) {
            $this->merge(['phone_number' => app(WhatsAppGateway::class)->formatPhone(is_string($this->input('phone_number')) ? $this->input('phone_number') : null)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'request_key'  => ['nullable', 'string', 'max:200', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'name'         => ['nullable', 'string', 'max:100'],
            'mode'         => ['nullable', 'string', 'in:qr,pairing'],
            'phone_number' => ['nullable', 'required_if:mode,pairing', 'string', 'regex:/^[1-9][0-9]{7,14}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_number.regex'       => 'Nomor WhatsApp tidak valid.',
            'phone_number.required_if' => 'Isi nomor WhatsApp HP untuk mendapatkan kode pairing.',
        ];
    }
}
