<?php

namespace Cesa\Rekrutmen\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveWagCapacityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage_rekrutmen_whatsapp') ?? false;
    }

    public function rules(): array
    {
        return ['configured_limit' => ['required', 'integer', 'min:0']];
    }

    public function messages(): array
    {
        return ['configured_limit.required' => 'Isi batas jumlah akun.', 'configured_limit.integer' => 'Batas jumlah akun harus berupa angka bulat.'];
    }
}
