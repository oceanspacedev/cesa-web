<template>
  <p :class="cn('text-xs text-zinc-500', $attrs.class)">
    <slot />
  </p>
</template>

<script setup>
import { cn } from '../../../lib/utils';
</script>
