<?php

namespace Cesa\Rekrutmen\Http\Requests;

use Carbon\Carbon;
use Cesa\Rekrutmen\Models\ScheduledNotification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SendCandidateNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $schedules = $this->input('candidate_schedules');
        if (is_string($schedules)) {
            $this->merge(['candidate_schedules' => json_decode($schedules, true)]);
        }

        if (! $this->has('send_type')) {
            $this->merge(['send_type' => 'immediate']);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('send_type') !== 'scheduled' || $validator->errors()->isNotEmpty()) {
                return;
            }

            $replay = $this->filled('request_key') && ScheduledNotification::query()
                ->where('request_key', $this->input('request_key'))
                ->where('creator_id', $this->user()->id)->exists();

            if (! $replay && Carbon::parse($this->input('scheduled_at'))->isPast()) {
                $validator->errors()->add('scheduled_at', 'Pilih waktu pengiriman yang belum berlalu.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $bulk = $this->routeIs('rekrutmen.api.applications.bulk-send-notification');

        return [
            'request_key'           => ['nullable', 'string', 'max:200', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'application_ids'       => [$bulk ? 'required' : 'sometimes', 'array', 'min:1'],
            'application_ids.*'     => ['integer', 'distinct', 'exists:rekrutmen_job_applications,id'],
            'channels'              => ['sometimes', 'array', 'min:1'],
            'channels.*'            => ['required', 'in:email,whatsapp', 'distinct'],
            'whatsapp_account_id'   => ['nullable', 'integer', 'min:1'],
            'subject'               => ['required', 'string', 'max:255'],
            'body_message'          => ['required', 'string'],
            'send_type'             => ['required', 'in:immediate,scheduled'],
            'scheduled_at'          => ['required_if:send_type,scheduled', 'nullable', 'date'],
            'template_key'          => ['nullable', 'string', 'max:100'],
            'schedule'              => ['nullable', 'string'],
            'venue_or_method'       => ['nullable', 'string'],
            'action_url'            => ['nullable', 'string', 'max:2048'],
            'action_label'          => ['nullable', 'string'],
            'special_note'          => ['nullable', 'string'],
            'badge_text'            => ['nullable', 'string'],
            'info_box_title'        => ['nullable', 'string'],
            'candidate_schedules'   => ['nullable', 'array'],
            'candidate_schedules.*' => [function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) && ! is_array($value)) {
                    $fail('Jadwal kandidat harus berupa teks atau rincian jadwal.');
                }
            }],
            'candidate_schedules.*.schedule'        => ['nullable', 'string'],
            'candidate_schedules.*.venue_or_method' => ['nullable', 'string'],
            'candidate_schedules.*.action_url'      => ['nullable', 'string', 'max:2048'],
            'attachment'                            => ['nullable', 'file', 'max:10240'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'application_ids.required' => 'Pilih kandidat penerima notifikasi.',
            'application_ids.*.exists' => 'Kandidat penerima tidak ditemukan.',
            'channels.*.in'            => 'Kanal pengiriman harus email atau WhatsApp.',
            'subject.required'         => 'Subjek notifikasi wajib diisi.',
            'body_message.required'    => 'Isi pesan wajib diisi.',
            'scheduled_at.required_if' => 'Waktu pengiriman wajib diisi untuk notifikasi terjadwal.',
            'request_key.regex'        => 'Identitas permintaan tidak valid.',
        ];
    }
}
