<template>
  <div
    class="relative flex items-center w-full"
    :class="$attrs.class"
    :style="$attrs.style"
  >
    <div
      v-if="$slots.prefix"
      class="absolute inset-y-0 left-0 flex items-center pl-2.5 pointer-events-none text-ink-gray-4 shrink-0"
    >
      <slot name="prefix" />
    </div>

    <input
      ref="inputRef"
      :type="type"
      :placeholder="placeholder"
      :disabled="disabled"
      :id="id"
      :value="modelValue"
      :required="required"
      :class="[
        'w-full font-sans transition-all text-ink-gray-9 placeholder:text-ink-gray-4 focus:outline-none focus:ring-1',
        sizeClasses,
        variantClasses,
        $slots.prefix ? 'pl-9' : 'pl-3',
        ($slots.suffix || (clearable && modelValue)) ? 'pr-8' : 'pr-3',
        disabled ? 'opacity-60 cursor-not-allowed bg-surface-gray-2' : ''
      ]"
      :style="{
        paddingLeft: $slots.prefix ? '2.15rem' : undefined,
        paddingRight: ($slots.suffix || (clearable && modelValue)) ? '2rem' : undefined
      }"
      @input="handleInput"
      @change="handleChange"
      v-bind="attrsWithoutClass"
    />

    <div
      v-if="$slots.suffix || (clearable && modelValue)"
      class="absolute inset-y-0 right-0 flex items-center pr-2.5 shrink-0"
    >
      <slot name="suffix">
        <button
          v-if="clearable && modelValue"
          type="button"
          @click="clear"
          class="text-ink-gray-4 hover:text-ink-gray-7 p-0.5 rounded cursor-pointer transition-colors"
          tabindex="-1"
        >
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
          </svg>
        </button>
      </slot>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, useAttrs } from 'vue';

defineOptions({
  inheritAttrs: false,
});

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  type: { type: String, default: 'text' },
  size: { type: String, default: 'sm' },
  variant: { type: String, default: 'outline' },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  required: { type: Boolean, default: false },
  id: { type: String, default: null },
  debounce: { type: Number, default: 0 },
  clearable: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'change', 'clear']);
const attrs = useAttrs();
const inputRef = ref(null);

defineExpose({ el: inputRef, focus: () => inputRef.value?.focus() });

const attrsWithoutClass = computed(() => {
  const rest = { ...attrs };
  delete rest.class;
  delete rest.style;
  return rest;
});

const sizeClasses = computed(() => {
  return {
    sm: 'h-8 text-xs rounded-md py-1.5',
    md: 'h-8.5 text-xs rounded-md py-1.5',
    lg: 'h-10 text-sm rounded-md py-2',
    xl: 'h-11 text-base rounded-lg py-2.5',
  }[props.size] || 'h-8 text-xs rounded-md py-1.5';
});

const variantClasses = computed(() => {
  return {
    outline: 'border border-outline-gray-2 bg-surface-white hover:border-outline-gray-3 focus:bg-surface-white focus:border-outline-gray-4 focus:ring-outline-gray-3 shadow-2xs',
    subtle: 'border border-outline-gray-2 bg-surface-gray-2 hover:bg-surface-gray-3 focus:bg-surface-white focus:border-outline-gray-4 focus:ring-outline-gray-3',
    ghost: 'border-0 bg-transparent focus:ring-0',
  }[props.variant] || 'border border-outline-gray-2 bg-surface-white hover:border-outline-gray-3 focus:bg-surface-white focus:border-outline-gray-4 focus:ring-outline-gray-3 shadow-2xs';
});

let debounceTimer = null;

const handleInput = (e) => {
  const val = e.target.value;
  if (props.debounce > 0) {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      emit('update:modelValue', val);
    }, props.debounce);
  } else {
    emit('update:modelValue', val);
  }
};

const handleChange = (e) => {
  emit('change', e.target.value);
};

const clear = () => {
  emit('update:modelValue', '');
  emit('clear');
  inputRef.value?.focus();
};
</script>
