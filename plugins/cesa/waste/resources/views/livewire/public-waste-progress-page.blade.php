<div class="pf-page">
    <div class="mx-auto max-w-xl space-y-4">
        @php
            $statusBadgeClass = match ($summary['status'] ?? 'pending') {
                'approved' => 'bg-green-50 text-green-700 ring-green-600/20',
                'rejected' => 'bg-red-50 text-red-700 ring-red-600/10',
                default => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
            };
        @endphp

        <header class="pf-header-card mb-4">
            <div class="px-6 pb-5 pt-6">
                <h1 class="pf-title">{{ __('waste::waste.progress_title') }}</h1>
                <p class="pf-hint mt-3">{{ $summary['brand'] }} / {{ $summary['outlet'] }} · {{ $summary['event_date'] }} · {{ $summary['reporter_name'] }}</p>
            </div>
            <div class="border-t border-gray-200 px-6 py-3">
                <x-waste::ui component="Badge" :props="['label' => $summary['status_label'], 'theme' => match ($summary['status']) { 'approved' => 'green', 'rejected' => 'red', default => 'orange' }, 'size' => 'lg']">
                    <span class="inline-flex items-center px-3 py-1 text-xs font-medium ring-1 ring-inset {{ $statusBadgeClass }}">{{ $summary['status_label'] }}</span>
                </x-waste::ui>
            </div>
        </header>

        @if ($summary['status'] === 'rejected')
            <section class="border border-red-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-medium text-gray-950">{{ __('waste::waste.needs_revision') }}</h2>
                @if ($rejectionReason)
                    <p class="mt-2 text-sm text-gray-700">{{ __('waste::waste.review_note') }}: {{ $rejectionReason }}</p>
                @endif
                @if ($revisionUrl)
                    <a href="{{ $revisionUrl }}" class="mt-4 inline-flex items-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600">{{ __('waste::waste.edit_report') }}</a>
                @else
                    <p class="mt-3 text-sm text-gray-700">{{ __('waste::waste.revision_link_notice') }}</p>
                @endif
            </section>
        @endif

        <section class="border border-gray-200 bg-white p-6 shadow-sm">
            <div class="pf-section-bar -mx-6 -mt-6 mb-6 px-6 py-3">
                <h2 class="text-lg font-medium">{{ __('waste::waste.steps.report') }}</h2>
            </div>
            <div class="space-y-8">
                @foreach ($events as $event)
                    <div wire:key="progress-event-{{ $event['sequence'] }}" class="space-y-6">
                        @if (count($events) > 1)
                            <h3 class="text-sm font-medium text-gray-900">{{ __('waste::waste.event_title', ['number' => $event['sequence']]) }}</h3>
                        @endif
                        <x-waste::public-field :label="__('waste::waste.fields.reason')">
                            <div class="fi-input w-full px-3 py-2 text-sm text-gray-950">{{ $event['reason'] }}</div>
                        </x-waste::public-field>
                        @if (filled($event['category']))
                            <x-waste::public-field :label="__('waste::waste.fields.category')">
                                <div class="fi-input w-full px-3 py-2 text-sm text-gray-950">{{ $event['category'] }}</div>
                            </x-waste::public-field>
                        @endif
                        @if (filled($event['section']))
                            <x-waste::public-field :label="__('waste::waste.fields.section')">
                                <div class="fi-input w-full px-3 py-2 text-sm text-gray-950">{{ $event['section'] }}</div>
                            </x-waste::public-field>
                        @endif
                        @if ($event['pip'])
                            <x-waste::public-field :label="__('waste::waste.fields.pip_item')">
                                <div class="fi-input w-full px-3 py-2 text-sm text-gray-950">{{ $event['pip'] }}</div>
                            </x-waste::public-field>
                        @endif
                        @if ($event['pip_quantity'] !== null)
                            <x-waste::public-field :label="__('waste::waste.fields.pip_quantity')">
                                <div class="fi-input w-full px-3 py-2 text-sm text-gray-950">{{ $event['pip_quantity'] }} {{ $event['pip_unit'] }}</div>
                            </x-waste::public-field>
                        @endif
                        @foreach ($event['lines'] as $line)
                            <div wire:key="progress-line-{{ $event['sequence'] }}-{{ $loop->index }}" style="display: grid; grid-template-columns: minmax(0, 1fr) 8.5rem; gap: 1.5rem; align-items: start;">
                                <x-waste::public-field :label="__('waste::waste.choose_item')">
                                    <div class="fi-input w-full px-3 py-2 text-sm text-gray-950">{{ $line['item'] }}</div>
                                </x-waste::public-field>
                                <x-waste::public-field :label="__('waste::waste.fields.quantity')">
                                    <div class="fi-input w-full px-3 py-2 text-sm text-gray-950">{{ $line['quantity'] }} {{ $line['unit'] }}</div>
                                </x-waste::public-field>
                            </div>
                        @endforeach
                        @if ($event['evidence'])
                            <div>
                                <p class="mb-3 text-sm font-medium text-gray-950">{{ __('waste::waste.camera_title') }}</p>
                                <div class="waste-camera-slots">
                                    @foreach ($event['evidence'] as $photo)
                                        <a wire:key="progress-evidence-{{ $event['sequence'] }}-{{ $loop->index }}" href="{{ $photo }}" target="_blank" rel="noopener" aria-label="{{ __('waste::waste.summary_photos') }} {{ $loop->iteration }}" class="block overflow-hidden border border-gray-200 bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                                            <img src="{{ $photo }}" alt="" loading="lazy" class="aspect-square w-full object-cover">
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

    </div>
</div>
