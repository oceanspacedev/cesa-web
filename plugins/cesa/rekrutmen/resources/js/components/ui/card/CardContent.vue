<template>
  <div :class="cn('p-5 pt-0', $attrs.class)">
    <slot />
  </div>
</template>

<script setup>
import { cn } from '../../../lib/utils';
</script>
