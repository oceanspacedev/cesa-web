<div class="pf-page">
    <div class="mx-auto max-w-xl">
        @php
            $recaptchaEnabled = $this->isRecaptchaEnabled();
            $recaptchaSiteKey = $recaptchaEnabled ? $this->getRecaptchaSiteKey() : null;
            $recaptchaAction = $recaptchaEnabled ? $this->getRecaptchaAction() : null;
        @endphp

        @if ($recentSubmission)
            <div class="pf-card mb-4 p-6">
                <h2 class="mb-2 text-xl font-medium text-gray-900">{{ __('rekrutmen::livewire/public-request-man-power-form.summary.title') }}</h2>
                <p class="text-sm text-gray-600">
                    {{ __('rekrutmen::livewire/public-request-man-power-form.summary.description') }}
                </p>

                <dl class="mt-4 grid gap-3 text-sm text-gray-600">
                    <div class="flex justify-between gap-4">
                        <dt class="font-medium text-gray-700">{{ __('rekrutmen::livewire/public-request-man-power-form.summary.fields.posisi_dibutuhkan') }}</dt>
                        <dd class="text-right">{{ $recentSubmission['posisi_dibutuhkan'] ?? __('rekrutmen::livewire/public-request-man-power-form.common.not_available') }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="font-medium text-gray-700">{{ __('rekrutmen::livewire/public-request-man-power-form.summary.fields.nama_pengaju') }}</dt>
                        <dd class="text-right">{{ $recentSubmission['nama_pengaju'] ?? __('rekrutmen::livewire/public-request-man-power-form.common.not_available') }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="font-medium text-gray-700">{{ __('rekrutmen::livewire/public-request-man-power-form.summary.fields.status_kebutuhan') }}</dt>
                        <dd class="text-right">{{ $recentSubmission['status_kebutuhan'] ?? __('rekrutmen::livewire/public-request-man-power-form.common.not_available') }}</dd>
                    </div>
                    @if (filled($recentSubmission['nama_replacement'] ?? null))
                        <div class="flex justify-between gap-4">
                            <dt class="font-medium text-gray-700">{{ __('rekrutmen::livewire/public-request-man-power-form.summary.fields.nama_replacement') }}</dt>
                            <dd class="text-right">{{ $recentSubmission['nama_replacement'] }}</dd>
                        </div>
                    @endif
                </dl>

                <div class="mt-4">
                    <a href="{{ route('rekrutmen.public.request-man-power.form') }}" class="text-sm font-medium text-blue-600 hover:text-blue-500 hover:underline">
                        {{ __('rekrutmen::livewire/public-request-man-power-form.summary.actions.submit_another') }}
                    </a>
                </div>
            </div>
        @else
            @php
                $fieldErrorMessages = collect($errors->getMessages())
                    ->except(['data', 'data.recaptcha_token'])
                    ->flatten()
                    ->unique()
                    ->values();
            @endphp

            <form
                id="form"
                x-data="manRequestRecaptchaForm({
                    enabled: {{ $recaptchaEnabled ? 'true' : 'false' }},
                    siteKey: @js($recaptchaSiteKey),
                    action: @js($recaptchaAction),
                })"
                x-on:submit.prevent="handleSubmit"
                x-on:form-processing-started="isProcessing = true"
                x-on:form-processing-finished="isProcessing = false"
                x-on:form-errors-presented.window="handleErrorsPresented"
            >
                <div class="pf-header-card mb-4">
                    <div class="px-6 pt-6 pb-5">
                        <h1 class="pf-title">{{ __('rekrutmen::livewire/public-request-man-power-form.header.title') }}</h1>
                        <p class="pf-hint mt-3">{{ __('rekrutmen::livewire/public-request-man-power-form.header.description') }}</p>
                    </div>
                    <div class="border-t border-gray-200 px-6 py-3">
                        <p class="pf-required-note">{{ __('rekrutmen::livewire/public-request-man-power-form.header.required') }}</p>
                    </div>
                </div>

                @include('rekrutmen::livewire.partials._error-summary', [
                    'validationTitle' => __('rekrutmen::livewire/public-request-man-power-form.notifications.validation.title'),
                    'validationBody'  => __('rekrutmen::livewire/public-request-man-power-form.notifications.validation.body'),
                ])

                <div class="space-y-4">
                    <div class="pf-card p-6">
                        <div class="pf-section-bar -mx-6 -mt-6 mb-6 px-6 py-3">
                        <h2 class="text-lg font-medium">{{ __('Data permintaan') }}</h2>
                    </div>
                    {{ $this->form }}
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between gap-3 px-1">
                    <p class="text-xs text-gray-600">{{ __('rekrutmen::livewire/public-request-man-power-form.pagination.single_page', ['current' => 1, 'total' => 1]) }}</p>

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
                    Alpine.data('manRequestRecaptchaForm', ({ enabled, siteKey, action }) => ({
                        enabled,
                        siteKey,
                        action,
                        isProcessing: false,
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
