@props([
    'label',
    'required' => false,
    'error' => null,
    'wrapper' => 'fi-fo-text-input',
])

<div class="fi-fo-field min-w-0">
    <div class="fi-fo-field-label-col">
        <div class="fi-fo-field-label-ctn">
            <label class="fi-fo-field-label">
                <span class="fi-fo-field-label-content">
                    {{ $label }}@if ($required)<sup class="fi-fo-field-label-required-mark">*</sup>@endif
                </span>
            </label>
        </div>
    </div>

    <div class="fi-fo-field-content-col">
        <div class="fi-input-wrp {{ $wrapper }}">
            <div class="fi-input-wrp-content-ctn">
                {{ $slot }}
            </div>
        </div>

        @if (filled($error))
            <p class="fi-fo-field-wrp-error-message text-sm text-danger-600">{{ $error }}</p>
        @endif
    </div>
</div>
