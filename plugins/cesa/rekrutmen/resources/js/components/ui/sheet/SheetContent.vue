<template>
  <DialogPortal>
    <DialogOverlay
      class="fixed inset-0 z-50 bg-black/40"
    />
    <DialogContent
      :class="cn(
        'fixed z-50 gap-4 bg-white p-0 shadow-xl inset-y-0 right-0 h-full w-full border-l border-zinc-200 sm:max-w-xl flex flex-col',
        $attrs.class
      )"
      v-bind="$attrs"
    >
      <slot />

      <DialogClose
        class="absolute right-4 top-4 rounded-sm opacity-70 ring-offset-white hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-zinc-950 focus:ring-offset-2 disabled:pointer-events-none p-1 text-zinc-500 hover:text-zinc-900 cursor-pointer"
      >
        <X class="h-4 w-4" />
        <span class="sr-only">Tutup</span>
      </DialogClose>
    </DialogContent>
  </DialogPortal>
</template>

<script setup>
import { DialogPortal, DialogOverlay, DialogContent, DialogClose } from 'radix-vue';
import { X } from 'lucide-vue-next';
import { cn } from '../../../lib/utils';
</script>
