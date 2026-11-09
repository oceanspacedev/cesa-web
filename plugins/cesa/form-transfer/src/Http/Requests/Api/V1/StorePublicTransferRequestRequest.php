<?php

namespace Cesa\FormTransfer\Http\Requests\Api\V1;

use Cesa\FormTransfer\Models\FormTransfer;
use Cesa\FormTransfer\Models\TransferReferenceNote;
use Cesa\FormTransfer\Models\TransferRequest;
use Cesa\FormTransfer\Services\RecaptchaValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePublicTransferRequestRequest extends FormRequest
{
    protected ?FormTransfer $resolvedFormTransfer = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email'              => Str::lower(trim((string) $this->input('email'))),
            'account_number'     => trim((string) $this->input('account_number')),
            'requester_whatsapp' => TransferRequest::normalizeWhatsappNumber($this->input('requester_whatsapp')),
        ]);
    }

    public function rules(): array
    {
        $invoiceFiles = $this->file('invoice_path');
        $accountAttachmentFiles = $this->file('account_attachment_path');

        $invoiceRule = is_array($invoiceFiles)
            ? ['nullable', 'array']
            : ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,xls,xlsx', 'max:5120'];

        $accountAttachmentRule = is_array($accountAttachmentFiles)
            ? ['nullable', 'array']
            : ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];

        return [
            'requester_name'           => ['required', 'string', 'max:191'],
            'division_id'              => [
                $this->requiresDivision() ? 'required' : 'nullable',
                'integer',
                Rule::exists('form_transfer_divisions', 'id')
                    ->where('form_transfer_id', $this->formTransfer()->getKey())
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'email'                    => ['required', 'email', 'max:191'],
            'requester_whatsapp'       => ['nullable', 'string', 'max:32', 'regex:'.TransferRequest::WHATSAPP_NUMBER_PATTERN],
            'bank_id'                  => [
                'required',
                'integer',
                Rule::exists('form_transfer_banks', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'account_number'           => ['required', 'string', 'max:191'],
            'account_name'             => ['required', 'string', 'max:191'],
            'transfer_amount'          => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'purpose'                  => ['required', 'string', 'max:1000'],
            'reference_note_id'        => [
                'nullable',
                'integer',
                Rule::exists('form_transfer_reference_notes', 'id')
                    ->where('form_transfer_id', $this->formTransfer()->getKey())
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'reference_note'           => ['nullable', 'string', 'max:1000'],
            'invoice_path'             => $invoiceRule,
            'invoice_path.*'           => is_array($invoiceFiles)
                ? ['file', 'mimes:pdf,jpg,jpeg,png,xls,xlsx', 'max:5120']
                : [],
            'account_attachment_path'   => $accountAttachmentRule,
            'account_attachment_path.*' => is_array($accountAttachmentFiles)
                ? ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']
                : [],
            'recaptcha_token'          => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $referenceNote = trim((string) $this->input('reference_note'));

            if ($referenceNote === '' && blank($this->input('reference_note_id'))) {
                $validator->errors()->add('reference_note', 'Referensi atau catatan wajib diisi.');

                return;
            }

            $availableReferenceNotes = $this->activeReferenceNoteLabels();

            if ($referenceNote !== '' && $availableReferenceNotes !== [] && ! in_array($referenceNote, $availableReferenceNotes, true)) {
                $validator->errors()->add('reference_note', 'Referensi yang dipilih tidak tersedia untuk form ini.');

                return;
            }

            $result = app(RecaptchaValidator::class)->verify(
                (string) $this->input('recaptcha_token', ''),
                (string) config('form-transfer.security.recaptcha.action', 'form_transfer_request'),
                $this->ip() ?: '0.0.0.0',
            );

            if (! ($result['success'] ?? false)) {
                $validator->errors()->add(
                    'recaptcha_token',
                    (string) ($result['message'] ?? 'Verifikasi keamanan gagal. Silakan coba lagi.')
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'requester_name.required'         => __('form-transfer::filament/resources/transfer-request/validation.requester_name_required'),
            'division_id.required'            => __('form-transfer::filament/resources/transfer-request/validation.division_invalid'),
            'division_id.exists'              => __('form-transfer::filament/resources/transfer-request/validation.division_invalid'),
            'email.required'                  => __('form-transfer::filament/resources/transfer-request/validation.requester_email_required'),
            'email.email'                     => 'Email harus menggunakan format yang valid.',
            'requester_whatsapp.regex'        => __('form-transfer::filament/resources/transfer-request/validation.requester_whatsapp_invalid'),
            'bank_id.required'                => __('form-transfer::filament/resources/transfer-request/validation.bank_invalid'),
            'bank_id.exists'                  => __('form-transfer::filament/resources/transfer-request/validation.bank_invalid'),
            'account_number.required'         => __('form-transfer::filament/resources/transfer-request/validation.account_number_required'),
            'account_name.required'           => __('form-transfer::filament/resources/transfer-request/validation.account_name_required'),
            'transfer_amount.required'        => __('form-transfer::filament/resources/transfer-request/validation.transfer_amount_required'),
            'transfer_amount.numeric'         => __('form-transfer::filament/resources/transfer-request/validation.transfer_amount_numeric'),
            'transfer_amount.min'             => __('form-transfer::filament/resources/transfer-request/validation.transfer_amount_min'),
            'transfer_amount.max'             => __('form-transfer::filament/resources/transfer-request/validation.transfer_amount_max'),
            'purpose.required'                => 'Keperluan transfer wajib diisi.',
            'reference_note_id.exists'        => __('form-transfer::filament/resources/transfer-request/validation.reference_note_invalid'),
            'invoice_path.file'               => __('form-transfer::filament/resources/transfer-request/validation.invoice_file'),
            'invoice_path.mimes'              => __('form-transfer::filament/resources/transfer-request/validation.invoice_mimes'),
            'invoice_path.max'                => __('form-transfer::filament/resources/transfer-request/validation.invoice_max_size'),
            'invoice_path.*.file'             => __('form-transfer::filament/resources/transfer-request/validation.invoice_file'),
            'invoice_path.*.mimes'            => __('form-transfer::filament/resources/transfer-request/validation.invoice_mimes'),
            'invoice_path.*.max'              => __('form-transfer::filament/resources/transfer-request/validation.invoice_max_size'),
            'account_attachment_path.file'    => __('form-transfer::filament/resources/transfer-request/validation.account_attachment_file'),
            'account_attachment_path.mimes'   => __('form-transfer::filament/resources/transfer-request/validation.account_attachment_mimes'),
            'account_attachment_path.max'     => __('form-transfer::filament/resources/transfer-request/validation.account_attachment_max_size'),
            'account_attachment_path.*.file'  => __('form-transfer::filament/resources/transfer-request/validation.account_attachment_file'),
            'account_attachment_path.*.mimes' => __('form-transfer::filament/resources/transfer-request/validation.account_attachment_mimes'),
            'account_attachment_path.*.max'   => __('form-transfer::filament/resources/transfer-request/validation.account_attachment_max_size'),
        ];
    }

    public function formTransfer(): FormTransfer
    {
        if ($this->resolvedFormTransfer instanceof FormTransfer) {
            return $this->resolvedFormTransfer;
        }

        $identifier = (string) $this->route('formTransfer');

        $this->resolvedFormTransfer = FormTransfer::query()
            ->internalEntry()
            ->where('is_active', true)
            ->where(function ($query) use ($identifier): void {
                $query->where('code', $identifier);

                if (ctype_digit($identifier)) {
                    $query->orWhereKey((int) $identifier);
                }
            })
            ->firstOrFail();

        return $this->resolvedFormTransfer;
    }

    protected function requiresDivision(): bool
    {
        return $this->formTransfer()
            ->divisions()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    protected function activeReferenceNoteLabels(): array
    {
        return TransferReferenceNote::query()
            ->where('form_transfer_id', $this->formTransfer()->getKey())
            ->where('is_active', true)
            ->orderBy('label')
            ->pluck('label')
            ->all();
    }
}
