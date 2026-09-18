<?php

namespace Cesa\Lead\Http\Requests;

use Cesa\Lead\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Webkul\PluginManager\Package;

class CheckPublicLeadWhatsAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(Package::isPluginInstalled('lead'), 404);

        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['bail', 'required', 'string', 'max:15', 'regex:/^62[0-9]{8,}$/', 'unique:leads,phone'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.required' => __('lead::filament/resources/lead.validation.phone_required'),
            'phone.regex'    => __('lead::filament/resources/lead.validation.phone_format'),
            'phone.unique'   => __('lead::filament/resources/lead.validation.phone_unique'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => Lead::normalizePhone($this->input('phone'))]);
        }
    }
}
