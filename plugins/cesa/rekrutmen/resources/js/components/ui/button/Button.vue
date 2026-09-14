<template>
  <component
    :is="as"
    :class="cn(buttonVariants({ variant, size }), $attrs.class)"
    :disabled="disabled"
  >
    <slot />
  </component>
</template>

<script setup>
import { cva } from 'class-variance-authority';
import { cn } from '../../../lib/utils';

const buttonVariants = cva(
  'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-md text-xs font-medium transition-all focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-zinc-950 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-3.5 [&_svg]:shrink-0 cursor-pointer select-none active:scale-[0.98]',
  {
    variants: {
      variant: {
        default: 'bg-zinc-900 text-zinc-50 shadow-xs hover:bg-zinc-800',
        destructive: 'bg-red-600 text-zinc-50 shadow-xs hover:bg-red-700',
        outline: 'border border-zinc-200 bg-white text-zinc-800 shadow-2xs hover:bg-zinc-50 hover:text-zinc-900 hover:border-zinc-300',
        secondary: 'bg-zinc-100 text-zinc-900 shadow-2xs hover:bg-zinc-200/80',
        ghost: 'text-zinc-700 hover:bg-zinc-100 hover:text-zinc-900',
        link: 'text-zinc-900 underline-offset-4 hover:underline',
        navy: 'bg-[#0c2340] text-white shadow-xs hover:bg-[#153a66]',
      },
      size: {
        default: 'h-8 px-3 py-1.5',
        xs: 'h-6 rounded px-2 text-[11px]',
        sm: 'h-7 rounded-md px-2.5 text-xs',
        lg: 'h-9 rounded-md px-4 text-sm',
        icon: 'h-8 w-8 p-0',
        'icon-sm': 'h-7 w-7 p-0',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  }
);

defineProps({
  as: {
    type: [String, Object],
    default: 'button',
  },
  variant: {
    type: String,
    default: 'default',
  },
  size: {
    type: String,
    default: 'default',
  },
  disabled: {
    type: Boolean,
    default: false,
  },
});
</script>
