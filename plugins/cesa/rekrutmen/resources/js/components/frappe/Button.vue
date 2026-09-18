<template>
  <button
    :type="type"
    :disabled="isDisabled"
    :class="buttonClasses"
    @click="handleClick"
    v-bind="$attrs"
  >
    <svg
      v-if="loading"
      class="animate-spin shrink-0"
      :class="{
        'h-3.5 w-3.5': size === 'sm',
        'h-4 w-4': size === 'md',
        'h-5 w-5': size === 'lg' || size === 'xl',
      }"
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
    >
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
    </svg>

    <span v-if="$slots.prefix || iconLeft" class="inline-flex items-center justify-center shrink-0">
      <slot name="prefix">
        <span v-if="typeof iconLeft === 'string'" :class="[iconLeft, iconClasses]"></span>
        <component v-else-if="iconLeft" :is="iconLeft" :class="iconClasses" />
      </slot>
    </span>

    <template v-if="loading && loadingText">
      <span class="truncate leading-none text-center inline-flex items-center justify-center">{{ loadingText }}</span>
    </template>
    <template v-else-if="isIconButton && !loading">
      <span v-if="typeof icon === 'string'" :class="[icon, iconClasses]"></span>
      <component v-else-if="icon" :is="icon" :class="iconClasses" />
      <slot name="icon" v-else-if="$slots.icon" />
      <slot v-else>{{ label }}</slot>
    </template>
    <span v-else class="truncate leading-none text-center inline-flex items-center justify-center">
      <slot>{{ label }}</slot>
    </span>

    <span v-if="!loading && ($slots.suffix || iconRight)" class="inline-flex items-center justify-center shrink-0">
      <slot name="suffix">
        <span v-if="typeof iconRight === 'string'" :class="[iconRight, iconClasses]"></span>
        <component v-else-if="iconRight" :is="iconRight" :class="iconClasses" />
      </slot>
    </span>
  </button>
</template>

<script setup>
import { computed, useSlots } from 'vue';

defineOptions({
  inheritAttrs: false,
});

const props = defineProps({
  theme: { type: String, default: 'gray' },
  size: { type: String, default: 'sm' },
  variant: { type: String, default: 'subtle' },
  label: { type: String, default: '' },
  icon: { type: [String, Object], default: null },
  iconLeft: { type: [String, Object], default: null },
  iconRight: { type: [String, Object], default: null },
  loading: { type: Boolean, default: false },
  loadingText: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  type: { type: String, default: 'button' },
  link: { type: String, default: '' },
});

const emit = defineEmits(['click']);
const slots = useSlots();

const isDisabled = computed(() => props.disabled || props.loading);
const isIconButton = computed(() => Boolean(props.icon || slots.icon || (!slots.default && !props.label && (props.iconLeft || props.iconRight))));

const handleClick = (event) => {
  if (props.link) {
    window.open(props.link, '_blank');
  }
  emit('click', event);
};

const buttonClasses = computed(() => {
  const solidClasses = {
    gray: 'text-ink-white bg-surface-gray-7 hover:bg-surface-gray-6 active:bg-surface-gray-5 shadow-2xs',
    blue: 'text-ink-white bg-surface-blue-3 hover:bg-blue-600 active:bg-blue-700 shadow-2xs',
    green: 'text-ink-white bg-surface-green-3 hover:bg-emerald-700 active:bg-emerald-800 shadow-2xs',
    red: 'text-ink-white bg-surface-red-5 hover:bg-surface-red-6 active:bg-surface-red-7 shadow-2xs',
  }[props.theme] || '';

  const subtleClasses = {
    gray: 'text-ink-gray-8 bg-surface-gray-2 hover:bg-surface-gray-3 active:bg-surface-gray-4',
    blue: 'text-ink-blue-3 bg-surface-blue-2 hover:bg-blue-200 active:bg-blue-300',
    green: 'text-green-800 bg-surface-green-2 hover:bg-green-200 active:bg-green-300',
    red: 'text-red-700 bg-surface-red-2 hover:bg-surface-red-3 active:bg-surface-red-4',
  }[props.theme] || '';

  const outlineClasses = {
    gray: 'text-ink-gray-8 bg-surface-white border border-outline-gray-2 hover:border-outline-gray-3 active:bg-surface-gray-2 shadow-2xs',
    blue: 'text-ink-blue-3 bg-surface-white border border-outline-blue-1 hover:border-blue-400 active:bg-blue-50 shadow-2xs',
    green: 'text-green-800 bg-surface-white border border-outline-green-2 hover:border-green-500 active:bg-green-50 shadow-2xs',
    red: 'text-red-700 bg-surface-white border border-outline-red-1 hover:border-outline-red-2 active:bg-red-50 shadow-2xs',
  }[props.theme] || '';

  const ghostClasses = {
    gray: 'text-ink-gray-8 bg-transparent hover:bg-surface-gray-2 active:bg-surface-gray-3',
    blue: 'text-ink-blue-3 bg-transparent hover:bg-surface-blue-2 active:bg-blue-100',
    green: 'text-green-800 bg-transparent hover:bg-surface-green-2 active:bg-green-100',
    red: 'text-red-700 bg-transparent hover:bg-surface-red-2 active:bg-red-100',
  }[props.theme] || '';

  const variantClass = {
    solid: solidClasses,
    subtle: subtleClasses,
    outline: outlineClasses,
    ghost: ghostClasses,
  }[props.variant] || subtleClasses;

  const disabledClass = 'opacity-50 cursor-not-allowed pointer-events-none';

  let sizeClass = {
    sm: 'h-7 text-xs px-2.5 rounded-md',
    md: 'h-8 text-xs font-medium px-3 rounded-md',
    lg: 'h-9 text-sm font-medium px-3.5 rounded-lg',
    xl: 'h-10 text-base font-medium px-4 rounded-lg',
  }[props.size] || 'h-8 text-xs font-medium px-3 rounded-md';

  if (isIconButton.value) {
    sizeClass = {
      sm: 'h-7 w-7 rounded-md p-0 justify-center',
      md: 'h-8 w-8 rounded-md p-0 justify-center',
      lg: 'h-9 w-9 rounded-lg p-0 justify-center',
      xl: 'h-10 w-10 rounded-lg p-0 justify-center',
    }[props.size] || 'h-8 w-8 rounded-md p-0 justify-center';
  }

  return [
    'inline-flex items-center justify-center text-center gap-1.5 font-medium transition-all select-none cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-outline-gray-3 shrink-0 leading-none',
    isDisabled.value ? disabledClass : variantClass,
    sizeClass,
  ];
});

const iconClasses = computed(() => {
  return {
    sm: 'w-3.5 h-3.5 shrink-0',
    md: 'w-4 h-4 shrink-0',
    lg: 'w-4.5 h-4.5 shrink-0',
    xl: 'w-5 h-5 shrink-0',
  }[props.size] || 'w-4 h-4 shrink-0';
});
</script>
