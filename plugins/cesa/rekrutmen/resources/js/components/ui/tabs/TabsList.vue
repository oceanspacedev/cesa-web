<template>
  <TabsList
    :class="cn(
      'inline-flex h-9 items-center justify-center rounded-lg bg-zinc-100 p-1 text-zinc-500 border border-zinc-200/80',
      $attrs.class
    )"
  >
    <slot />
  </TabsList>
</template>

<script setup>
import { TabsList } from 'radix-vue';
import { cn } from '../../../lib/utils';
</script>
