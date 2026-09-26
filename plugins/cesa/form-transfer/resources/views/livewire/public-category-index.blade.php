<div class="pf-page">
    <div class="mx-auto max-w-4xl">
        <div class="pf-header-card mb-6">
            <div class="px-6 pb-6 pt-5">
                <h1 class="pf-title-display">
                    {{ $heading }}
                </h1>
                <p class="pf-hint mt-2">
                    {{ $description }}
                </p>
            </div>
        </div>

        @if ($categories->isNotEmpty())
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($categories as $category)
                    <a
                        href="{{ route('form-transfer.public.dynamic-index', ['publicIndexSlug' => $category->slug]) }}"
                        class="group pf-card p-6 transition hover:border-primary-600 hover:shadow-md"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">
                                    {{ '/form/'.$category->slug }}
                                </p>
                                <h2 class="mt-2 text-lg font-semibold text-gray-900 group-hover:text-primary-600">
                                    {{ $category->name }}
                                </h2>
                                <p class="mt-2 pf-hint">
                                    {{ filled($category->description) ? $category->description : $defaultDescription }}
                                </p>
                            </div>
                            <x-filament::icon
                                icon="heroicon-m-arrow-right"
                                class="h-5 w-5 text-gray-400 group-hover:text-primary-600"
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
