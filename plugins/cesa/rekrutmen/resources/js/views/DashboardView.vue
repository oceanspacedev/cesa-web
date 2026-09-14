<template>
  <div class="space-y-6">
    <!-- Header Title & Action -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-zinc-200">
      <div>
        <h1 class="text-xl font-bold tracking-tight text-zinc-900 flex items-center gap-2">
          Dashboard Rekrutmen
        </h1>
        <p class="text-xs text-zinc-500 mt-1">
          Pantau seluruh aktivitas rekrutmen karyawan, lowongan aktif, permintaan man power, dan pipeline pelamar secara real-time.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <router-link to="/admin/job-applications">
          <Button
            variant="default"
            size="sm"
            class="h-8 bg-zinc-900 hover:bg-zinc-800 text-white gap-2 text-xs"
          >
            <Users class="w-3.5 h-3.5" />
            <span>Buka Kanban Board</span>
          </Button>
        </router-link>
      </div>
    </div>

    <!-- Stat Cards Grid -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
      <StatCard
        title="Lowongan Aktif"
        :value="data?.stats?.active_postings ?? 0"
        :subtext="`dari total ${data?.stats?.total_postings ?? 0} lowongan`"
        :icon="Briefcase"
        iconColor="text-zinc-400"
      />
      <StatCard
        title="Total Pelamar Masuk"
        :value="data?.stats?.total_applications ?? 0"
        subtext="seluruh posisi"
        :icon="Users"
        iconColor="text-zinc-400"
      />
      <StatCard
        title="Permintaan Man Power"
        :value="data?.stats?.total_requests ?? 0"
        subtext="FPTK terdaftar"
        :icon="FileSpreadsheet"
        iconColor="text-zinc-400"
      />
      <StatCard
        title="FPTK Menunggu Review"
        :value="data?.stats?.pending_requests ?? 0"
        subtext="butuh tindakan"
        :icon="Clock"
        iconColor="text-amber-500"
      />
    </div>

    <!-- Two Columns: Pipeline Stages Distribution & Recent Applications -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Pipeline Distribution Card -->
      <Card class="flex flex-col">
        <CardHeader class="pb-3 border-b border-zinc-100 flex flex-row items-center justify-between">
          <CardTitle class="text-sm font-semibold text-zinc-900">Distribusi Tahapan Pipeline</CardTitle>
          <router-link to="/admin/job-applications" class="text-xs text-zinc-500 hover:text-zinc-900 font-medium">
            Lihat Semua &rarr;
          </router-link>
        </CardHeader>

        <CardContent class="pt-4 flex-1 overflow-y-auto max-h-80 no-scrollbar space-y-2">
          <div
            v-for="stage in data?.stages_distribution"
            :key="stage.id"
            class="flex items-center justify-between p-2.5 rounded-lg bg-zinc-50 border border-zinc-200/80 hover:border-zinc-300 transition-colors"
          >
            <div class="flex items-center gap-2.5">
              <span
                class="w-2.5 h-2.5 rounded-full"
                :style="{ backgroundColor: stage.color || '#18181b' }"
              ></span>
              <span class="text-xs font-medium text-zinc-800">{{ stage.name }}</span>
            </div>
            <Badge variant="outline" class="text-xs font-medium bg-white">
              {{ stage.job_applications_count }} Pelamar
            </Badge>
          </div>
          <div v-if="!data?.stages_distribution?.length" class="text-xs text-zinc-400 text-center py-8">
            Belum ada data stage pipeline.
          </div>
        </CardContent>
      </Card>

      <!-- Recent Applications List -->
      <Card class="lg:col-span-2 flex flex-col">
        <CardHeader class="pb-3 border-b border-zinc-100 flex flex-row items-center justify-between">
          <CardTitle class="text-sm font-semibold text-zinc-900">Pelamar Terbaru</CardTitle>
          <router-link to="/admin/job-applications" class="text-xs text-zinc-500 hover:text-zinc-900 font-medium">
            Kelola Pelamar &rarr;
          </router-link>
        </CardHeader>

        <CardContent class="p-0 flex-1 overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead class="font-medium text-zinc-600">Nama Pelamar</TableHead>
                <TableHead class="font-medium text-zinc-600">Posisi Dilamar</TableHead>
                <TableHead class="font-medium text-zinc-600">Tahapan</TableHead>
                <TableHead class="font-medium text-zinc-600 text-right">Tanggal Masuk</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow
                v-for="app in data?.recent_applications"
                :key="app.id"
                class="hover:bg-zinc-50/80 transition-colors"
              >
                <TableCell>
                  <div class="font-semibold text-zinc-900">{{ app.name }}</div>
                  <div class="text-[11px] text-zinc-400 font-mono">{{ app.email }}</div>
                </TableCell>
                <TableCell class="font-medium text-zinc-700">
                  {{ app.job_posting?.title || '-' }}
                </TableCell>
                <TableCell>
                  <Badge variant="secondary" class="text-[11px] font-medium gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: app.stage?.color || '#71717a' }"></span>
                    {{ app.stage?.name || 'Review' }}
                  </Badge>
                </TableCell>
                <TableCell class="text-right text-xs text-zinc-500">
                  {{ formatDate(app.created_at) }}
                </TableCell>
              </TableRow>
              <TableRow v-if="!data?.recent_applications?.length">
                <TableCell colspan="4" class="py-8 text-center text-xs text-zinc-400">
                  Belum ada pelamar baru yang masuk.
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { Briefcase, Users, FileSpreadsheet, Clock } from 'lucide-vue-next';
import StatCard from '../components/StatCard.vue';
import { useRekrutmenStore } from '../stores/rekrutmen';

// Shadcn UI Components
import { Button } from '../components/ui/button';
import { Badge } from '../components/ui/badge';
import { Card, CardHeader, CardTitle, CardContent } from '../components/ui/card';
import { Table, TableHeader, TableBody, TableHead, TableRow, TableCell } from '../components/ui/table';

const store = useRekrutmenStore();
const data = computed(() => store.dashboardData);

const formatDate = (dateStr) => {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
};

onMounted(() => {
  store.fetchDashboard();
});
</script>
