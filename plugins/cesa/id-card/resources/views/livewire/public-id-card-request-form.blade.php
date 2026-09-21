<main class="min-h-screen bg-blue-50 px-4 py-8 font-sans antialiased sm:px-6 lg:px-8">
    <div class="mx-auto flex max-w-2xl flex-col gap-4">
        <header class="rounded-lg border-t-[10px] cesa-primary-border bg-white p-6 shadow-sm">
            <h1 class="text-2xl font-semibold text-gray-900">{{ __('id-card::id-card.title') }}</h1>
            <p class="mt-3 text-sm leading-relaxed text-gray-600">{{ __('id-card::id-card.public.description') }}</p>
        </header>

        @if ($submitted)
            <section role="status" class="rounded-lg border border-green-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-green-700">{{ __('id-card::id-card.public.success_title') }}</h2>
                <p class="mt-2 text-sm text-gray-600">{{ __('id-card::id-card.public.success_description') }}</p>
            </section>
        @else
            <form wire:submit="submit" class="flex flex-col gap-4">
                @error('data')
                    <div role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {{ $message }}
                    </div>
                @enderror

                <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                    <p class="mb-5 text-xs text-gray-600">{{ __('id-card::id-card.public.required') }}</p>
                    {{ $this->form }}
                </div>

                <div class="flex justify-end">
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="submit">
                        <span wire:loading.remove wire:target="submit">{{ __('id-card::id-card.actions.submit') }}</span>
                        <span wire:loading wire:target="submit">{{ __('id-card::id-card.actions.submitting') }}</span>
                    </x-filament::button>
                </div>
            </form>
        @endif

        <x-filament-actions::modals />
    </div>
</main>
