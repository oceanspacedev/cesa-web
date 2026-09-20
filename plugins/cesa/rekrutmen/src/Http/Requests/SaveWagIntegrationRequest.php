<?php

namespace Cesa\Rekrutmen\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveWagIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage_rekrutmen_whatsapp') ?? false;
    }

    public function rules(): array
    {
        return ['url' => ['required', 'url:http,https', 'max:255', 'not_regex:/[?#@]/'], 'token' => ['nullable', 'string', 'max:4096']];
    }

    public function messages(): array
    {
        return ['url.required' => 'Isi alamat WAG Hub.', 'url.url' => 'Gunakan alamat HTTP atau HTTPS yang valid.', 'url.not_regex' => 'Alamat hub tidak boleh memuat kredensial atau query.'];
    }
}
