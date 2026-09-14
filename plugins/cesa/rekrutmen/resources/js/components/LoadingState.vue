<template>
  <div class="w-full flex flex-col items-center justify-center py-16 px-4 animate-in fade-in duration-200" :style="{ minHeight: minHeight }">
    <!-- Spinning Loader -->
    <div class="relative w-12 h-12 mb-3.5 flex items-center justify-center">
      <div class="absolute inset-0 rounded-full border-2 border-blue-200 animate-ping opacity-30"></div>
      <div class="w-9 h-9 rounded-full border-3 border-slate-200 border-t-blue-600 animate-spin"></div>
    </div>

    <!-- Loading Message -->
    <div class="text-center space-y-1">
      <div class="text-xs font-bold text-slate-800 tracking-tight">
        {{ title || 'Sedang memuat data...' }}
      </div>
      <div v-if="subtitle" class="text-[11px] text-slate-400 font-medium">
        {{ subtitle }}
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: {
    type: String,
    default: 'Sedang memuat data...'
  },
  subtitle: {
    type: String,
    default: 'Mohon tunggu sebentar, sedang menyiapkan informasi...'
  },
  minHeight: {
    type: String,
    default: '380px'
  }
});
</script>
