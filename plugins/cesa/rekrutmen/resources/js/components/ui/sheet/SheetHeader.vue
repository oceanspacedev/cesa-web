<template>
  <div :class="cn('flex flex-col space-y-1 p-6 border-b border-zinc-100 shrink-0 text-left', $attrs.class)">
    <slot />
  </div>
</template>

<script setup>
import { cn } from '../../../lib/utils';
</script>
