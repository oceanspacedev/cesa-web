<template>
  <tr :class="cn('border-b border-zinc-100 transition-colors hover:bg-zinc-50/80 data-[state=selected]:bg-zinc-100', $attrs.class)">
    <slot />
  </tr>
</template>

<script setup>
import { cn } from '../../../lib/utils';
</script>
