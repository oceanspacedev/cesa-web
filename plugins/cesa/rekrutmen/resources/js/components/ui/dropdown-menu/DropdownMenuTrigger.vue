<template>
  <DropdownMenuTrigger as-child v-bind="$attrs">
    <slot />
  </DropdownMenuTrigger>
</template>

<script setup>
import { DropdownMenuTrigger } from 'radix-vue';
</script>
