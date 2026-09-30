<div class="pf-page">
    <div class="mx-auto max-w-2xl">
        @php
            $statusBadgeClass = match ($summary['status'] ?? 'pending') {
                'approved' => 'bg-green-50 text-green-700 ring-green-600/20',
                'rejected' => 'bg-red-50 text-red-700 ring-red-600/10',
                default => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
            };
        @endphp

        <div class="pf-header-card mb-4">
            <div class="px-6 pt-5 pb-6">
                <h1 class="pf-title-display">
                    {{ __('waste::waste.approval_title') }}
                </h1>
                <p class="mt-2 break-words text-sm text-gray-600">
                    {{ $summary['brand'] }}
                    <span class="px-1 text-gray-400" aria-hidden="true">/</span>
                    {{ $summary['outlet'] }}
                    · {{ $summary['current_step'] }}
                </p>
            </div>
            <div class="border-t border-gray-200 px-6 py-3">
                <div class="flex flex-wrap items-center gap-2 text-xs text-gray-600">
                    <span>{{ __('waste::waste.fields.status') }}</span>
                    <x-waste::ui component="Badge" :props="['label' => $summary['status_label'], 'theme' => match ($summary['status']) { 'approved' => 'green', 'rejected' => 'red', default => 'orange' }, 'size' => 'lg']">
                        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset {{ $statusBadgeClass }}">{{ $summary['status_label'] }}</span>
                    </x-waste::ui>
                    <span aria-hidden="true" class="text-gray-300">|</span>
                    <span class="break-all font-mono text-gray-400">{{ $summary['uid'] }}</span>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <section class="rounded-lg border border-gray-200 bg-white px-6 py-5 shadow-sm" aria-label="{{ __('waste::waste.approval_timeline') }}">
                <h2 class="text-sm font-medium text-gray-900">{{ __('waste::waste.approval_timeline') }}</h2>
                <ol class="mt-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    @foreach ($approvals as $step)
                        <li @class([
                            'flex min-w-0 items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm sm:min-w-40',
                            'border-blue-300 bg-blue-50' => $step['status'] === 'pending',
                            'border-gray-200' => $step['status'] !== 'pending',
                        ])>
                            <span class="font-medium text-gray-900">{{ $step['label'] }}</span>
                            <span class="text-gray-600">{{ $step['status_label'] }}</span>
                        </li>
                    @endforeach
                </ol>
            </section>

            <section x-data="{ expanded: true }" class="overflow-hidden pf-card">
                <button
                    type="button"
                    @click="expanded = ! expanded"
                    :aria-expanded="expanded.toString()"
                    class="cesa-primary-bg cesa-primary-bg-hover flex w-full cursor-pointer items-center justify-between px-6 py-4 text-left text-white transition-colors"
                >
                    <h2 class="text-lg font-medium">{{ __('waste::waste.event_summary') }}</h2>
                    <x-filament::icon
                        icon="heroicon-m-chevron-down"
                        class="h-5 w-5 shrink-0 transition-transform duration-200"
                        ::class="{ 'rotate-180': expanded }"
                    />
                </button>
                <div x-show="expanded" x-collapse class="space-y-4 border-t border-blue-100 px-6 py-5">
                    @foreach ($events as $event)
                        <article wire:key="approval-event-{{ $event['sequence'] }}" class="rounded-lg border border-gray-200 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <h3 class="font-medium text-gray-900">{{ __('waste::waste.event_title', ['number' => $event['sequence']]) }}</h3>
                                <span class="break-words text-sm text-gray-600">{{ $event['category'] }}</span>
                            </div>
                            <ul class="mt-3 divide-y divide-gray-200">
                                @foreach ($event['lines'] as $line)
                                    <li wire:key="approval-line-{{ $event['sequence'] }}-{{ $loop->index }}" class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 py-2 text-sm">
                                        <span class="min-w-0 break-words text-gray-900">
                                            {{ $line['item'] }}
                                            <span class="text-gray-500">({{ $line['code'] }})</span>
                                            @if (filled($line['reason']))
                                                <span class="mt-1 block text-gray-600">{{ $line['reason'] }}</span>
                                            @endif
                                        </span>
                                        <span class="font-medium text-gray-900">{{ $line['quantity'] }} {{ $line['unit'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($event['evidence'])
                                <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-5">
                                    @foreach ($event['evidence'] as $photo)
                                        <a wire:key="approval-evidence-{{ $event['sequence'] }}-{{ $loop->index }}" href="{{ $photo }}" target="_blank" rel="noopener" class="rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                                            <img src="{{ $photo }}" alt="{{ __('waste::waste.camera_title') }}" loading="lazy" class="aspect-square w-full rounded-lg object-cover ring-1 ring-gray-200">
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>

            @if (! $actionTaken && $summary['status'] === 'pending')
                <section class="rounded-lg border border-gray-200 bg-white px-6 py-5 shadow-sm">
                    <label for="waste-rejection-reason" class="block text-sm font-medium text-gray-900">
                        {{ __('waste::waste.rejection_reason') }}
                    </label>
                    <x-waste::ui
                        component="Textarea"
                        model="rejectionReason"
                        :props="['id' => 'waste-rejection-reason', 'rows' => 3, 'variant' => 'subtle', 'size' => 'lg', 'aria-invalid' => $errors->has('rejectionReason') ? 'true' : 'false', 'aria-describedby' => $errors->has('rejectionReason') ? 'waste-rejection-reason-error' : null]"
                        class="mt-2 block"
                    >
                        <textarea
                            id="waste-rejection-reason"
                            wire:model="rejectionReason"
                            rows="3"
                            aria-invalid="{{ $errors->has('rejectionReason') ? 'true' : 'false' }}"
                            @error('rejectionReason') aria-describedby="waste-rejection-reason-error" @enderror
                            class="cesa-primary-focus block w-full rounded-lg border border-gray-200 bg-white p-3 text-sm text-gray-900 shadow-sm"
                        ></textarea>
                    </x-waste::ui>
                    @error('rejectionReason')
                        <p id="waste-rejection-reason-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    <div class="mt-4 flex flex-col gap-3 sm:flex-row">
                        <x-waste::ui
                            component="Button"
                            :props="['label' => __('waste::waste.approve'), 'theme' => 'blue', 'variant' => 'solid', 'size' => 'lg']"
                            wire:click="approve"
                            wire:confirm="{{ __('waste::waste.confirm_approve') }}"
                            wire:loading.attr="data-disabled"
                            wire:target="approve,reject"
                            class="inline-flex"
                        >
                            <button type="button" class="cesa-primary-bg cesa-primary-bg-hover cesa-primary-focus rounded-lg px-5 py-2.5 text-sm font-medium text-white">{{ __('waste::waste.approve') }}</button>
                        </x-waste::ui>
                        <x-waste::ui
                            component="Button"
                            :props="['label' => __('waste::waste.reject'), 'theme' => 'red', 'variant' => 'outline', 'size' => 'lg']"
                            wire:click="reject"
                            wire:confirm="{{ __('waste::waste.confirm_reject') }}"
                            wire:loading.attr="data-disabled"
                            wire:target="approve,reject"
                            class="inline-flex"
                        >
                            <button type="button" class="rounded-lg border border-red-300 bg-white px-5 py-2.5 text-sm font-medium text-red-700 hover:bg-red-50">{{ __('waste::waste.reject') }}</button>
                        </x-waste::ui>
                    </div>
                </section>
            @else
                @php
                    $closedMessage = match ($summary['status'] ?? null) {
                        'approved' => __('waste::waste.approval_closed_approved'),
                        'rejected' => __('waste::waste.approval_closed_rejected'),
                        default => __('waste::waste.approval_closed_step'),
                    };
                    $closedClass = match ($summary['status'] ?? null) {
                        'rejected' => 'border-red-200 bg-red-50 text-red-800',
                        default => 'border-green-200 bg-green-50 text-green-800',
                    };
                @endphp
                <div role="status" class="rounded-lg border px-6 py-5 text-sm font-medium shadow-sm {{ $closedClass }}">
                    {{ $closedMessage }}
                </div>
            @endif
        </div>
    </div>
</div>
