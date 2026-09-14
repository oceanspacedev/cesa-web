<template>
  <header class="h-16 bg-white border-b border-slate-200/80 flex items-center justify-between px-6 shrink-0 z-30">
    <!-- Left: Page Title -->
    <div class="flex items-center gap-3">
      <h2 class="text-sm font-bold text-slate-800 tracking-tight">{{ pageTitle }}</h2>
    </div>

    <!-- Right: Quick actions -->
    <div class="flex items-center gap-3">
      <a
        href="/admin"
        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-600 hover:text-blue-600 hover:bg-slate-50 border border-slate-200 transition-colors"
      >
        <LayoutDashboard class="w-3.5 h-3.5" />
        <span>Panel Admin Filament</span>
      </a>
    </div>
  </header>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { LayoutDashboard } from 'lucide-vue-next';

const route = useRoute();

const pageTitle = computed(() => {
  const path = route.path;
  if (path.includes('job-postings')) return 'Lowongan Pekerjaan (Job Postings)';
  if (path.includes('job-applications')) return 'Data Pelamar Kerja (Job Applications)';
  if (path.includes('request-man-powers')) return 'Permintaan Tenaga Kerja (Manpower Requests)';
  if (path.includes('recruitment-progress')) return 'Progress Rekrutmen (Monitoring Report)';
  if (path.includes('configurations')) return 'Master Data & Pengaturan';
  return 'Dashboard Rekrutmen';
});
</script>
