<template>
  <div :class="cn('animate-pulse rounded-md bg-zinc-100', $attrs.class)" />
</template>

<script setup>
import { cn } from '../../../lib/utils';
</script>
