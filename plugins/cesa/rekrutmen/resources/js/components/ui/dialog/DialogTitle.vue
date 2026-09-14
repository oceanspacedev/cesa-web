<template>
  <DialogTitle :class="cn('text-base font-semibold text-zinc-900 tracking-tight leading-none', $attrs.class)">
    <slot />
  </DialogTitle>
</template>

<script setup>
import { DialogTitle } from 'radix-vue';
import { cn } from '../../../lib/utils';
</script>
