<template>
  <div :class="cn('flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-2 pt-3 border-t border-zinc-100 gap-2 sm:gap-0', $attrs.class)">
    <slot />
  </div>
</template>

<script setup>
import { cn } from '../../../lib/utils';
</script>
