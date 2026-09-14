<template>
  <TabsTrigger
    :value="value"
    :class="cn(
      'inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-xs font-medium ring-offset-white transition-all focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-zinc-950 disabled:pointer-events-none disabled:opacity-50 data-[state=active]:bg-white data-[state=active]:text-zinc-950 data-[state=active]:shadow-2xs data-[state=active]:font-semibold cursor-pointer select-none text-zinc-600 hover:text-zinc-900',
      $attrs.class
    )"
  >
    <slot />
  </TabsTrigger>
</template>

<script setup>
import { TabsTrigger } from 'radix-vue';
import { cn } from '../../../lib/utils';

defineProps({
  value: {
    type: String,
    required: true,
  },
});
</script>
