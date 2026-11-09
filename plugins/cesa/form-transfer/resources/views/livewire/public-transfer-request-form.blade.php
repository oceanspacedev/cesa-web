<div class="pf-page">
    <div class="mx-auto max-w-xl">
        @php
            $recaptchaEnabled = $this->isRecaptchaEnabled();
            $recaptchaSiteKey = $recaptchaEnabled ? $this->getRecaptchaSiteKey() : null;
            $recaptchaAction = $recaptchaEnabled ? $this->getRecaptchaAction() : null;
            $heading = $this->getHeading();
            $subheading = $this->getSubheading();
        @endphp

        @if ($recentSubmission)
            <div class="pf-card mb-4 p-6">
                <h2 class="text-xl font-medium text-gray-900 mb-2">{{ __('form-transfer::public.submission.success_title') }}</h2>
                <p class="text-sm text-gray-600">
                    {{ __('form-transfer::public.submission.success_description', ['reference_label' => __('form-transfer::public.submission.reference_id_label'), 'uid' => $recentSubmission['uid'] ?? '']) }}.
                </p>

                <dl class="mt-4 grid gap-3 text-sm text-gray-600">
                    <div class="flex justify-between">
                        <dt class="font-medium text-gray-700">
                            {{ __('form-transfer::public.submission.form_label') }}
                        </dt>
                        <dd>{{ $recentSubmission['form'] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="font-medium text-gray-700">
                            {{ __('form-transfer::public.submission.reference_id_label') }}
                        </dt>
                        <dd class="font-semibold text-gray-900">{{ $recentSubmission['uid'] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="font-medium text-gray-700">
                            {{ __('form-transfer::public.submission.status_response_label') }}
                        </dt>
                        <dd>{{ $recentSubmission['status_response_id'] ?? '—' }}</dd>
                    </div>
                </dl>
                <div class="mt-4">
                    <a
                        href="{{ route('form-transfer.public.form', $formTransferModel?->code ?? $formTransferModel?->getKey()) }}"
                        class="text-sm font-medium text-blue-600 hover:text-blue-500 hover:underline"
                    >
                        {{ __('form-transfer::public.submission.submit_another') }}
                    </a>
                </div>
            </div>
        @else
            @php
                $fieldErrorMessages = collect($errors->getMessages())
                    ->except(['data', 'data.recaptcha_token', 'rate_limit'])
                    ->flatten()
                    ->unique()
                    ->values();
            @endphp

            <form
                id="form"
                x-data="transferRecaptchaForm({
                    enabled: {{ $recaptchaEnabled ? 'true' : 'false' }},
                    siteKey: @js($recaptchaSiteKey),
                    action: @js($recaptchaAction),
                })"
                x-ref="transferForm"
                x-on:submit.prevent="handleSubmit"
                x-on:form-processing-started="isProcessing = true"
                x-on:form-processing-finished="isProcessing = false"
                x-on:form-errors-presented.window="handleErrorsPresented"
            >
                <div class="pf-header-card mb-4">
                    <div class="px-6 pt-6 pb-5">
                        <h1 class="pf-title">
                            {{ $heading }}
                        </h1>
                        <p class="pf-hint mt-3">
                            {{ $subheading }}
                        </p>
                    </div>
                    <div class="border-t border-gray-200 px-6 py-3">
                        <p class="pf-required-note">{{ __('form-transfer::public.submission.required_hint') }}</p>
                    </div>
                </div>

                @include('form-transfer::livewire.partials._error-summary', [
                    'validationTitle' => __('form-transfer::public.form.notifications.validation.title'),
                    'validationBody'  => __('form-transfer::public.form.notifications.validation.body'),
                    'extraErrorKeys'  => ['rate_limit'],
                ])

                <div class="space-y-4">
                    <div class="pf-card p-6">
                        {{ $this->form }}
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between gap-3 px-1">
                    <p class="text-xs text-gray-600">
                        {{ str_replace([':current', ':total'], [1, 1], __('form-transfer::public.submission.page_of')) }}
                    </p>

                    <x-filament::actions
                        :actions="$this->getCachedFormActions()"
                        :full-width="false"
                        x-bind:class="{ 'opacity-60 pointer-events-none': isProcessing }"
                    />
                </div>
            </form>
        @endif

        @push('scripts')
            @if ($recaptchaEnabled && $recaptchaSiteKey)
                <script src="https://www.google.com/recaptcha/api.js?render={{ $recaptchaSiteKey }}" defer></script>
            @endif
            <script>
                document.addEventListener('alpine:init', () => {
                    const requesterStorageKey = 'cesa.form-transfer.requester';
                    const requesterFields = {
                        email: 'data.email',
                        name: 'data.requester_name',
                        whatsapp: 'data.requester_whatsapp',
                    };

                    Alpine.data('transferRecaptchaForm', ({ enabled, siteKey, action }) => ({
                        enabled,
                        siteKey,
                        action,
                        isProcessing: false,
                        init() {
                            this.restoreRequester();
                        },
                        restoreRequester() {
                            let saved = null;

                            try {
                                saved = JSON.parse(window.localStorage.getItem(requesterStorageKey) || 'null');
                            } catch (error) {
                                saved = null;
                            }

                            if (! saved || typeof saved !== 'object' || Array.isArray(saved)) {
                                return;
                            }

                            Object.entries(requesterFields).forEach(([field, path]) => {
                                const value = typeof saved[field] === 'string' ? saved[field].trim() : '';
                                const current = this.$wire.get(path);

                                if (value !== '' && (current === null || current === undefined || String(current).trim() === '')) {
                                    this.$wire.$set(path, value, false);
                                }
                            });
                        },
                        rememberRequester() {
                            const requester = {};

                            Object.entries(requesterFields).forEach(([field, path]) => {
                                const value = this.$wire.get(path);

                                requester[field] = typeof value === 'string' ? value.trim() : '';
                            });

                            try {
                                window.localStorage.setItem(requesterStorageKey, JSON.stringify(requester));
                            } catch (error) {
                                // Browser storage can be unavailable in private mode or when it is full.
                            }
                        },
                        handleErrorsPresented() {
                            this.isProcessing = false;

                            this.$nextTick(() => {
                                const summary = this.$refs.errorSummary;

                                if (! summary) {
                                    return;
                                }

                                summary.focus({ preventScroll: true });
                                summary.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            });
                        },
                        handleSubmit() {
                            if (this.isProcessing) {
                                return;
                            }

                            this.rememberRequester();

                            if (! this.enabled || ! this.siteKey) {
                                this.isProcessing = true;
                                this.$wire.call('submit');

                                return;
                            }

                            if (typeof grecaptcha === 'undefined') {
                                console.warn('reCAPTCHA script not yet loaded.');
                                return;
                            }

                            this.isProcessing = true;

                            grecaptcha.ready(() => {
                                grecaptcha.execute(this.siteKey, { action: this.action || 'submit' })
                                    .then((token) => {
                                        this.$wire.set('data.recaptcha_token', token);
                                        this.$wire.call('submit');
                                    })
                                    .catch(() => {
                                        this.isProcessing = false;
                                    });
                            });
                        },
                    }));
                });
            </script>
        @endpush
    </div>
</div>
