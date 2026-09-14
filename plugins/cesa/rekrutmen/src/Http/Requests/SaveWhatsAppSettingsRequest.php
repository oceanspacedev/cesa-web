<?php

namespace Cesa\Rekrutmen\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveWhatsAppSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage_rekrutmen_whatsapp') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
