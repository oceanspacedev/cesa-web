<template>
  <DialogTrigger as-child v-bind="$attrs">
    <slot />
  </DialogTrigger>
</template>

<script setup>
import { DialogTrigger } from 'radix-vue';
</script>
