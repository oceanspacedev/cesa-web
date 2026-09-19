<?php

namespace Cesa\Rekrutmen\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class QueueAiScreeningRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'job_id'            => ['nullable', 'integer', 'exists:rekrutmen_job_postings,id'],
            'application_ids'   => ['sometimes', 'array', 'min:1', 'max:10000'],
            'application_ids.*' => ['integer', 'distinct', 'exists:rekrutmen_job_applications,id'],
            'ids'               => ['sometimes', 'array', 'max:200'],
            'ids.*'             => ['integer', 'distinct'],
            'force'             => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'job_id.exists'            => 'Lowongan tidak ditemukan.',
            'application_ids.min'      => 'Pilih setidaknya satu pelamar.',
            'application_ids.*.exists' => 'Pelamar tidak ditemukan.',
            'application_ids.max'      => 'Pilih maksimal 10.000 pelamar sekaligus.',
            'ids.max'                  => 'Maksimal 200 pelamar dapat diperbarui sekaligus.',
            'force.boolean'            => 'Pilihan analisis ulang tidak valid.',
        ];
    }
}
