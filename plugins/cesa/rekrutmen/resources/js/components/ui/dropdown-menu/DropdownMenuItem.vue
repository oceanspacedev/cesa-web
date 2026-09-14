<template>
  <DropdownMenuItem
    :class="cn(
      'relative flex cursor-pointer select-none items-center gap-2 rounded-sm px-2.5 py-1.5 text-xs text-zinc-700 outline-none transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus:bg-zinc-100 focus:text-zinc-900 data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&>svg]:size-3.5 [&>svg]:shrink-0',
      $attrs.class
    )"
    v-bind="$attrs"
  >
    <slot />
  </DropdownMenuItem>
</template>

<script setup>
import { DropdownMenuItem } from 'radix-vue';
import { cn } from '../../../lib/utils';
</script>
