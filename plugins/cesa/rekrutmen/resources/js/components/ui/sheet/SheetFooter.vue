<template>
  <div :class="cn('flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-2 border-t border-zinc-100 p-4 bg-zinc-50/50 shrink-0 gap-2 sm:gap-0', $attrs.class)">
    <slot />
  </div>
</template>

<script setup>
import { cn } from '../../../lib/utils';
</script>
