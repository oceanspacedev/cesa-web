@if (strtoupper((string) ($brandData['code'] ?? '')) !== 'MOMOYO')
    <x-waste::public-field :label="__('waste::waste.fields.reason')" :required="true" :error="$errors->first('data.events.'.$eventIndex.'.reason')">
        <input type="text" wire:model="data.events.{{ $eventIndex }}.reason" class="fi-input" placeholder="{{ __('waste::waste.placeholders.reason') }}" required>
    </x-waste::public-field>
@endif

@if ($sections !== [])
    <div class="grid gap-6 sm:grid-cols-2">
        <x-waste::public-select
            :label="__('waste::waste.fields.section')"
            :options="array_combine($sections, $sections) ?: []"
            :model="'data.events.'.$eventIndex.'.section'"
            :value="$event['section'] ?? ''"
            :placeholder="__('waste::waste.placeholders.section')"
            :error="$errors->first('data.events.'.$eventIndex.'.section')"
        />

        <x-waste::public-select
            :label="__('waste::waste.fields.category')"
            :options="$categories"
            :model="'data.events.'.$eventIndex.'.category_id'"
            :value="$event['category_id'] ?? ''"
            :required="true"
            :placeholder="__('waste::waste.choose')"
            :error="$errors->first('data.events.'.$eventIndex.'.category_id')"
        />
    </div>
@else
    <x-waste::public-select
        :label="__('waste::waste.fields.category')"
        :options="$categories"
        :model="'data.events.'.$eventIndex.'.category_id'"
        :value="$event['category_id'] ?? ''"
        :required="true"
        :placeholder="__('waste::waste.choose')"
        :error="$errors->first('data.events.'.$eventIndex.'.category_id')"
    />
@endif

@if ($referenceItems !== [])
    @php $pipItemModel = 'data.events.'.$eventIndex.'.pip_item_id'; @endphp
    <div class="grid gap-6 sm:grid-cols-2" x-data="wasteItemUnits(@js($this->itemUnits), @js([$pipItemModel => (string) ($event['pip_item_id'] ?? '')]))" x-on:waste-item-selected="if ($event.detail.model === @js($pipItemModel)) remember($event.detail.model, $event.detail.id)">
        <x-waste::public-select
            :label="__('waste::waste.fields.pip_item')"
            :options="$referenceItems"
            :model="'data.events.'.$eventIndex.'.pip_item_id'"
            :value="$event['pip_item_id'] ?? ''"
            :placeholder="__('waste::waste.no_pip')"
            :error="$errors->first('data.events.'.$eventIndex.'.pip_item_id')"
        />

        <x-waste::public-field :label="__('waste::waste.fields.pip_quantity')" :required="filled($event['pip_item_id'] ?? null)" :error="$errors->first('data.events.'.$eventIndex.'.pip_quantity')">
            <div class="flex w-full items-center">
                <input type="text" inputmode="decimal" wire:model="data.events.{{ $eventIndex }}.pip_quantity" class="fi-input min-w-0 flex-1" placeholder="{{ __('waste::waste.placeholders.pip_quantity') }}" @if (filled($event['pip_item_id'] ?? null)) required @endif>
                <span class="shrink-0 pe-2 text-xs font-medium text-gray-500" data-waste-quantity-unit="{{ $this->itemUnits[$event['pip_item_id']] ?? '' }}" x-text="unitFor(@js($pipItemModel))" x-bind:aria-label="unitFor(@js($pipItemModel))">{{ $this->itemUnits[$event['pip_item_id']] ?? '' }}</span>
            </div>
        </x-waste::public-field>
    </div>
    <p class="text-xs text-gray-600">{{ __('waste::waste.pip_quantity_hint') }}</p>
@endif
