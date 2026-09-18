<template>
  <div class="space-y-4 pb-12 font-sans">
    <!-- Elevated Asoy Header Card -->
    <div class="p-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-surface-gray-2 border border-outline-gray-2 flex items-center justify-center shrink-0">
          <LayoutDashboard class="w-5 h-5 text-ink-gray-7" />
        </div>
        <div>
          <div class="flex items-center gap-2.5">
            <h1 class="text-base sm:text-lg font-bold text-ink-gray-9 tracking-tight">
              Dashboard Rekrutmen
            </h1>
            <FBadge theme="green" variant="subtle" size="sm" class="tabular-nums font-semibold">
              <template #prefix>
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
              </template>
              Live Monitoring
            </FBadge>
          </div>
          <p class="text-xs text-ink-gray-5 mt-0.5">
            Pantau seluruh aktivitas rekrutmen karyawan, lowongan aktif, permintaan man power, dan pipeline pelamar secara real-time.
          </p>
        </div>
      </div>

      <div class="flex items-center gap-2 shrink-0">
        <router-link to="/admin/job-applications">
          <FButton variant="solid" size="sm">
            <template #prefix>
              <KanbanSquare class="w-3.5 h-3.5" />
            </template>
            Buka Kanban Board
          </FButton>
        </router-link>
      </div>
    </div>

    <!-- Calibrated KPI Summary Cards (py-4 px-4) -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Lowongan Aktif</span>
          <span class="text-xl font-bold text-emerald-700 mt-1 block tabular-nums">
            {{ data?.stats?.active_postings ?? 0 }}
          </span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">
            dari total {{ data?.stats?.total_postings ?? 0 }} lowongan
          </span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-green-2 text-surface-green-3 flex items-center justify-center shrink-0">
          <Briefcase class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>

      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Total Pelamar Masuk</span>
          <span class="text-xl font-bold text-ink-gray-9 mt-1 block tabular-nums">
            {{ data?.stats?.total_applications ?? 0 }}
          </span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Seluruh posisi terdaftar</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-blue-2 text-surface-blue-3 flex items-center justify-center shrink-0">
          <Users class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>

      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Permintaan Man Power</span>
          <span class="text-xl font-bold text-ink-gray-9 mt-1 block tabular-nums">
            {{ data?.stats?.total_requests ?? 0 }}
          </span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Total FPTK dibuat</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-gray-2 text-ink-gray-7 flex items-center justify-center shrink-0">
          <FileSpreadsheet class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>

      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">FPTK Butuh Review</span>
          <span class="text-xl font-bold text-amber-700 mt-1 block tabular-nums">
            {{ data?.stats?.pending_requests ?? 0 }}
          </span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Menunggu persetujuan</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-gray-2 text-amber-600 flex items-center justify-center shrink-0">
          <Clock class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>
    </div>

    <!-- Two Columns: Pipeline Stages Distribution & Recent Applications -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- Pipeline Distribution Card -->
      <div class="bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex flex-col">
        <div class="p-3.5 border-b border-outline-gray-1 flex items-center justify-between">
          <h2 class="text-xs font-bold text-ink-gray-9 uppercase tracking-wider">Distribusi Tahapan Pipeline</h2>
          <router-link to="/admin/job-applications" class="text-xs text-blue-600 hover:text-blue-700 font-medium">
            Lihat Semua &rarr;
          </router-link>
        </div>

        <div class="p-3.5 flex-1 overflow-y-auto max-h-96 space-y-2">
          <div
            v-for="stage in data?.stages_distribution"
            :key="stage.id"
            class="flex items-center justify-between p-2.5 rounded-lg bg-surface-gray-1 border border-outline-gray-1 hover:border-outline-gray-2 transition-colors"
          >
            <div class="flex items-center gap-2.5">
              <span
                class="w-2.5 h-2.5 rounded-full shrink-0"
                :style="{ backgroundColor: stage.color || '#0c2340' }"
              ></span>
              <span class="text-xs font-medium text-ink-gray-9">{{ stage.name }}</span>
            </div>
            <FBadge theme="gray" size="sm" class="tabular-nums">
              {{ stage.job_applications_count }} Pelamar
            </FBadge>
          </div>
          <div v-if="!data?.stages_distribution?.length" class="text-xs text-ink-gray-4 text-center py-8">
            Belum ada data stage pipeline.
          </div>
        </div>
      </div>

      <!-- Recent Applications (Card Feed - No Table Layout) -->
      <div class="lg:col-span-2 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex flex-col">
        <div class="p-3.5 border-b border-outline-gray-1 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <h2 class="text-xs font-bold text-ink-gray-9 uppercase tracking-wider">Pelamar Terbaru</h2>
            <span class="text-xs text-ink-gray-4">({{ data?.recent_applications?.length || 0 }})</span>
          </div>
          <router-link to="/admin/job-applications" class="text-xs text-blue-600 hover:text-blue-700 font-medium">
            Kelola Semua Pelamar &rarr;
          </router-link>
        </div>

        <div class="p-3.5 flex-1 overflow-y-auto max-h-96 space-y-2.5">
          <div
            v-for="app in data?.recent_applications"
            :key="app.id"
            @click="navigateToApplication(app)"
            class="p-3 rounded-lg bg-surface-white border border-outline-gray-2 hover:border-outline-gray-3 hover:shadow-2xs transition-all flex flex-col sm:flex-row sm:items-center justify-between gap-3 cursor-pointer group"
          >
            <!-- Left: Avatar & Candidate Info -->
            <div class="flex items-center gap-3 min-w-0">
              <div class="w-9 h-9 rounded-full bg-blue-50 border border-blue-100 flex items-center justify-center font-bold text-xs text-blue-700 shrink-0">
                {{ getInitials(app.name) }}
              </div>
              <div class="min-w-0">
                <div class="text-xs font-bold text-ink-gray-9 group-hover:text-blue-600 transition-colors truncate">
                  {{ app.name }}
                </div>
                <div class="text-[11px] text-ink-gray-5 truncate">
                  {{ app.email }}
                </div>
              </div>
            </div>

            <!-- Middle: Applied Position -->
            <div class="min-w-0 flex-1 sm:px-4">
              <span class="text-[10px] text-ink-gray-4 block">Posisi Dilamar:</span>
              <span class="text-xs font-medium text-ink-gray-8 truncate block">
                {{ app.job_posting?.title || '-' }}
              </span>
            </div>

            <!-- Right: Stage Badge & Date -->
            <div class="flex items-center sm:flex-col sm:items-end justify-between gap-1 shrink-0">
              <span
                class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium border"
                :style="{
                  backgroundColor: `${app.stage?.color || '#3b82f6'}15`,
                  borderColor: `${app.stage?.color || '#3b82f6'}40`,
                  color: app.stage?.color || '#1e40af'
                }"
              >
                <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: app.stage?.color || '#3b82f6' }"></span>
                {{ app.stage?.name || 'Review' }}
              </span>
              <span class="text-[11px] text-ink-gray-4 tabular-nums">
                {{ formatDate(app.created_at) }}
              </span>
            </div>
          </div>

          <div v-if="!data?.recent_applications?.length" class="text-center py-12 text-xs text-ink-gray-4">
            Belum ada pelamar baru yang masuk.
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import {
  Briefcase,
  Users,
  FileSpreadsheet,
  Clock,
  LayoutDashboard,
  KanbanSquare,
} from 'lucide-vue-next';
import { useRekrutmenStore } from '../stores/rekrutmen';

// Frappe UI Components
import FButton from '../components/frappe/Button.vue';
import FBadge from 'frappe-ui/src/components/Badge/Badge.vue';

const store = useRekrutmenStore();
const router = useRouter();
const data = computed(() => store.dashboardData);

const formatDate = (dateStr) => {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
};

const getInitials = (name) => {
  if (!name) return '?';
  const parts = name.trim().split(' ').filter(Boolean);
  if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
};

const navigateToApplication = (app) => {
  if (!app?.id) return;
  router.push({
    path: '/admin/job-applications',
    query: {
      application_id: app.id,
      ...(app.job_posting_id || app.job_posting?.id ? { job_id: app.job_posting_id || app.job_posting.id } : {}),
    }
  });
};

onMounted(() => {
  store.fetchDashboard();
});
</script>
