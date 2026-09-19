<?php

namespace Cesa\Rekrutmen\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage_rekrutmen_ai') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'api_key'       => ['nullable', 'string', 'max:2048'],
            'automatic'     => ['sometimes', 'required', 'boolean'],
            'base_url'      => ['sometimes', 'required', 'url:https', 'max:2048', 'not_regex:/[?#]/'],
            'model'         => ['sometimes', 'required', 'string', 'max:255'],
            'clear_api_key' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'api_key.string'     => 'API key harus berupa teks.',
            'api_key.max'        => 'API key maksimal 2048 karakter.',
            'automatic.boolean'  => 'Status screening otomatis tidak valid.',
            'base_url.required'  => 'Endpoint API wajib diisi.',
            'base_url.url'       => 'Endpoint API harus menggunakan URL HTTPS yang valid.',
            'base_url.not_regex' => 'Endpoint API tidak boleh memuat query atau fragmen.',
            'model.required'     => 'Nama model wajib diisi.',
            'model.max'          => 'Nama model maksimal 255 karakter.',
        ];
    }
}
