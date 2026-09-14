<template>
  <div :class="cn('rounded-xl border border-zinc-200 bg-white text-zinc-950 shadow-2xs transition-all', $attrs.class)">
    <slot />
  </div>
</template>

<script setup>
import { cn } from '../../../lib/utils';
</script>
