<template>
  <h3 :class="cn('text-sm font-semibold leading-none tracking-tight text-zinc-900', $attrs.class)">
    <slot />
  </h3>
</template>

<script setup>
import { cn } from '../../../lib/utils';
</script>
