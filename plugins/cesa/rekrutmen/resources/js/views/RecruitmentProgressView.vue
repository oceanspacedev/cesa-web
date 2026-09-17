<template>
  <div class="space-y-4 pb-12 font-sans">
    <!-- Elevated Asoy Header Card -->
    <div class="p-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-surface-gray-2 border border-outline-gray-2 flex items-center justify-center shrink-0">
          <TrendingUp class="w-5 h-5 text-ink-gray-7" />
        </div>
        <div>
          <div class="flex items-center gap-2.5">
            <h1 class="text-base sm:text-lg font-bold text-ink-gray-9 tracking-tight">
              Monitoring & Progres Rekrutmen
            </h1>
            <FBadge theme="green" variant="subtle" size="sm" class="tabular-nums font-semibold">
              <template #prefix>
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
              </template>
              {{ positions.length }} Posisi
            </FBadge>
          </div>
          <p class="text-xs text-ink-gray-5 mt-0.5">
            Pantau rasio pemenuhan personil Manpower Planning (MPP), pipeline seleksi, dan status target kebutuhan
          </p>
        </div>
      </div>

      <div class="flex items-center gap-2 shrink-0">
        <FButton
          variant="outline"
          size="sm"
          @click="refreshData"
          :loading="isRefreshing"
        >
          <template #prefix>
            <RotateCw class="w-3.5 h-3.5" />
          </template>
          Segarkan
        </FButton>

        <FButton
          variant="solid"
          size="sm"
          @click="exportExcel"
          :loading="isExporting"
        >
          <template #prefix>
            <FileSpreadsheet class="w-3.5 h-3.5" />
          </template>
          Export Excel
        </FButton>
      </div>
    </div>

    <!-- Floating Toast Notification -->
    <teleport to="body">
      <transition
        enter-active-class="transition duration-250 ease-out"
        enter-from-class="transform translate-y-3 opacity-0 scale-95"
        enter-to-class="transform translate-y-0 opacity-100 scale-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="transform translate-y-0 opacity-100 scale-100"
        leave-to-class="transform translate-y-3 opacity-0 scale-95"
      >
        <div
          v-if="toastMessage"
          class="fixed bottom-6 right-6 z-50 max-w-sm w-auto p-3 rounded-xl border flex items-center gap-3 text-xs font-medium shadow-lg backdrop-blur-md"
          :class="[
            toastType === 'success'
              ? 'bg-surface-white/95 border-emerald-200 text-emerald-900 shadow-emerald-950/10'
              : 'bg-surface-white/95 border-rose-200 text-rose-900 shadow-rose-950/10'
          ]"
        >
          <div :class="['w-7 h-7 rounded-lg flex items-center justify-center shrink-0', toastType === 'success' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700']">
            <CheckCircle2 v-if="toastType === 'success'" class="w-4 h-4" />
            <AlertCircle v-else class="w-4 h-4" />
          </div>
          <span class="pr-2">{{ toastMessage }}</span>
          <button
            type="button"
            @click="toastMessage = null"
            class="text-ink-gray-4 hover:text-ink-gray-7 font-bold p-1 rounded-md hover:bg-surface-gray-2 cursor-pointer ml-auto"
          >
            &times;
          </button>
        </div>
      </transition>
    </teleport>

    <!-- Calibrated KPI Summary Cards (py-4 px-4) -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Posisi Dipantau</span>
          <span class="text-xl font-bold text-ink-gray-9 mt-1 block tabular-nums">{{ positions.length }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Total jabatan pipeline</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-blue-2 text-surface-blue-3 flex items-center justify-center shrink-0">
          <Briefcase class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>

      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Target Kebutuhan</span>
          <span class="text-xl font-bold text-ink-gray-9 mt-1 block tabular-nums">{{ totalNeeded }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Total personil dicari</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-gray-2 text-ink-gray-7 flex items-center justify-center shrink-0">
          <Users class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>

      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Dalam Proses</span>
          <span class="text-xl font-bold text-amber-700 mt-1 block tabular-nums">{{ totalInProcess }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Kandidat sedang seleksi</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-gray-2 text-amber-600 flex items-center justify-center shrink-0">
          <Clock class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>

      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Terpenuhi (Hired)</span>
          <span class="text-xl font-bold text-emerald-700 mt-1 block tabular-nums">{{ totalHired }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Kandidat diterima kerja</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-green-2 text-surface-green-3 flex items-center justify-center shrink-0">
          <CheckCircle2 class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="p-2.5 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
      <div class="flex items-center gap-2">
        <span class="text-xs font-bold uppercase tracking-wider text-ink-gray-7">Pemenuhan Pipeline</span>
        <span class="text-xs text-ink-gray-4">({{ filteredPositions.length }} posisi)</span>
      </div>

      <FTextInput
        v-model="searchQuery"
        placeholder="Cari posisi, lokasi, perusahaan..."
        size="md"
        variant="outline"
        class="w-full sm:w-64"
      >
        <template #prefix>
          <Search class="w-3.5 h-3.5 text-zinc-400" />
        </template>
      </FTextInput>
    </div>

    <!-- Skeleton Loading State -->
    <div v-if="isLoading" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3.5">
      <div
        v-for="i in 6"
        :key="i"
        class="bg-surface-white rounded-lg border border-outline-gray-2 p-4 space-y-3 shadow-2xs"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="space-y-1.5 w-3/4">
            <Skeleton class="h-4 w-44" />
            <Skeleton class="h-3 w-28" />
          </div>
          <Skeleton class="h-5 w-16 rounded-full" />
        </div>
        <Skeleton class="h-2 w-full rounded-full" />
        <div class="grid grid-cols-4 gap-2 pt-2">
          <Skeleton class="h-8 rounded" />
          <Skeleton class="h-8 rounded" />
          <Skeleton class="h-8 rounded" />
          <Skeleton class="h-8 rounded" />
        </div>
        <Skeleton class="h-8 w-full rounded-md mt-2" />
      </div>
    </div>

    <!-- Card Feed (Zero Table Layout) -->
    <div v-else-if="filteredPositions.length" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3.5">
      <div
        v-for="item in filteredPositions"
        :key="item.job_posting_id || item.id"
        class="bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs hover:border-outline-gray-3 hover:shadow-xs transition-all duration-200 p-4 flex flex-col justify-between gap-3 group"
      >
        <!-- Card Top: Title, Meta, Badge -->
        <div class="space-y-2">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0 flex-1">
              <h3
                @click="navigateToJob(item)"
                class="text-sm font-bold text-ink-gray-9 group-hover:text-blue-600 transition-colors cursor-pointer truncate"
                :title="item.position"
              >
                {{ item.position }}
              </h3>
              <div class="flex items-center gap-2 text-xs text-ink-gray-5 mt-1 flex-wrap">
                <span class="inline-flex items-center gap-1">
                  <Building2 class="w-3 h-3 text-ink-gray-4 shrink-0" />
                  <span class="truncate max-w-[130px]">{{ item.company || 'Perusahaan' }}</span>
                </span>
                <span class="text-ink-gray-3">&bull;</span>
                <span class="inline-flex items-center gap-1">
                  <MapPin class="w-3 h-3 text-ink-gray-4 shrink-0" />
                  <span class="truncate max-w-[110px]">{{ item.location || 'Indonesia' }}</span>
                </span>
              </div>
            </div>

            <FBadge
              :theme="getHealthBadgeTheme(item)"
              size="sm"
              class="shrink-0"
            >
              {{ getHealthBadgeLabel(item) }}
            </FBadge>
          </div>

          <!-- Progress Bar Section -->
          <div class="space-y-1.5 pt-1.5">
            <div class="flex items-center justify-between text-xs">
              <span class="text-ink-gray-5 font-medium">Pemenuhan Personil</span>
              <span class="font-bold text-ink-gray-9 tabular-nums">
                {{ item.hired }} / {{ item.needed }}
                <span class="text-ink-gray-4 font-normal">({{ item.fulfillment_percentage || 0 }}%)</span>
              </span>
            </div>
            <div class="w-full bg-surface-gray-2 rounded-full h-2 overflow-hidden border border-outline-gray-1">
              <div
                :class="[
                  'h-2 rounded-full transition-all duration-300',
                  item.fulfillment_percentage >= 100
                    ? 'bg-emerald-500'
                    : item.fulfillment_percentage >= 50
                    ? 'bg-blue-600'
                    : item.fulfillment_percentage > 0
                    ? 'bg-amber-500'
                    : 'bg-zinc-300'
                ]"
                :style="{ width: `${Math.min(item.fulfillment_percentage || 0, 100)}%` }"
              ></div>
            </div>
          </div>
        </div>

        <!-- Metric Mini-Strip -->
        <div class="grid grid-cols-4 gap-1.5 py-2 px-2.5 rounded-lg bg-surface-gray-1 border border-outline-gray-1 text-center">
          <div>
            <span class="text-[10px] text-ink-gray-4 block">Target</span>
            <span class="text-xs font-bold text-ink-gray-9 tabular-nums">{{ item.needed }}</span>
          </div>
          <div>
            <span class="text-[10px] text-ink-gray-4 block">Pelamar</span>
            <span class="text-xs font-bold text-ink-gray-9 tabular-nums">{{ item.total_applicants }}</span>
          </div>
          <div>
            <span class="text-[10px] text-amber-700 block">Proses</span>
            <span class="text-xs font-bold text-amber-600 tabular-nums">{{ item.in_process }}</span>
          </div>
          <div>
            <span class="text-[10px] text-emerald-700 block">Hired</span>
            <span class="text-xs font-bold text-emerald-600 tabular-nums">{{ item.hired }}</span>
          </div>
        </div>

        <!-- Action Button -->
        <div class="pt-1">
          <FButton
            variant="subtle"
            size="sm"
            class="w-full justify-center"
            @click="navigateToJob(item)"
          >
            Lihat Pelamar & Seleksi &rarr;
          </FButton>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="p-12 text-center bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs">
      <Briefcase class="w-8 h-8 text-ink-gray-4 mx-auto mb-2" />
      <h4 class="text-sm font-bold text-ink-gray-9">Tidak Ada Data Progres</h4>
      <p class="text-xs text-ink-gray-5 mt-1">
        Tidak ada data posisi atau lowongan yang sesuai dengan kriteria pencarian.
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { useRekrutmenStore } from '../stores/rekrutmen';

// Frappe UI Components
import FButton from '../components/frappe/Button.vue';
import FBadge from 'frappe-ui/src/components/Badge/Badge.vue';
import FTextInput from '../components/frappe/TextInput.vue';

// Shadcn UI Components
import { Skeleton } from '../components/ui/skeleton';

// Icons
import {
  Search,
  FileSpreadsheet,
  RotateCw,
  Briefcase,
  Users,
  Clock,
  CheckCircle2,
  AlertCircle,
  MapPin,
  Building2,
  TrendingUp,
} from 'lucide-vue-next';

const store = useRekrutmenStore();
const router = useRouter();

const navigateToJob = (item) => {
  const id = item?.id || item?.job_posting_id;
  if (!id) return;
  router.push({
    path: '/admin/job-applications',
    query: { id: id, job_id: id }
  });
};

const isLoading = ref(true);
const isRefreshing = ref(false);
const searchQuery = ref('');
const isExporting = ref(false);
const toastMessage = ref(null);
const toastType = ref('success');

onMounted(async () => {
  isLoading.value = true;
  try {
    await store.fetchProgressReport(true);
  } finally {
    isLoading.value = false;
  }
});

const refreshData = async () => {
  isRefreshing.value = true;
  try {
    await store.fetchProgressReport(true);
    toastType.value = 'success';
    toastMessage.value = 'Laporan progres berhasil disegarkan.';
    setTimeout(() => { toastMessage.value = null; }, 2500);
  } catch (e) {
    toastType.value = 'error';
    toastMessage.value = 'Gagal memperbarui laporan progres.';
  } finally {
    isRefreshing.value = false;
  }
};

const positions = computed(() => store.progressData?.positions || []);

const totalNeeded = computed(() => {
  return positions.value.reduce((acc, p) => acc + (Number(p.needed) || 0), 0);
});

const totalInProcess = computed(() => {
  return positions.value.reduce((acc, p) => acc + (Number(p.in_process) || 0), 0);
});

const totalHired = computed(() => {
  return positions.value.reduce((acc, p) => acc + (Number(p.hired) || 0), 0);
});

const filteredPositions = computed(() => {
  if (!searchQuery.value) return positions.value;
  const q = searchQuery.value.toLowerCase();
  return positions.value.filter(p =>
    p.position?.toLowerCase().includes(q) ||
    p.location?.toLowerCase().includes(q) ||
    p.company?.toLowerCase().includes(q)
  );
});

const getHealthBadgeTheme = (item) => {
  const status = item?.cycle_health_status || '';
  if (status === 'risk') return 'red';
  if (status === 'watch') return 'orange';
  return 'green';
};

const getHealthBadgeLabel = (item) => {
  const status = item?.cycle_health_status || '';
  if (status === 'risk') return 'Kritis';
  if (status === 'watch') return 'Perhatian';
  return 'Normal';
};

const exportExcel = async () => {
  if (isExporting.value) return;
  isExporting.value = true;
  try {
    const response = await axios.get('/rekrutmen/api/progress-report/export', {
      responseType: 'blob',
    });

    let filename = 'recruitment-progress-mpp.xlsx';
    const disposition = response.headers['content-disposition'];
    if (disposition && disposition.includes('filename=')) {
      const filenameMatch = disposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
      if (filenameMatch && filenameMatch[1]) {
        filename = filenameMatch[1].replace(/['"]/g, '');
      }
    }

    const blob = new Blob([response.data], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    });
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
    toastType.value = 'success';
    toastMessage.value = 'Laporan progres berhasil diekspor ke Excel.';
    setTimeout(() => { toastMessage.value = null; }, 3000);
  } catch (err) {
    console.error('Export failed', err);
    window.location.href = '/rekrutmen/api/progress-report/export';
  } finally {
    isExporting.value = false;
  }
};
</script>

<style scoped>
.no-scrollbar::-webkit-scrollbar {
  display: none;
}
.no-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>
