<template>
  <div class="space-y-4 pb-12">
    <!-- Top Header: Title & Primary Actions (Elevated "Asoy" Card) -->
    <div class="p-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3.5">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-surface-gray-2 border border-outline-gray-2 flex items-center justify-center shrink-0 text-ink-gray-8 shadow-2xs">
          <FileSpreadsheet class="w-5 h-5 stroke-[1.75]" />
        </div>
        <div>
          <div class="flex items-center gap-2.5">
            <h1 class="text-base sm:text-lg font-bold text-ink-gray-9 tracking-tight">
              Permintaan Tenaga Kerja (FPTK)
            </h1>
            <FBadge theme="blue" variant="subtle" size="sm" class="tabular-nums font-semibold">
              <template #prefix>
                <span class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse shrink-0"></span>
              </template>
              {{ requests.length }} Pengajuan
            </FBadge>
          </div>
          <p class="text-xs text-ink-gray-5 mt-0.5">
            Kelola dan pantau permohonan penambahan personil (Manpower Request) dari seluruh cabang dan divisi
          </p>
        </div>
      </div>

      <div class="flex items-center gap-2 shrink-0">
        <FButton
          theme="gray"
          variant="outline"
          size="sm"
          :icon-left="RotateCw"
          :loading="isRefreshing"
          @click="refreshData"
        >
          Segarkan
        </FButton>

        <a href="/man-power" target="_blank">
          <FButton
            theme="gray"
            variant="solid"
            size="sm"
            :icon-left="Plus"
          >
            Buat FPTK Baru
          </FButton>
        </a>
      </div>
    </div>

    <!-- Floating Toast Notification -->
    <teleport to="body">
      <transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="transform translate-y-2 opacity-0 scale-95"
        enter-to-class="transform translate-y-0 opacity-100 scale-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="transform translate-y-0 opacity-100 scale-100"
        leave-to-class="transform translate-y-2 opacity-0 scale-95"
      >
        <div
          v-if="toastMessage"
          class="fixed bottom-6 right-6 z-50 max-w-sm w-auto p-3 rounded-lg border border-outline-gray-2 bg-surface-white text-ink-gray-9 shadow-lg flex items-center gap-3 text-xs font-medium select-none"
        >
          <span
            :class="[
              'w-2 h-2 rounded-full shrink-0',
              toastType === 'success' ? 'bg-emerald-500' : 'bg-rose-500'
            ]"
          ></span>
          <span class="pr-2">{{ toastMessage }}</span>
          <button
            type="button"
            @click="toastMessage = null"
            class="text-ink-gray-4 hover:text-ink-gray-7 font-bold p-1 rounded hover:bg-surface-gray-2 cursor-pointer ml-auto"
          >
            &times;
          </button>
        </div>
      </transition>
    </teleport>

    <!-- Calibrated KPI Cards (Comfortable vertical proportion py-4 px-4) -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
      <!-- Total FPTK -->
      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Total FPTK</span>
          <span class="text-xl font-bold text-ink-gray-9 mt-1 block tabular-nums">{{ requests.length }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Akumulasi permohonan personil</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-blue-2 text-surface-blue-3 flex items-center justify-center shrink-0">
          <FileSpreadsheet class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>

      <!-- Disetujui -->
      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Disetujui</span>
          <span class="text-xl font-bold text-emerald-700 mt-1 block tabular-nums">{{ approvedCount }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Siap/sedang diproses</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-green-2 text-surface-green-3 flex items-center justify-center shrink-0">
          <CheckCircle2 class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>

      <!-- Menunggu Approval -->
      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Menunggu Approval</span>
          <span class="text-xl font-bold text-amber-700 mt-1 block tabular-nums">{{ pendingCount }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Perlu persetujuan manajemen</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-gray-2 text-amber-600 flex items-center justify-center shrink-0">
          <Clock class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>

      <!-- Kebutuhan Personil -->
      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Kebutuhan Personil</span>
          <span class="text-xl font-bold text-ink-gray-9 mt-1 block tabular-nums">{{ totalNeededPersonnel }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Total orang diajukan</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-gray-2 text-ink-gray-7 flex items-center justify-center shrink-0">
          <Users class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>
    </div>

    <!-- Filters & Toolbar -->
    <div class="p-2.5 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
      <!-- Status Filter Tabs -->
      <div class="inline-flex items-center p-0.5 bg-surface-gray-2 rounded-md border border-outline-gray-2 overflow-x-auto no-scrollbar">
        <button
          type="button"
          @click="statusFilter = 'all'"
          :class="[
            'px-2.5 h-7 rounded text-xs font-medium transition-all cursor-pointer inline-flex items-center justify-center gap-1.5 select-none leading-none',
            statusFilter === 'all'
              ? 'bg-surface-white text-ink-gray-8 shadow-2xs font-semibold'
              : 'text-ink-gray-5 hover:text-ink-gray-8'
          ]"
        >
          <span>Semua FPTK</span>
          <span :class="['text-[10px] tabular-nums leading-none', statusFilter === 'all' ? 'text-ink-gray-8 font-semibold' : 'text-ink-gray-4']">
            {{ requests.length }}
          </span>
        </button>

        <button
          type="button"
          @click="statusFilter = 'approved'"
          :class="[
            'px-2.5 h-7 rounded text-xs font-medium transition-all cursor-pointer inline-flex items-center justify-center gap-1.5 select-none leading-none',
            statusFilter === 'approved'
              ? 'bg-surface-white text-emerald-800 shadow-2xs font-semibold'
              : 'text-ink-gray-5 hover:text-ink-gray-8'
          ]"
        >
          <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0"></span>
          <span>Disetujui</span>
          <span :class="['text-[10px] tabular-nums leading-none', statusFilter === 'approved' ? 'text-emerald-800 font-semibold' : 'text-ink-gray-4']">
            {{ approvedCount }}
          </span>
        </button>

        <button
          type="button"
          @click="statusFilter = 'pending'"
          :class="[
            'px-2.5 h-7 rounded text-xs font-medium transition-all cursor-pointer inline-flex items-center justify-center gap-1.5 select-none leading-none',
            statusFilter === 'pending'
              ? 'bg-surface-white text-amber-800 shadow-2xs font-semibold'
              : 'text-ink-gray-5 hover:text-ink-gray-8'
          ]"
        >
          <span class="w-1.5 h-1.5 rounded-full bg-amber-500 shrink-0"></span>
          <span>Menunggu</span>
          <span :class="['text-[10px] tabular-nums leading-none', statusFilter === 'pending' ? 'text-amber-800 font-semibold' : 'text-ink-gray-4']">
            {{ pendingCount }}
          </span>
        </button>
      </div>

      <!-- Search Input -->
      <FTextInput
        v-model="searchQuery"
        size="md"
        variant="outline"
        placeholder="Cari nomor FPTK, posisi, divisi..."
        class="w-full sm:w-64"
      >
        <template #prefix>
          <Search class="w-3.5 h-3.5 text-zinc-400" />
        </template>
      </FTextInput>
    </div>

    <!-- SKELETON LOADING STATE -->
    <div v-if="isLoading" class="bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs p-4 space-y-3">
      <div v-for="i in 4" :key="i" class="flex items-center justify-between gap-4 py-3 border-b border-outline-gray-1 last:border-0">
        <div class="space-y-1.5 w-1/3">
          <Skeleton class="h-4 w-40" />
          <Skeleton class="h-3 w-24" />
        </div>
        <Skeleton class="h-4 w-20" />
        <Skeleton class="h-4 w-28" />
        <Skeleton class="h-6 w-20 rounded-full" />
      </div>
    </div>

    <!-- FPTK REQUEST CARDS FEED (No Table Layout) -->
    <div v-else class="space-y-3">
      <!-- Summary Strip -->
      <div class="px-3.5 py-2.5 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between text-xs text-ink-gray-5">
        <span class="font-medium text-ink-gray-7">Daftar Pengajuan FPTK</span>
        <span class="text-[11px] text-ink-gray-4">Menampilkan {{ filteredRequests.length }} dari {{ requests.length }} permohonan</span>
      </div>

      <!-- Cards Feed -->
      <div v-if="filteredRequests.length" class="space-y-2.5">
        <div
          v-for="item in filteredRequests"
          :key="item.id"
          class="p-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs hover:shadow-xs hover:border-outline-gray-3 transition-all group"
        >
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-3.5">
            <!-- Left Info -->
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-2 flex-wrap">
                <FBadge theme="gray" variant="subtle" size="sm" class="tabular-nums font-semibold">
                  #{{ item.id || item.request_number }}
                </FBadge>
                <h3
                  class="font-bold text-sm text-ink-gray-9 hover:text-blue-600 transition-colors cursor-pointer truncate"
                  @click="openRequestDetail(item)"
                >
                  {{ item.posisi_dibutuhkan || item.position_name || item.position_title || 'Staff' }}
                </h3>
                <FBadge
                  :theme="getBadgeTheme(item.status || item.approval_status)"
                  variant="subtle"
                  size="sm"
                  class="font-semibold"
                >
                  {{ item.status || item.approval_status || 'Pending' }}
                </FBadge>
              </div>

              <!-- Division, Company, Location & Date Meta -->
              <div class="flex items-center gap-3 text-xs text-ink-gray-5 mt-2 flex-wrap">
                <span class="flex items-center gap-1 font-medium text-ink-gray-7">
                  <Building2 class="w-3.5 h-3.5 text-ink-gray-4 shrink-0" />
                  {{ item.division_name || item.department || item.division?.name || '-' }}
                  <span v-if="item.business_entity_name || item.company_name" class="text-ink-gray-4 font-normal">
                    ({{ item.business_entity_name || item.company_name }})
                  </span>
                </span>
                <span class="text-outline-gray-3">&bull;</span>
                <span class="flex items-center gap-1">
                  <MapPin class="w-3.5 h-3.5 text-ink-gray-4 shrink-0" />
                  {{ item.lokasi_penempatan || item.branch || item.location || '-' }}
                </span>
                <span class="text-outline-gray-3">&bull;</span>
                <span class="flex items-center gap-1 text-[11px] text-ink-gray-4">
                  <Calendar class="w-3 h-3 text-ink-gray-4 shrink-0" />
                  {{ item.tanggal_pengajuan || item.submission_date || item.created_at || '-' }}
                </span>
                <span v-if="item.nama_pengaju" class="text-outline-gray-3">&bull;</span>
                <span v-if="item.nama_pengaju" class="text-[11px] text-ink-gray-5">
                  Diajukan: <strong class="font-medium text-ink-gray-7">{{ item.nama_pengaju }}</strong>
                </span>
              </div>
            </div>

            <!-- Right Controls: Progress & Action -->
            <div class="flex items-center gap-4 shrink-0 justify-between md:justify-end pt-2 md:pt-0 border-t md:border-t-0 border-outline-gray-1">
              <!-- Fulfillment Progress -->
              <div class="w-36 space-y-1 text-right">
                <div class="flex items-center justify-between text-[11px] font-medium text-ink-gray-6">
                  <span>Kebutuhan:</span>
                  <span class="tabular-nums font-bold text-ink-gray-9">
                    {{ item.fulfilled_count || 0 }} / {{ item.jumlah_karyawan_dibutuhkan || item.quantity || 1 }} Org
                  </span>
                </div>
                <div class="w-full bg-surface-gray-2 rounded-full h-1.5 overflow-hidden">
                  <div
                    :class="[
                      'h-1.5 rounded-full transition-all duration-300',
                      Math.round(((item.fulfilled_count || 0) / (item.jumlah_karyawan_dibutuhkan || item.quantity || 1)) * 100) >= 100
                        ? 'bg-emerald-500'
                        : Math.round(((item.fulfilled_count || 0) / (item.jumlah_karyawan_dibutuhkan || item.quantity || 1)) * 100) >= 50
                        ? 'bg-blue-600'
                        : Math.round(((item.fulfilled_count || 0) / (item.jumlah_karyawan_dibutuhkan || item.quantity || 1)) * 100) > 0
                        ? 'bg-amber-500'
                        : 'bg-outline-gray-2'
                    ]"
                    :style="{ width: `${Math.min(100, Math.round(((item.fulfilled_count || 0) / (item.jumlah_karyawan_dibutuhkan || item.quantity || 1)) * 100))}%` }"
                  ></div>
                </div>
              </div>

              <!-- Detail Action Button -->
              <FButton
                theme="gray"
                variant="outline"
                size="sm"
                :icon-left="Eye"
                @click="openRequestDetail(item)"
              >
                Detail
              </FButton>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div
        v-else
        class="py-16 text-center bg-surface-white rounded-lg border border-outline-gray-2 p-8 shadow-2xs flex flex-col items-center justify-center gap-2"
      >
        <div class="w-12 h-12 rounded-full bg-surface-gray-2 text-ink-gray-4 flex items-center justify-center">
          <FileSpreadsheet class="w-6 h-6 stroke-[1.5]" />
        </div>
        <p class="text-sm font-semibold text-ink-gray-8">Tidak ada data permohonan tenaga kerja</p>
        <p class="text-xs text-ink-gray-5 max-w-sm">
          Tidak ada pengajuan FPTK yang sesuai dengan filter atau pencarian Anda saat ini.
        </p>
        <FButton
          theme="gray"
          variant="outline"
          size="sm"
          class="mt-2"
          @click="statusFilter = 'all'; searchQuery = '';"
        >
          Reset Semua Filter
        </FButton>
      </div>
    </div>

    <!-- SLIDE-OVER SHEET: FPTK Detail Drawer -->
    <Sheet :open="!!selectedRequest" @update:open="(val) => { if (!val) selectedRequest = null; }">
      <SheetContent class="sm:max-w-lg">
        <SheetHeader>
          <div class="flex items-center gap-2">
            <SheetTitle>Detail Permintaan FPTK</SheetTitle>
            <FBadge v-if="selectedRequest" :theme="getBadgeTheme(selectedRequest.status || selectedRequest.approval_status)" variant="subtle" size="sm" class="font-semibold">
              {{ selectedRequest.status || selectedRequest.approval_status || 'Pending' }}
            </FBadge>
          </div>
          <SheetDescription>
            Nomor Pengajuan #{{ selectedRequest?.id || selectedRequest?.request_number }}
          </SheetDescription>
        </SheetHeader>

        <div v-if="selectedRequest" class="p-6 space-y-4 overflow-y-auto flex-1 text-xs text-ink-gray-9">
          <div class="border border-outline-gray-2 rounded-lg p-4 space-y-3 bg-surface-gray-1">
            <div class="flex justify-between items-start pb-2 border-b border-outline-gray-2">
              <span class="text-ink-gray-5 font-medium">Posisi Dibutuhkan:</span>
              <span class="font-bold text-ink-gray-9 text-right">{{ selectedRequest.posisi_dibutuhkan || selectedRequest.position_name || selectedRequest.position_title }}</span>
            </div>

            <div class="flex justify-between items-start pb-2 border-b border-outline-gray-2">
              <span class="text-ink-gray-5 font-medium">Divisi / Departemen:</span>
              <span class="font-medium text-ink-gray-8 text-right">{{ selectedRequest.division_name || selectedRequest.department || selectedRequest.division?.name || '-' }}</span>
            </div>

            <div class="flex justify-between items-start pb-2 border-b border-outline-gray-2">
              <span class="text-ink-gray-5 font-medium">Badan Usaha:</span>
              <span class="font-medium text-ink-gray-8 text-right">{{ selectedRequest.business_entity_name || selectedRequest.company_name || '-' }}</span>
            </div>

            <div class="flex justify-between items-start pb-2 border-b border-outline-gray-2">
              <span class="text-ink-gray-5 font-medium">Lokasi Penempatan:</span>
              <span class="font-medium text-ink-gray-8 text-right">{{ selectedRequest.lokasi_penempatan || selectedRequest.branch || selectedRequest.location || '-' }}</span>
            </div>

            <div class="flex justify-between items-start pb-2 border-b border-outline-gray-2">
              <span class="text-ink-gray-5 font-medium">Jumlah Kebutuhan:</span>
              <span class="font-bold text-ink-gray-9 text-right">{{ selectedRequest.jumlah_karyawan_dibutuhkan || selectedRequest.quantity || 1 }} Orang</span>
            </div>

            <div class="flex justify-between items-start pb-2 border-b border-outline-gray-2">
              <span class="text-ink-gray-5 font-medium">Tanggal Pengajuan:</span>
              <span class="font-medium text-ink-gray-8 text-right">{{ selectedRequest.tanggal_pengajuan || selectedRequest.submission_date || selectedRequest.created_at || '-' }}</span>
            </div>

            <div v-if="selectedRequest.nama_pengaju" class="flex justify-between items-start pb-2 border-b border-outline-gray-2">
              <span class="text-ink-gray-5 font-medium">Diajukan Oleh:</span>
              <span class="font-medium text-ink-gray-8 text-right">{{ selectedRequest.nama_pengaju }} ({{ selectedRequest.posisi_pengaju || '-' }})</span>
            </div>

            <div>
              <span class="text-ink-gray-5 font-medium block mb-1.5">Alasan / Kualifikasi Kebutuhan:</span>
              <p class="text-ink-gray-7 leading-relaxed whitespace-pre-line bg-surface-white p-3 rounded-lg border border-outline-gray-2 text-xs shadow-2xs">
                {{ selectedRequest.requirements_kualifikasi || selectedRequest.keterangan || selectedRequest.reason || selectedRequest.justification || 'Kebutuhan operasional penambahan personil' }}
              </p>
            </div>
          </div>
        </div>

        <SheetFooter class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between w-full border-t border-outline-gray-2 bg-surface-gray-1 p-4 gap-2">
          <FButton theme="gray" variant="outline" size="sm" @click="selectedRequest = null" :disabled="isActionLoading">
            Tutup
          </FButton>

          <div v-if="selectedRequest && canTakeAction(selectedRequest)" class="flex items-center gap-2">
            <FButton
              theme="red"
              variant="outline"
              size="sm"
              :icon-left="XCircle"
              @click="handleReject(selectedRequest)"
              :loading="isActionLoading"
            >
              Tolak
            </FButton>

            <FButton
              theme="gray"
              variant="solid"
              size="sm"
              :icon-left="CheckCircle2"
              @click="handleApprove(selectedRequest)"
              :loading="isActionLoading"
            >
              Setujui (Approve)
            </FButton>
          </div>

          <div v-else-if="selectedRequest" class="flex items-center gap-1.5 text-xs text-ink-gray-5 font-medium">
            <FBadge v-if="String(selectedRequest.status || selectedRequest.approval_status || '').toLowerCase().includes('approv')" theme="green" variant="subtle" size="sm" class="font-semibold">
              <template #prefix>
                <CheckCircle2 class="w-3.5 h-3.5 text-emerald-600" />
              </template>
              Sudah Disetujui
            </FBadge>
            <FBadge v-else-if="String(selectedRequest.status || selectedRequest.approval_status || '').toLowerCase().includes('reject')" theme="red" variant="subtle" size="sm" class="font-semibold">
              <template #prefix>
                <XCircle class="w-3.5 h-3.5 text-rose-600" />
              </template>
              Permintaan Ditolak
            </FBadge>
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

// Frappe UI Components
import FButton from '../components/frappe/Button.vue';
import FBadge from 'frappe-ui/src/components/Badge/Badge.vue';
import FTextInput from '../components/frappe/TextInput.vue';

// Shadcn UI Components
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetFooter } from '../components/ui/sheet';
import { Skeleton } from '../components/ui/skeleton';

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
  Calendar,
  Building2,
  Eye,
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

const getBadgeTheme = (status) => {
  const s = String(status || '').toLowerCase();
  if (s.includes('approv') || s.includes('setuju')) return 'green';
  if (s.includes('reject') || s.includes('tolak')) return 'red';
  return 'orange';
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
