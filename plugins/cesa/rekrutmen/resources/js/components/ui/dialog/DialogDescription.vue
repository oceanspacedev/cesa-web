<template>
  <DialogDescription :class="cn('text-xs text-zinc-500 font-normal', $attrs.class)">
    <slot />
  </DialogDescription>
</template>

<script setup>
import { DialogDescription } from 'radix-vue';
import { cn } from '../../../lib/utils';
</script>
