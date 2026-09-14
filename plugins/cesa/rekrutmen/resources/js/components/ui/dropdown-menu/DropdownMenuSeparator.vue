<template>
  <DropdownMenuSeparator
    :class="cn('-mx-1 my-1 h-px bg-zinc-100', $attrs.class)"
    v-bind="$attrs"
  />
</template>

<script setup>
import { DropdownMenuSeparator } from 'radix-vue';
import { cn } from '../../../lib/utils';
</script>
