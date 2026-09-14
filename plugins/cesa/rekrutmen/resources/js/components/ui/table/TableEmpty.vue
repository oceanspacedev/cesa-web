<template>
  <TableRow>
    <TableCell
      :class="cn('p-8 text-center text-xs text-zinc-500', $attrs.class)"
      :colspan="colspan"
    >
      <div class="flex flex-col items-center justify-center gap-1.5">
        <slot />
      </div>
    </TableCell>
  </TableRow>
</template>

<script setup>
import { cn } from '../../../lib/utils';
import TableRow from './TableRow.vue';
import TableCell from './TableCell.vue';

defineProps({
  colspan: {
    type: Number,
    default: 1,
  },
});
</script>
