<template>
  <thead :class="cn('[&_tr]:border-b bg-zinc-50/75 border-zinc-200', $attrs.class)">
    <slot />
  </thead>
</template>

<script setup>
import { cn } from '../../../lib/utils';
</script>
