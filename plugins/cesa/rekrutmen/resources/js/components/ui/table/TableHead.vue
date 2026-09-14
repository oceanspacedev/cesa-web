<template>
  <th :class="cn('h-9 px-4 text-left align-middle font-medium text-[11px] text-zinc-500 uppercase tracking-wider [&:has([role=checkbox])]:pr-0', $attrs.class)">
    <slot />
  </th>
</template>

<script setup>
import { cn } from '../../../lib/utils';
</script>
