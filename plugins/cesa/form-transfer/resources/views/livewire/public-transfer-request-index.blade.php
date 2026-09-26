<div class="pf-page {{ $affiliateMode ? 'pf-page--affiliate' : '' }}">
    <div class="mx-auto max-w-4xl">
        <div class="pf-header-card mb-6 {{ $affiliateMode ? 'pf-header-card--affiliate' : '' }}">
            <div class="px-6 pb-6 pt-5">
                <h1 class="pf-title-display">
                    {{ $heading }}
                </h1>
                <p class="pf-hint mt-2">
                    {{ $description }}
                </p>
            </div>
        </div>

        @if ($formTransfers->isNotEmpty())
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($formTransfers as $formTransfer)
                    <a
                        href="{{ $formTransfer->public_destination_url }}"
                        @if ($formTransfer->usesExternalPublicEntry()) target="_blank" rel="noopener noreferrer" @endif
                        class="group pf-card p-6 transition {{ $affiliateMode ? 'hover:border-[#673AB7]' : 'hover:border-primary-600' }} hover:shadow-md"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">
                                    {{ $formTransfer->public_badge_label ?: ($formTransfer->usesExternalPublicEntry() ? 'Google Form' : 'Form Transfer') }}
                                </p>
                                <h2 class="mt-2 text-lg font-semibold text-gray-900 {{ $affiliateMode ? 'group-hover:text-[#673AB7]' : 'group-hover:text-primary-600' }}">
                                    {{ $formTransfer->name }}
                                </h2>
                                <p class="pf-hint mt-2">
                                    {{ filled($formTransfer->description) ? $formTransfer->description : $defaultDescription }}
                                </p>
                            </div>
                            <x-filament::icon
                                icon="heroicon-m-arrow-right"
                                class="h-5 w-5 text-gray-400 {{ $affiliateMode ? 'group-hover:text-[#673AB7]' : 'group-hover:text-primary-600' }}"
                            />
                        </div>
                    </a>
                @endforeach
            </div>
        @else
            <div class="pf-card p-6">
                <p class="text-sm text-gray-600">
                    {{ $emptyState }}
                </p>
            </div>
        @endif
    </div>
</div>
