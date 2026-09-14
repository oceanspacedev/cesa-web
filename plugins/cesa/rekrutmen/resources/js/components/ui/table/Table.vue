<template>
  <div class="relative w-full overflow-auto">
    <table :class="cn('w-full caption-bottom text-xs', $attrs.class)">
      <slot />
    </table>
  </div>
</template>

<script setup>
import { cn } from '../../../lib/utils';
</script>
