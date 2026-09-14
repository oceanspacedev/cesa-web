<template>
  <TabsRoot v-bind="forwarded" :class="cn('flex flex-col gap-2', $attrs.class)">
    <slot />
  </TabsRoot>
</template>

<script setup>
import { TabsRoot, useForwardPropsEmits } from 'radix-vue';
import { cn } from '../../../lib/utils';

const props = defineProps({
  defaultValue: { type: String, required: false },
  modelValue: { type: String, required: false },
  orientation: { type: String, required: false, default: 'horizontal' },
  dir: { type: String, required: false },
  activationMode: { type: String, required: false, default: 'automatic' },
});
const emits = defineEmits(['update:modelValue']);
const forwarded = useForwardPropsEmits(props, emits);
</script>
