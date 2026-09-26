@component('waste::layouts.form', ['title' => __('waste::waste.entry.title')])
    <main
        class="pf-page"
        x-data="{
            search: '',
            brands: @js($brands),
            matches(brand, outlet = '') {
                return `${brand} ${outlet}`.toLocaleLowerCase().includes(this.search.trim().toLocaleLowerCase());
            },
            matchesBrand(brand) {
                return this.matches(brand.name) || brand.outlets.some(outlet => this.matches(brand.name, `${outlet.name} ${outlet.code}`));
            },
            hasMatches() {
                return this.brands.some(brand => this.matchesBrand(brand));
            }
        }"
    >
        <div class="mx-auto max-w-4xl">
            <div class="pf-header-card mb-6">
                <div class="px-6 pb-6 pt-5">
                    <h1 class="pf-title-display">{{ __('waste::waste.public_title') }}</h1>
                    <p class="pf-hint mt-2">{{ __('waste::waste.entry.hint') }}</p>

                    @if ($showSearch)
                        <div class="mt-5 max-w-md">
                            <label for="outlet-search" class="sr-only">{{ __('waste::waste.entry.search') }}</label>
                            <input
                                id="outlet-search"
                                type="search"
                                x-model="search"
                                placeholder="{{ __('waste::waste.entry.search_placeholder') }}"
                                autocomplete="off"
                                class="cesa-primary-focus block w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-900 shadow-sm"
                            />
                        </div>
                    @endif
                </div>
            </div>

            @if ($brands !== [])
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($brands as $brand)
                        @forelse ($brand['outlets'] as $outlet)
                            <a
                                href="{{ $outlet['url'] }}"
                                x-show="matches(@js($brand['name']), @js($outlet['name'].' '.$outlet['code']))"
                                class="group pf-card p-6 transition hover:border-primary-600 hover:shadow-md"
                            >
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">{{ $brand['name'] }}</p>
                                        <h2 class="mt-2 text-lg font-semibold text-gray-900 group-hover:text-primary-600">{{ $outlet['name'] }}</h2>
                                    </div>
                                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-5 w-5 text-gray-400 group-hover:text-primary-600" />
                                </div>
                            </a>
                        @empty
                            <div class="pf-card p-6" x-show="matches(@js($brand['name']))">
                                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">{{ $brand['name'] }}</p>
                                <p class="pf-hint mt-2">{{ __('waste::waste.entry.no_outlets') }}</p>
                            </div>
                        @endforelse
                    @endforeach
                </div>

                <p x-show="!hasMatches()" x-cloak role="status" class="pf-card p-6 text-sm text-gray-600">{{ __('waste::waste.entry.no_results') }}</p>
            @else
                <div class="pf-card p-6">
                    <p class="text-sm text-gray-600">{{ __('waste::waste.entry.empty') }}</p>
                </div>
            @endif

            <div class="mt-8">
                <a href="{{ Route::has('home') ? route('home') : url('/') }}" class="text-sm font-medium text-gray-600 hover:text-gray-900 hover:underline">
                    &larr; {{ __('waste::waste.entry.back') }}
                </a>
            </div>
        </div>
    </main>
@endcomponent
