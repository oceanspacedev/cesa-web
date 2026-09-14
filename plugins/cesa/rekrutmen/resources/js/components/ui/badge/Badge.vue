<template>
  <div :class="cn(badgeVariants({ variant }), $attrs.class)">
    <slot />
  </div>
</template>

<script setup>
import { cva } from 'class-variance-authority';
import { cn } from '../../../lib/utils';

const badgeVariants = cva(
  'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-semibold transition-colors focus:outline-none focus:ring-1 focus:ring-zinc-950',
  {
    variants: {
      variant: {
        default: 'border-transparent bg-zinc-900 text-zinc-50 shadow-xs hover:bg-zinc-800',
        secondary: 'border-transparent bg-zinc-100 text-zinc-900 hover:bg-zinc-200/80',
        destructive: 'border-transparent bg-red-500 text-zinc-50 shadow-xs hover:bg-red-600',
        outline: 'text-zinc-800 border-zinc-200 bg-white hover:bg-zinc-50',
        success: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        warning: 'border-amber-200 bg-amber-50 text-amber-700',
        navy: 'border-blue-200 bg-blue-50 text-[#0c2340]',
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  }
);

defineProps({
  variant: {
    type: String,
    default: 'default',
  },
});
</script>
