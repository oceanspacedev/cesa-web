@props([
    'label',
    'options' => [],
    'model',
    'value' => '',
    'required' => false,
    'error' => null,
    'placeholder' => '',
])

@php
    $choices = collect($options)
        ->map(fn (mixed $optionLabel, mixed $id): array => ['id' => $id, 'label' => (string) $optionLabel])
        ->values();
    $searchable = $choices->count() > 5;
@endphp

<x-waste::public-field
    :label="$label"
    :required="$required"
    :error="$error"
    :wrapper="$searchable ? 'fi-fo-select' : 'fi-fo-select fi-fo-select-native'"
>
    @if ($searchable)
        <div
            class="relative w-full min-w-0"
            data-waste-search-select
            x-data="wasteSearchSelect(@js($choices), @js($value), @js($placeholder), @js($model))"
            x-on:click.outside="open = false"
            x-on:keydown.escape.window="open = false"
        >
            <button type="button" class="fi-select-input w-full overflow-hidden text-start" style="height: 36px; padding: 6px 32px 6px 12px; font-size: 14px; line-height: 24px; color: var(--gray-950); background: transparent; border: 0; text-align: start" x-on:click="toggle()" x-bind:aria-expanded="open">
                <span class="block truncate" style="color: var(--gray-950)" x-text="selectedLabel || placeholder"></span>
            </button>
            <svg class="pointer-events-none absolute z-10 h-5 w-5 text-gray-400" style="right: 0.75rem; top: 0.5rem" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
            </svg>
            <template x-if="open">
                <div x-ref="panel" class="absolute inset-x-0 z-30 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg" style="top: calc(100% + 4px)">
                    <div class="border-b border-gray-200 px-3" style="background: #f9fafb">
                        <input type="search" x-ref="search" x-model="query" class="w-full bg-transparent text-sm text-gray-950" style="height: 40px; border: 0; outline: none; box-shadow: none" placeholder="{{ __('waste::waste.select_search') }}" autocomplete="off">
                    </div>
                    <ul class="overflow-y-auto py-1" style="max-height: 16rem">
                        <template x-for="option in filtered" :key="option.id">
                            <li>
                                <button type="button" class="block w-full px-3 py-2 text-start text-sm text-gray-950 hover:bg-gray-50" style="white-space: normal; line-height: 1.4" x-bind:class="String(option.id) === value ? 'bg-gray-50' : ''" x-on:click="choose(option)" x-text="option.label"></button>
                            </li>
                        </template>
                        <li x-show="filtered.length === 0" class="px-3 py-2 text-sm text-gray-500">{{ __('waste::waste.select_empty') }}</li>
                        <li x-show="matches.length > filtered.length" class="px-3 py-2 text-sm text-gray-500">{{ __('waste::waste.select_more') }}</li>
                    </ul>
                </div>
            </template>
            <input type="hidden" wire:model="{{ $model }}" @if ($required) required @endif>
        </div>
    @else
        <select wire:model="{{ $model }}" class="fi-select-input" x-on:change="$dispatch('waste-item-selected', { model: @js($model), id: $event.target.value })" @if ($required) required @endif>
            <option value="">{{ $placeholder }}</option>
            @foreach ($options as $id => $optionLabel)
                <option value="{{ $id }}">{{ $optionLabel }}</option>
            @endforeach
        </select>
    @endif
</x-waste::public-field>
