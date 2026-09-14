<template>
  <div class="space-y-6 pb-12">
    <!-- Top Header Title & Action Button -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <div class="flex items-center gap-2.5">
          <h1 class="text-xl font-semibold text-zinc-900 tracking-tight">
            Permintaan Tenaga Kerja (FPTK)
          </h1>
          <Badge variant="outline" class="font-mono text-[11px] text-zinc-600 bg-zinc-50 border-zinc-200">
            {{ requests.length }} Pengajuan
          </Badge>
        </div>
        <p class="text-xs text-zinc-500 mt-1">
          Kelola dan pantau permohonan penambahan personil (Manpower Request) dari seluruh cabang dan divisi
        </p>
      </div>

      <div class="flex items-center gap-2">
        <Button
          variant="outline"
          size="sm"
          @click="refreshData"
          :disabled="isRefreshing"
          class="text-xs h-8 text-zinc-700 hover:text-zinc-900"
        >
          <RotateCw :class="['w-3.5 h-3.5 mr-1.5', isRefreshing ? 'animate-spin' : '']" />
          <span>Segarkan</span>
        </Button>

        <a href="/man-power" target="_blank" class="inline-flex">
          <Button size="sm" variant="default" class="bg-[#0c2340] hover:bg-[#153459] text-white text-xs h-8 gap-1.5 shadow-xs">
            <Plus class="w-3.5 h-3.5" />
            <span>Buat FPTK Baru</span>
          </Button>
        </a>
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
              ? 'bg-white/95 border-emerald-200 text-emerald-900 shadow-emerald-950/10'
              : 'bg-white/95 border-rose-200 text-rose-900 shadow-rose-950/10'
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
            class="text-zinc-400 hover:text-zinc-700 font-bold p-1 rounded-md hover:bg-zinc-100 cursor-pointer ml-auto"
          >
            &times;
          </button>
        </div>
      </transition>
    </teleport>

    <!-- KPI Summary Metrics (Polished Brand-Aligned Cards) -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
      <Card class="hover:border-blue-200 hover:shadow-xs transition-all duration-200 bg-white">
        <CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0">
          <span class="text-xs font-medium text-zinc-500">Total FPTK</span>
          <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center shrink-0">
            <FileSpreadsheet class="w-4 h-4" />
          </div>
        </CardHeader>
        <CardContent class="p-4 pt-0">
          <div class="text-2xl font-bold tracking-tight text-zinc-900">{{ requests.length }}</div>
          <p class="text-[11px] text-zinc-500 mt-0.5">
            Akumulasi permohonan personil
          </p>
        </CardContent>
      </Card>

      <Card class="hover:border-emerald-200 hover:shadow-xs transition-all duration-200 bg-white">
        <CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0">
          <span class="text-xs font-medium text-zinc-500">Disetujui</span>
          <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-700 flex items-center justify-center shrink-0">
            <CheckCircle2 class="w-4 h-4" />
          </div>
        </CardHeader>
        <CardContent class="p-4 pt-0">
          <div class="text-2xl font-bold tracking-tight text-emerald-700">{{ approvedCount }}</div>
          <p class="text-[11px] text-zinc-500 mt-0.5">
            Siap/sedang diproses rekrutmen
          </p>
        </CardContent>
      </Card>

      <Card class="hover:border-amber-200 hover:shadow-xs transition-all duration-200 bg-white">
        <CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0">
          <span class="text-xs font-medium text-zinc-500">Menunggu Approval</span>
          <div class="w-8 h-8 rounded-lg bg-amber-50 text-amber-700 flex items-center justify-center shrink-0">
            <Clock class="w-4 h-4" />
          </div>
        </CardHeader>
        <CardContent class="p-4 pt-0">
          <div class="text-2xl font-bold tracking-tight text-amber-700">{{ pendingCount }}</div>
          <p class="text-[11px] text-zinc-500 mt-0.5">
            Perlu persetujuan manajemen
          </p>
        </CardContent>
      </Card>

      <Card class="hover:border-indigo-200 hover:shadow-xs transition-all duration-200 bg-white">
        <CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0">
          <span class="text-xs font-medium text-zinc-500">Kebutuhan Personil</span>
          <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-700 flex items-center justify-center shrink-0">
            <Users class="w-4 h-4" />
          </div>
        </CardHeader>
        <CardContent class="p-4 pt-0">
          <div class="text-2xl font-bold tracking-tight text-zinc-900">{{ totalNeededPersonnel }}</div>
          <p class="text-[11px] text-zinc-500 mt-0.5">
            Total orang yang diajukan
          </p>
        </CardContent>
      </Card>
    </div>

    <!-- Filters & Toolbar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 bg-white rounded-xl border border-zinc-200 shadow-2xs">
      <!-- Status Filter Tabs -->
      <div class="inline-flex items-center p-1 bg-zinc-100/90 border border-zinc-200/80 rounded-lg text-xs overflow-x-auto no-scrollbar">
        <button
          type="button"
          @click="statusFilter = 'all'"
          :class="[
            'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer shrink-0 select-none flex items-center gap-1.5',
            statusFilter === 'all'
              ? 'bg-white text-[#0c2340] shadow-xs font-semibold'
              : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
          ]"
        >
          <span>Semua FPTK</span>
          <span
            :class="[
              'px-1.5 py-0.5 rounded-full text-[10px] font-semibold leading-none',
              statusFilter === 'all' ? 'bg-blue-50 text-[#0c2340]' : 'bg-zinc-200/70 text-zinc-500'
            ]"
          >
            {{ requests.length }}
          </span>
        </button>

        <button
          type="button"
          @click="statusFilter = 'approved'"
          :class="[
            'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer shrink-0 select-none flex items-center gap-1.5',
            statusFilter === 'approved'
              ? 'bg-white text-emerald-700 shadow-xs font-semibold'
              : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
          ]"
        >
          <span>Disetujui</span>
          <span
            :class="[
              'px-1.5 py-0.5 rounded-full text-[10px] font-semibold leading-none',
              statusFilter === 'approved' ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-200/70 text-zinc-500'
            ]"
          >
            {{ approvedCount }}
          </span>
        </button>

        <button
          type="button"
          @click="statusFilter = 'pending'"
          :class="[
            'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer shrink-0 select-none flex items-center gap-1.5',
            statusFilter === 'pending'
              ? 'bg-white text-amber-700 shadow-xs font-semibold'
              : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
          ]"
        >
          <span>Menunggu</span>
          <span
            :class="[
              'px-1.5 py-0.5 rounded-full text-[10px] font-semibold leading-none',
              statusFilter === 'pending' ? 'bg-amber-50 text-amber-700' : 'bg-zinc-200/70 text-zinc-500'
            ]"
          >
            {{ pendingCount }}
          </span>
        </button>
      </div>

      <!-- Search Input -->
      <div class="relative w-full sm:w-80">
        <Search class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-zinc-400" />
        <Input
          v-model="searchQuery"
          type="text"
          placeholder="Cari nomor FPTK, posisi, divisi..."
          class="pl-8 h-8 text-xs bg-zinc-50 focus:bg-white"
        />
        <button
          v-if="searchQuery"
          type="button"
          @click="searchQuery = ''"
          class="absolute right-2.5 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-600 text-xs font-bold"
        >
          &times;
        </button>
      </div>
    </div>

    <!-- SKELETON LOADING STATE -->
    <div v-if="isLoading" class="bg-white rounded-xl border border-zinc-200 shadow-2xs p-4 space-y-3">
      <div v-for="i in 5" :key="i" class="flex items-center justify-between gap-4 py-3 border-b border-zinc-100 last:border-0">
        <div class="space-y-1.5 w-1/4">
          <Skeleton class="h-4 w-36" />
          <Skeleton class="h-3 w-20" />
        </div>
        <Skeleton class="h-4 w-16" />
        <Skeleton class="h-4 w-16" />
        <Skeleton class="h-4 w-16" />
        <Skeleton class="h-4 w-28" />
        <Skeleton class="h-5 w-20 rounded-full" />
      </div>
    </div>

    <!-- DATA TABLE CARD: Shadcn Table Component -->
    <div v-else class="bg-white rounded-xl border border-zinc-200 shadow-2xs overflow-hidden">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead class="w-[28%]">No. FPTK & Posisi</TableHead>
            <TableHead class="w-[24%]">Divisi & Badan Usaha</TableHead>
            <TableHead class="w-[10%]">Kebutuhan</TableHead>
            <TableHead class="w-[16%]">Progres Pemenuhan</TableHead>
            <TableHead class="w-[12%]">Tgl Pengajuan</TableHead>
            <TableHead class="w-[10%]">Status</TableHead>
            <TableHead class="w-[8%] text-right">Aksi</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="item in filteredRequests" :key="item.id" class="group hover:bg-blue-50/30 transition-colors">
            <TableCell class="py-3.5">
              <div
                @click="openRequestDetail(item)"
                class="font-semibold text-xs text-zinc-900 hover:text-[#0c2340] cursor-pointer transition-colors"
              >
                {{ item.posisi_dibutuhkan || item.position_name || item.position_title || 'Staff' }}
              </div>
              <div class="text-[11px] text-zinc-400 font-mono mt-0.5">
                #{{ item.id || item.request_number }}
              </div>
            </TableCell>

            <TableCell class="py-3.5">
              <div class="font-medium text-zinc-800 text-xs truncate max-w-[220px]">
                {{ item.division_name || item.department || item.division?.name || '-' }}
              </div>
              <div class="text-[11px] text-zinc-500 truncate max-w-[220px] mt-0.5">
                {{ item.business_entity_name || item.company_name || '-' }}
              </div>
              <div class="text-[11px] text-zinc-400 flex items-center gap-1 mt-0.5">
                <MapPin class="w-3 h-3 text-zinc-400 shrink-0" />
                <span class="truncate">{{ item.lokasi_penempatan || item.branch || item.location || '-' }}</span>
              </div>
            </TableCell>

            <TableCell class="py-3.5 font-semibold text-zinc-900 text-xs">
              {{ item.jumlah_karyawan_dibutuhkan || item.quantity || 1 }} Orang
            </TableCell>

            <TableCell class="py-3.5">
              <div class="w-28 space-y-1">
                <div class="flex items-center justify-between text-[10px] font-medium text-zinc-600">
                  <span>{{ item.fulfilled_count || 0 }}/{{ item.jumlah_karyawan_dibutuhkan || item.quantity || 1 }}</span>
                  <span class="font-mono font-semibold text-zinc-700">{{ Math.min(100, Math.round(((item.fulfilled_count || 0) / (item.jumlah_karyawan_dibutuhkan || item.quantity || 1)) * 100)) }}%</span>
                </div>
                <div class="w-full bg-zinc-100 rounded-full h-1.5 overflow-hidden">
                  <div
                    :class="[
                      'h-1.5 rounded-full transition-all duration-300',
                      Math.round(((item.fulfilled_count || 0) / (item.jumlah_karyawan_dibutuhkan || item.quantity || 1)) * 100) >= 100
                        ? 'bg-emerald-500'
                        : Math.round(((item.fulfilled_count || 0) / (item.jumlah_karyawan_dibutuhkan || item.quantity || 1)) * 100) >= 50
                        ? 'bg-blue-600'
                        : Math.round(((item.fulfilled_count || 0) / (item.jumlah_karyawan_dibutuhkan || item.quantity || 1)) * 100) > 0
                        ? 'bg-amber-500'
                        : 'bg-zinc-300'
                    ]"
                    :style="{ width: `${Math.min(100, Math.round(((item.fulfilled_count || 0) / (item.jumlah_karyawan_dibutuhkan || item.quantity || 1)) * 100))}%` }"
                  ></div>
                </div>
              </div>
            </TableCell>

            <TableCell class="py-3.5 text-zinc-600 text-xs whitespace-nowrap">
              {{ item.tanggal_pengajuan || item.submission_date || item.created_at || '-' }}
            </TableCell>

            <TableCell class="py-3.5">
              <Badge :variant="getBadgeVariant(item.status || item.approval_status)" class="text-[10px] px-2 py-0.5">
                {{ item.status || item.approval_status || 'Pending' }}
              </Badge>
            </TableCell>

            <TableCell class="py-3.5 text-right whitespace-nowrap">
              <Button
                variant="outline"
                size="xs"
                @click="openRequestDetail(item)"
                class="h-7 text-zinc-700 hover:text-blue-950 hover:bg-blue-50/60 hover:border-blue-300 transition-colors"
              >
                Detail
              </Button>
            </TableCell>
          </TableRow>

          <TableRow v-if="!filteredRequests.length">
            <TableCell colspan="7" class="py-12 text-center text-xs text-zinc-500">
              Tidak ada data permohonan tenaga kerja yang sesuai kriteria.
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <!-- SLIDE-OVER SHEET: FPTK Detail Drawer -->
    <Sheet :open="!!selectedRequest" @update:open="(val) => { if (!val) selectedRequest = null; }">
      <SheetContent class="sm:max-w-lg">
        <SheetHeader>
          <div class="flex items-center gap-2">
            <SheetTitle>Detail Permintaan FPTK</SheetTitle>
            <Badge v-if="selectedRequest" :variant="getBadgeVariant(selectedRequest.status || selectedRequest.approval_status)" class="text-[10px]">
              {{ selectedRequest.status || selectedRequest.approval_status || 'Pending' }}
            </Badge>
          </div>
          <SheetDescription>
            Nomor Pengajuan #{{ selectedRequest?.id || selectedRequest?.request_number }}
          </SheetDescription>
        </SheetHeader>

        <div v-if="selectedRequest" class="p-6 space-y-4 overflow-y-auto flex-1 text-xs text-zinc-900">
          <div class="border border-zinc-200 rounded-lg p-4 space-y-3 bg-zinc-50/50">
            <div class="flex justify-between items-start pb-2 border-b border-zinc-200/70">
              <span class="text-zinc-500 font-medium">Posisi Dibutuhkan:</span>
              <span class="font-semibold text-zinc-900 text-right">{{ selectedRequest.posisi_dibutuhkan || selectedRequest.position_name || selectedRequest.position_title }}</span>
            </div>

            <div class="flex justify-between items-start pb-2 border-b border-zinc-200/70">
              <span class="text-zinc-500 font-medium">Divisi / Departemen:</span>
              <span class="font-medium text-zinc-800 text-right">{{ selectedRequest.division_name || selectedRequest.department || selectedRequest.division?.name || '-' }}</span>
            </div>

            <div class="flex justify-between items-start pb-2 border-b border-zinc-200/70">
              <span class="text-zinc-500 font-medium">Badan Usaha:</span>
              <span class="font-medium text-zinc-800 text-right">{{ selectedRequest.business_entity_name || selectedRequest.company_name || '-' }}</span>
            </div>

            <div class="flex justify-between items-start pb-2 border-b border-zinc-200/70">
              <span class="text-zinc-500 font-medium">Lokasi Penempatan:</span>
              <span class="font-medium text-zinc-800 text-right">{{ selectedRequest.lokasi_penempatan || selectedRequest.branch || selectedRequest.location || '-' }}</span>
            </div>

            <div class="flex justify-between items-start pb-2 border-b border-zinc-200/70">
              <span class="text-zinc-500 font-medium">Jumlah Kebutuhan:</span>
              <span class="font-bold text-zinc-900 text-right">{{ selectedRequest.jumlah_karyawan_dibutuhkan || selectedRequest.quantity || 1 }} Orang</span>
            </div>

            <div class="flex justify-between items-start pb-2 border-b border-zinc-200/70">
              <span class="text-zinc-500 font-medium">Tanggal Pengajuan:</span>
              <span class="font-medium text-zinc-800 text-right">{{ selectedRequest.tanggal_pengajuan || selectedRequest.submission_date || selectedRequest.created_at || '-' }}</span>
            </div>

            <div v-if="selectedRequest.nama_pengaju" class="flex justify-between items-start pb-2 border-b border-zinc-200/70">
              <span class="text-zinc-500 font-medium">Diajukan Oleh:</span>
              <span class="font-medium text-zinc-800 text-right">{{ selectedRequest.nama_pengaju }} ({{ selectedRequest.posisi_pengaju || '-' }})</span>
            </div>

            <div>
              <span class="text-zinc-500 font-medium block mb-1.5">Alasan / Kualifikasi Kebutuhan:</span>
              <p class="text-zinc-700 leading-relaxed whitespace-pre-line bg-white p-3 rounded-md border border-zinc-200 text-xs">
                {{ selectedRequest.requirements_kualifikasi || selectedRequest.keterangan || selectedRequest.reason || selectedRequest.justification || 'Kebutuhan operasional penambahan personil' }}
              </p>
            </div>
          </div>
        </div>

        <SheetFooter class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between w-full border-t border-zinc-200 bg-zinc-50/50 p-4 gap-2">
          <Button variant="outline" size="sm" @click="selectedRequest = null" :disabled="isActionLoading" class="text-xs h-8 text-zinc-700">
            Tutup
          </Button>

          <div v-if="selectedRequest && canTakeAction(selectedRequest)" class="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              class="border-rose-200 text-rose-600 hover:bg-rose-50 hover:text-rose-700 text-xs h-8 gap-1.5"
              @click="handleReject(selectedRequest)"
              :disabled="isActionLoading"
            >
              <XCircle class="w-3.5 h-3.5" />
              <span>Tolak</span>
            </Button>

            <Button
              size="sm"
              class="bg-zinc-900 hover:bg-zinc-800 text-white text-xs h-8 gap-1.5"
              @click="handleApprove(selectedRequest)"
              :disabled="isActionLoading"
            >
              <CheckCircle2 class="w-3.5 h-3.5" />
              <span>Setujui (Approve)</span>
            </Button>
          </div>

          <div v-else-if="selectedRequest" class="flex items-center gap-1.5 text-xs text-zinc-500 font-medium">
            <span v-if="String(selectedRequest.status || selectedRequest.approval_status || '').toLowerCase().includes('approv')" class="text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-1 rounded-md flex items-center gap-1.5">
              <CheckCircle2 class="w-3.5 h-3.5 text-emerald-600" />
              <span>Sudah Disetujui</span>
            </span>
            <span v-else-if="String(selectedRequest.status || selectedRequest.approval_status || '').toLowerCase().includes('reject')" class="text-rose-700 bg-rose-50 border border-rose-200 px-2.5 py-1 rounded-md flex items-center gap-1.5">
              <XCircle class="w-3.5 h-3.5 text-rose-600" />
              <span>Permintaan Ditolak</span>
            </span>
          </div>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRekrutmenStore } from '../stores/rekrutmen';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';

// Shadcn UI Components
import { Button } from '../components/ui/button';
import { Badge } from '../components/ui/badge';
import { Card, CardHeader, CardContent } from '../components/ui/card';
import { Table, TableHeader, TableBody, TableHead, TableRow, TableCell } from '../components/ui/table';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetFooter } from '../components/ui/sheet';
import { Skeleton } from '../components/ui/skeleton';
import { Input } from '../components/ui/input';

// Icons
import {
  Search,
  Plus,
  CheckCircle2,
  AlertCircle,
  Clock,
  Users,
  FileSpreadsheet,
  RotateCw,
  MapPin,
  XCircle,
} from 'lucide-vue-next';

const store = useRekrutmenStore();

const isLoading = ref(true);
const isRefreshing = ref(false);
const isActionLoading = ref(false);
const statusFilter = ref('all');
const searchQuery = ref('');
const toastMessage = ref(null);
const toastType = ref('success');
const selectedRequest = ref(null);

onMounted(async () => {
  isLoading.value = true;
  try {
    await store.fetchRequests('', false);
  } finally {
    isLoading.value = false;
  }
});

const refreshData = async () => {
  isRefreshing.value = true;
  try {
    await store.fetchRequests('', true);
    toastType.value = 'success';
    toastMessage.value = 'Data permintaan FPTK berhasil disegarkan.';
    setTimeout(() => { toastMessage.value = null; }, 2500);
  } catch (e) {
    toastType.value = 'error';
    toastMessage.value = 'Gagal memperbarui data FPTK.';
  } finally {
    isRefreshing.value = false;
  }
};

const requests = computed(() => store.requests || []);

const approvedCount = computed(() => requests.value.filter(r => {
  const s = String(r.status || r.approval_status || r.raw_status || '').toLowerCase();
  return s.includes('approv') || s.includes('setuju');
}).length);

const pendingCount = computed(() => requests.value.filter(r => {
  const s = String(r.status || r.approval_status || r.raw_status || '').toLowerCase();
  return s.includes('pend') || s.includes('tunggu');
}).length);

const totalNeededPersonnel = computed(() => {
  return requests.value.reduce((acc, r) => acc + (Number(r.jumlah_karyawan_dibutuhkan || r.quantity || 1) || 1), 0);
});

const filteredRequests = computed(() => {
  let list = requests.value;

  if (statusFilter.value === 'approved') {
    list = list.filter(r => {
      const s = String(r.status || r.approval_status || r.raw_status || '').toLowerCase();
      return s.includes('approv') || s.includes('setuju');
    });
  } else if (statusFilter.value === 'pending') {
    list = list.filter(r => {
      const s = String(r.status || r.approval_status || r.raw_status || '').toLowerCase();
      return s.includes('pend') || s.includes('tunggu');
    });
  }

  if (!searchQuery.value) return list;
  const q = searchQuery.value.toLowerCase();
  return list.filter(r => {
    const pos = String(r.posisi_dibutuhkan || r.position_name || r.position_title || '').toLowerCase();
    const num = String(r.id || r.request_number || '').toLowerCase();
    const div = String(r.division_name || r.department || '').toLowerCase();
    const company = String(r.business_entity_name || r.company_name || '').toLowerCase();
    const loc = String(r.lokasi_penempatan || r.branch || r.location || '').toLowerCase();
    const name = String(r.nama_pengaju || '').toLowerCase();
    return pos.includes(q) || num.includes(q) || div.includes(q) || company.includes(q) || loc.includes(q) || name.includes(q);
  });
});

const getBadgeVariant = (status) => {
  const s = String(status || '').toLowerCase();
  if (s.includes('approv') || s.includes('setuju')) return 'success';
  if (s.includes('reject') || s.includes('tolak')) return 'destructive';
  return 'warning';
};

const openRequestDetail = (item) => {
  selectedRequest.value = item;
};

const canTakeAction = (req) => {
  if (!req) return false;
  if (typeof req.can_approve_reject === 'boolean') {
    return req.can_approve_reject;
  }
  const s = String(req.raw_status || req.status || req.approval_status || '').toLowerCase();
  return !s.includes('approv') && !s.includes('setuju') && !s.includes('reject') && !s.includes('tolak');
};

const handleApprove = async (req) => {
  if (!req || isActionLoading.value) return;

  const result = await Swal.fire({
    title: 'Setujui Permintaan FPTK?',
    text: `Apakah Anda yakin ingin menyetujui permintaan posisi "${req.posisi_dibutuhkan || req.position_name || 'ini'}"? Lowongan kerja akan otomatis dibuat bila belum ada.`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Ya, Setujui',
    cancelButtonText: 'Batal',
    confirmButtonColor: '#18181b',
    cancelButtonColor: '#71717a',
    reverseButtons: true,
    customClass: {
      popup: 'rounded-xl border border-zinc-200 shadow-lg text-xs',
      title: 'text-sm font-semibold text-zinc-900',
      htmlContainer: 'text-xs text-zinc-500',
      confirmButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
      cancelButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
    },
  });

  if (!result.isConfirmed) return;

  isActionLoading.value = true;
  try {
    const res = await store.approveRequest(req.id);
    await store.fetchRequests('', true);

    const updated = (store.requests || []).find(r => String(r.id) === String(req.id));
    if (updated) {
      selectedRequest.value = updated;
    } else {
      req.status = 'Approved';
      req.approval_status = 'Approved';
      req.can_approve_reject = false;
    }

    Swal.fire({
      icon: 'success',
      title: 'Berhasil Disetujui',
      text: res?.message || 'Permintaan FPTK telah berhasil disetujui dan lowongan telah dibuat.',
      confirmButtonColor: '#18181b',
      customClass: {
        popup: 'rounded-xl border border-zinc-200 shadow-lg text-xs',
        title: 'text-sm font-semibold text-zinc-900',
        htmlContainer: 'text-xs text-zinc-500',
        confirmButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
      },
    });
  } catch (err) {
    const msg = err.response?.data?.message || err.message || 'Gagal menyetujui permintaan.';
    Swal.fire({
      icon: 'error',
      title: 'Gagal',
      text: msg,
      confirmButtonColor: '#18181b',
      customClass: {
        popup: 'rounded-xl border border-zinc-200 shadow-lg text-xs',
        title: 'text-sm font-semibold text-zinc-900',
        htmlContainer: 'text-xs text-zinc-500',
        confirmButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
      },
    });
  } finally {
    isActionLoading.value = false;
  }
};

const handleReject = async (req) => {
  if (!req || isActionLoading.value) return;

  const result = await Swal.fire({
    title: 'Tolak Permintaan FPTK?',
    text: `Apakah Anda yakin ingin menolak permintaan posisi "${req.posisi_dibutuhkan || req.position_name || 'ini'}"?`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Ya, Tolak Permintaan',
    cancelButtonText: 'Batal',
    confirmButtonColor: '#e11d48',
    cancelButtonColor: '#71717a',
    reverseButtons: true,
    customClass: {
      popup: 'rounded-xl border border-zinc-200 shadow-lg text-xs',
      title: 'text-sm font-semibold text-zinc-900',
      htmlContainer: 'text-xs text-zinc-500',
      confirmButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
      cancelButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
    },
  });

  if (!result.isConfirmed) return;

  isActionLoading.value = true;
  try {
    const res = await store.rejectRequest(req.id);
    await store.fetchRequests('', true);

    const updated = (store.requests || []).find(r => String(r.id) === String(req.id));
    if (updated) {
      selectedRequest.value = updated;
    } else {
      req.status = 'Rejected';
      req.approval_status = 'Rejected';
      req.can_approve_reject = false;
    }

    Swal.fire({
      icon: 'success',
      title: 'Permintaan Ditolak',
      text: res?.message || 'Permintaan FPTK telah ditolak.',
      confirmButtonColor: '#18181b',
      customClass: {
        popup: 'rounded-xl border border-zinc-200 shadow-lg text-xs',
        title: 'text-sm font-semibold text-zinc-900',
        htmlContainer: 'text-xs text-zinc-500',
        confirmButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
      },
    });
  } catch (err) {
    const msg = err.response?.data?.message || err.message || 'Gagal menolak permintaan.';
    Swal.fire({
      icon: 'error',
      title: 'Gagal',
      text: msg,
      confirmButtonColor: '#18181b',
      customClass: {
        popup: 'rounded-xl border border-zinc-200 shadow-lg text-xs',
        title: 'text-sm font-semibold text-zinc-900',
        htmlContainer: 'text-xs text-zinc-500',
        confirmButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
      },
    });
  } finally {
    isActionLoading.value = false;
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
