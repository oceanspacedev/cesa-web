<?php

namespace Cesa\Rekrutmen\Http\Requests;

use Cesa\Rekrutmen\Models\JobApplication;
use Illuminate\Foundation\Http\FormRequest;

class UploadCandidateCvRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = JobApplication::query()->findOrFail($this->route('id'));

        return $this->user()?->can('update', $application) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['cv' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:20480']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cv.required' => 'Pilih berkas CV yang akan diunggah.',
            'cv.mimes'    => 'CV harus berupa PDF, DOC, atau DOCX.',
            'cv.max'      => 'Ukuran CV maksimal 20 MB.',
        ];
    }
}
