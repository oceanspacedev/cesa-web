<template>
  <DropdownMenuPortal>
    <DropdownMenuContent
      :class="cn(
        'z-50 min-w-[9rem] overflow-hidden rounded-md border border-zinc-200 bg-white p-1 text-zinc-950 shadow-md text-xs !transition-none !animate-none [transition:none!important] [animation:none!important]',
        $attrs.class
      )"
      :side-offset="sideOffset"
      :align="align"
      v-bind="$attrs"
    >
      <slot />
    </DropdownMenuContent>
  </DropdownMenuPortal>
</template>

<script setup>
import { DropdownMenuPortal, DropdownMenuContent } from 'radix-vue';
import { cn } from '../../../lib/utils';

defineProps({
  sideOffset: {
    type: Number,
    default: 4,
  },
  align: {
    type: String,
    default: 'end',
  },
});
</script>
