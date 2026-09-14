<template>
  <TabsContent
    :value="value"
    :class="cn(
      'mt-2 ring-offset-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-950 focus-visible:ring-offset-2',
      $attrs.class
    )"
  >
    <slot />
  </TabsContent>
</template>

<script setup>
import { TabsContent } from 'radix-vue';
import { cn } from '../../../lib/utils';

defineProps({
  value: {
    type: String,
    required: true,
  },
});
</script>
