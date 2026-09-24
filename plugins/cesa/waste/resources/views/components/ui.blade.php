@props(['component', 'props' => [], 'model' => null])

<span
    x-data="wasteFrappe"
    data-waste-component="{{ $component }}"
    data-waste-props="{{ json_encode($props, JSON_THROW_ON_ERROR) }}"
    @if ($model) data-waste-model="{{ $model }}" @endif
    {{ $attributes }}
>
    <span data-waste-mount wire:ignore class="contents">{{ $slot }}</span>
</span>
