<template>
  <div class="space-y-4 pb-12 font-sans">
    <!-- Elevated Asoy Header Card -->
    <div class="p-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-surface-gray-2 border border-outline-gray-2 flex items-center justify-center shrink-0">
          <Settings class="w-5 h-5 text-ink-gray-7" />
        </div>
        <div>
          <div class="flex items-center gap-2.5">
            <h1 class="text-base sm:text-lg font-bold text-ink-gray-9 tracking-tight">
              Pengaturan Master Rekrutmen
            </h1>
            <FBadge theme="green" variant="subtle" size="sm" class="tabular-nums font-semibold">
              <template #prefix>
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
              </template>
              Sistem Aktif
            </FBadge>
          </div>
          <p class="text-xs text-ink-gray-5 mt-0.5">
            Konfigurasi pipeline tahapan seleksi, divisi terdaftar, dan parameter operasional.
          </p>
        </div>
      </div>
    </div>

    <!-- Master Data Cards Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <!-- Pipeline Stages List -->
      <div class="bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex flex-col">
        <div class="p-3.5 border-b border-outline-gray-1 flex items-center justify-between">
          <h2 class="text-xs font-bold text-ink-gray-9 uppercase tracking-wider">Tahapan Seleksi Pipeline</h2>
          <FBadge theme="gray" size="sm" class="tabular-nums">
            {{ masterData?.stages?.length || 0 }} Tahap
          </FBadge>
        </div>
        <div class="p-3.5 space-y-2 max-h-96 overflow-y-auto">
          <div
            v-for="(stage, idx) in masterData?.stages"
            :key="stage.id"
            class="flex items-center justify-between p-3 bg-surface-gray-1 border border-outline-gray-1 rounded-lg hover:border-outline-gray-2 transition-colors"
          >
            <div class="flex items-center gap-3">
              <span class="text-xs tabular-nums text-ink-gray-4 w-4">{{ idx + 1 }}.</span>
              <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ backgroundColor: stage.color || '#0c2340' }"></span>
              <span class="text-xs font-semibold text-ink-gray-9">{{ stage.name }}</span>
            </div>
            <FBadge theme="gray" size="sm" class="tabular-nums text-[10px]">
              Urutan #{{ stage.sort_order }}
            </FBadge>
          </div>
        </div>
      </div>

      <!-- Divisions List -->
      <div class="bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex flex-col">
        <div class="p-3.5 border-b border-outline-gray-1 flex items-center justify-between">
          <h2 class="text-xs font-bold text-ink-gray-9 uppercase tracking-wider">Divisi Terdaftar</h2>
          <FBadge theme="gray" size="sm" class="tabular-nums">
            {{ masterData?.divisions?.length || 0 }} Divisi
          </FBadge>
        </div>
        <div class="p-3.5 space-y-2 max-h-96 overflow-y-auto">
          <div
            v-for="div in masterData?.divisions"
            :key="div.id"
            class="flex items-center justify-between p-3 bg-surface-gray-1 border border-outline-gray-1 rounded-lg hover:border-outline-gray-2 transition-colors"
          >
            <div>
              <span class="text-xs font-semibold text-ink-gray-9">{{ div.name }}</span>
              <div class="text-[11px] text-ink-gray-5 mt-0.5">
                {{ div.company_name || div.badan_usaha || div.company?.name || '-' }}
              </div>
            </div>
            <FBadge theme="green" size="sm" class="text-[10px]">
              Aktif
            </FBadge>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { Settings } from 'lucide-vue-next';
import { useRekrutmenStore } from '../stores/rekrutmen';

// Frappe UI Components
import FBadge from 'frappe-ui/src/components/Badge/Badge.vue';

const store = useRekrutmenStore();
const masterData = computed(() => store.configurations);

onMounted(() => {
  store.fetchConfigurations();
});
</script>
