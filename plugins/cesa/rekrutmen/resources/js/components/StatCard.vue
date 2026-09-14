<template>
  <Card class="hover:border-zinc-300">
    <CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0">
      <span class="text-xs font-medium text-zinc-500">{{ title }}</span>
      <component :is="icon" class="w-4 h-4" :class="iconColor || 'text-zinc-400'" />
    </CardHeader>
    <CardContent class="p-4 pt-0">
      <div class="text-2xl font-bold tracking-tight text-zinc-900">{{ value }}</div>
      <p v-if="subtext" class="text-[11px] text-zinc-500 mt-0.5">
        {{ subtext }}
      </p>
    </CardContent>
  </Card>
</template>

<script setup>
import { Card, CardHeader, CardContent } from './ui/card';

defineProps({
  title: String,
  value: [Number, String],
  subtext: String,
  icon: Object,
  iconColor: {
    type: String,
    default: 'text-zinc-400'
  }
});
</script>
