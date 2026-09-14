<template>
  <div class="space-y-6">
    <div class="pb-2 border-b border-zinc-200">
      <h1 class="text-xl font-bold tracking-tight text-zinc-900 flex items-center gap-2">
        Pengaturan Master Rekrutmen
      </h1>
      <p class="text-xs text-zinc-500 mt-1">
        Konfigurasi pipeline tahapan seleksi, divisi terdaftar, dan parameter operasional.
      </p>
    </div>

    <!-- Master Data Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <!-- Pipeline Stages List -->
      <Card class="flex flex-col">
        <CardHeader class="pb-3 border-b border-zinc-100 flex flex-row items-center justify-between">
          <CardTitle class="text-sm font-semibold text-zinc-900">Tahapan Seleksi Pipeline</CardTitle>
          <Badge variant="secondary" class="text-xs font-medium">
            {{ masterData?.stages?.length || 0 }} Tahap
          </Badge>
        </CardHeader>
        <CardContent class="pt-4 space-y-2 max-h-96 overflow-y-auto no-scrollbar">
          <div
            v-for="(stage, idx) in masterData?.stages"
            :key="stage.id"
            class="flex items-center justify-between p-3 bg-zinc-50 border border-zinc-200/80 rounded-lg hover:border-zinc-300 transition-colors"
          >
            <div class="flex items-center gap-3">
              <span class="text-xs font-mono text-zinc-400 w-4">{{ idx + 1 }}.</span>
              <span class="w-2.5 h-2.5 rounded-full" :style="{ backgroundColor: stage.color || '#18181b' }"></span>
              <span class="text-xs font-semibold text-zinc-900">{{ stage.name }}</span>
            </div>
            <Badge variant="outline" class="text-[10px] font-mono text-zinc-500 bg-white">
              Urutan #{{ stage.sort_order }}
            </Badge>
          </div>
        </CardContent>
      </Card>

      <!-- Divisions List -->
      <Card class="flex flex-col">
        <CardHeader class="pb-3 border-b border-zinc-100 flex flex-row items-center justify-between">
          <CardTitle class="text-sm font-semibold text-zinc-900">Divisi Terdaftar</CardTitle>
          <Badge variant="secondary" class="text-xs font-medium">
            {{ masterData?.divisions?.length || 0 }} Divisi
          </Badge>
        </CardHeader>
        <CardContent class="pt-4 space-y-2 max-h-96 overflow-y-auto no-scrollbar">
          <div
            v-for="div in masterData?.divisions"
            :key="div.id"
            class="flex items-center justify-between p-3 bg-zinc-50 border border-zinc-200/80 rounded-lg hover:border-zinc-300 transition-colors"
          >
            <div>
              <span class="text-xs font-semibold text-zinc-900">{{ div.name }}</span>
              <div class="text-[11px] text-zinc-500 mt-0.5">{{ div.company_name || div.badan_usaha || div.company?.name || '-' }}</div>
            </div>
            <Badge variant="success" class="text-[10px] font-medium">
              Aktif
            </Badge>
          </div>
        </CardContent>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useRekrutmenStore } from '../stores/rekrutmen';

// Shadcn UI Components
import { Badge } from '../components/ui/badge';
import { Card, CardHeader, CardTitle, CardContent } from '../components/ui/card';

const store = useRekrutmenStore();
const masterData = computed(() => store.configurations);

onMounted(() => {
  store.fetchConfigurations();
});
</script>
