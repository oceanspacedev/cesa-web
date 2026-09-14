<template>
  <input
    :type="type"
    :value="modelValue"
    @input="$emit('update:modelValue', $event.target.value)"
    :class="cn(
      'flex h-8 w-full rounded-md border border-zinc-200 bg-white px-3 py-1 text-xs shadow-2xs transition-colors file:border-0 file:bg-transparent file:text-xs file:font-medium placeholder:text-zinc-400 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-zinc-950 focus-visible:border-zinc-950 disabled:cursor-not-allowed disabled:opacity-50 text-zinc-900',
      $attrs.class
    )"
    v-bind="$attrs"
  />
</template>

<script setup>
import { cn } from '../../../lib/utils';

defineProps({
  modelValue: {
    type: [String, Number],
    default: '',
  },
  type: {
    type: String,
    default: 'text',
  },
});

defineEmits(['update:modelValue']);
</script>
