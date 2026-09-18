<?php

namespace Cesa\Rekrutmen\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRecruitmentPipelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name'                   => ['required', 'string', 'max:255'],
            'description'            => ['nullable', 'string', 'max:1000'],
            'clone_from_pipeline_id' => ['nullable', 'integer', Rule::exists('rekrutmen_pipelines', 'id')->whereNull('deleted_at')],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required'                 => 'Nama pipeline wajib diisi.',
            'name.max'                      => 'Nama pipeline maksimal 255 karakter.',
            'description.max'               => 'Deskripsi pipeline maksimal 1000 karakter.',
            'clone_from_pipeline_id.exists' => 'Pilih pipeline aktif untuk disalin.',
        ];
    }
}
