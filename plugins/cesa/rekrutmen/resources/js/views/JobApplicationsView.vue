<template>
  <div class="space-y-6 pb-12">
    <!-- Top Header Title & Controls -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold text-zinc-900 tracking-tight">
          {{ activeJobTitle ? `Pelamar: ${activeJobTitle}` : 'Data Pelamar Kerja' }}
        </h1>
        <p class="text-xs text-zinc-500 mt-1">
          Pantau seluruh data kandidat pelamar, kualifikasi kecocokan, dan alur tahapan seleksi rekrutmen
        </p>
      </div>

      <!-- Controls & View Switcher -->
      <div class="flex items-center gap-2 flex-wrap">
        <!-- Active Job Filter Tag -->
        <button
          v-if="activeJobId"
          type="button"
          @click="resetJobFilter"
          class="h-8 px-2.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-800 rounded-md text-xs font-medium border border-zinc-200 flex items-center gap-1.5 transition-colors cursor-pointer"
          title="Tampilkan Semua Pelamar"
        >
          <span>Filter: {{ activeJobTitle }}</span>
          <span class="text-zinc-500 font-bold">&times;</span>
        </button>

        <!-- Sinkronkan Berkas CV Action Button -->
        <button
          @click="startSyncCvs"
          :disabled="isSyncingCvs"
          class="px-3.5 py-1.5 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 rounded-lg text-xs font-semibold flex items-center gap-1.5 shadow-2xs transition-colors cursor-pointer disabled:opacity-50"
          title="Cocokkan berkas CV di folder storage dengan data kandidat pelamar"
        >
          <FileText class="w-3.5 h-3.5 text-slate-500" :class="{ 'animate-pulse': isSyncingCvs }" />
          <span>Cocokkan CV Storage</span>
        </button>

        <!-- Evaluasi Kualifikasi Action Button -->
        <Button
          variant="outline"
          size="sm"
          @click="startRescreening"
          :disabled="isScreening"
          class="h-8 text-xs gap-1.5"
          title="Jalankan evaluasi kualifikasi otomatis untuk pelamar"
        >
          <RotateCw class="w-3.5 h-3.5 text-zinc-500" :class="{ 'animate-spin': isScreening }" />
          <span>Evaluasi Kualifikasi</span>
        </Button>

        <!-- View Switcher (Table / Kanban) -->
        <div class="inline-flex items-center bg-zinc-100 p-0.5 rounded-lg border border-zinc-200">
          <button
            type="button"
            @click="viewMode = 'table'"
            :class="[
              'px-2.5 py-1 rounded-md text-xs font-medium flex items-center gap-1.5 transition-all cursor-pointer select-none',
              viewMode === 'table' ? 'bg-white text-zinc-950 font-semibold shadow-2xs' : 'text-zinc-500 hover:text-zinc-900'
            ]"
          >
            <ListFilter class="w-3.5 h-3.5" />
            <span>Tabel</span>
          </button>
          <button
            type="button"
            @click="viewMode = 'kanban'"
            :class="[
              'px-2.5 py-1 rounded-md text-xs font-medium flex items-center gap-1.5 transition-all cursor-pointer select-none',
              viewMode === 'kanban' ? 'bg-white text-zinc-950 font-semibold shadow-2xs' : 'text-zinc-500 hover:text-zinc-900'
            ]"
          >
            <Kanban class="w-3.5 h-3.5" />
            <span>Kanban</span>
          </button>
        </div>
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
      <!-- Total Pelamar -->
      <Card class="hover:border-blue-200 hover:shadow-xs transition-all duration-200 bg-white">
        <CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0">
          <span class="text-xs font-medium text-zinc-500">Total Pelamar</span>
          <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center shrink-0">
            <Users class="w-4 h-4" />
          </div>
        </CardHeader>
        <CardContent class="p-4 pt-0">
          <div class="text-2xl font-bold tracking-tight text-zinc-900">{{ applications.length }}</div>
          <p class="text-[11px] text-zinc-500 mt-0.5">
            Semua berkas lamaran masuk
          </p>
        </CardContent>
      </Card>

      <!-- Sangat Sesuai -->
      <Card class="hover:border-emerald-200 hover:shadow-xs transition-all duration-200 bg-white">
        <CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0">
          <span class="text-xs font-medium text-zinc-500">Sangat Sesuai</span>
          <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-700 flex items-center justify-center shrink-0">
            <UserCheck class="w-4 h-4" />
          </div>
        </CardHeader>
        <CardContent class="p-4 pt-0">
          <div class="text-2xl font-bold tracking-tight text-emerald-700">{{ recommendedCount }}</div>
          <p class="text-[11px] text-zinc-500 mt-0.5">
            Skor kecocokan &ge; 75%
          </p>
        </CardContent>
      </Card>

      <!-- Dipertimbangkan -->
      <Card class="hover:border-amber-200 hover:shadow-xs transition-all duration-200 bg-white">
        <CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0">
          <span class="text-xs font-medium text-zinc-500">Dipertimbangkan</span>
          <div class="w-8 h-8 rounded-lg bg-amber-50 text-amber-700 flex items-center justify-center shrink-0">
            <Clock class="w-4 h-4" />
          </div>
        </CardHeader>
        <CardContent class="p-4 pt-0">
          <div class="text-2xl font-bold tracking-tight text-amber-700">{{ consideredCount }}</div>
          <p class="text-[11px] text-zinc-500 mt-0.5">
            Skor kecocokan 50% - 74%
          </p>
        </CardContent>
      </Card>

      <!-- Ditolak / Kurang Sesuai -->
      <Card class="hover:border-rose-200 hover:shadow-xs transition-all duration-200 bg-white">
        <CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0">
          <span class="text-xs font-medium text-zinc-500">Ditolak / Kurang Sesuai</span>
          <div class="w-8 h-8 rounded-lg bg-rose-50 text-rose-700 flex items-center justify-center shrink-0">
            <UserX class="w-4 h-4" />
          </div>
        </CardHeader>
        <CardContent class="p-4 pt-0">
          <div class="text-2xl font-bold tracking-tight text-rose-700">{{ rejectedCandidateCount + notSuitableCount }}</div>
          <p class="text-[11px] text-zinc-500 mt-0.5">
            {{ rejectedCandidateCount }} ditolak &bull; {{ notSuitableCount }} skor &lt; 50%
          </p>
        </CardContent>
      </Card>
    </div>

    <!-- Integrated Filter Tabs, Stage Filter Dropdown & Search Bar -->
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 border-b border-zinc-200 pb-3">
      <!-- Match Filter Tabs -->
      <div class="inline-flex items-center p-1 bg-zinc-100/90 border border-zinc-200/80 rounded-lg text-xs overflow-x-auto no-scrollbar">
        <button
          type="button"
          @click="matchFilter = 'all'"
          :class="[
            'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer shrink-0 select-none flex items-center gap-1.5',
            matchFilter === 'all'
              ? 'bg-white text-[#0c2340] shadow-xs font-semibold'
              : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
          ]"
        >
          <span>Semua Pelamar</span>
          <span
            :class="[
              'px-1.5 py-0.5 rounded-full text-[10px] font-semibold leading-none',
              matchFilter === 'all' ? 'bg-blue-50 text-[#0c2340]' : 'bg-zinc-200/70 text-zinc-500'
            ]"
          >
            {{ applications.length }}
          </span>
        </button>

        <button
          type="button"
          @click="matchFilter = 'recommended'"
          :class="[
            'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer shrink-0 select-none flex items-center gap-1.5',
            matchFilter === 'recommended'
              ? 'bg-white text-emerald-700 shadow-xs font-semibold'
              : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
          ]"
        >
          <span>Sangat Sesuai</span>
          <span
            :class="[
              'px-1.5 py-0.5 rounded-full text-[10px] font-semibold leading-none',
              matchFilter === 'recommended' ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-200/70 text-zinc-500'
            ]"
          >
            {{ recommendedCount }}
          </span>
        </button>

        <button
          type="button"
          @click="matchFilter = 'considered'"
          :class="[
            'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer shrink-0 select-none flex items-center gap-1.5',
            matchFilter === 'considered'
              ? 'bg-white text-amber-700 shadow-xs font-semibold'
              : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
          ]"
        >
          <span>Dipertimbangkan</span>
          <span
            :class="[
              'px-1.5 py-0.5 rounded-full text-[10px] font-semibold leading-none',
              matchFilter === 'considered' ? 'bg-amber-50 text-amber-700' : 'bg-zinc-200/70 text-zinc-500'
            ]"
          >
            {{ consideredCount }}
          </span>
        </button>

        <button
          type="button"
          @click="matchFilter = 'not_suitable'"
          :class="[
            'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer shrink-0 select-none flex items-center gap-1.5',
            matchFilter === 'not_suitable'
              ? 'bg-white text-rose-700 shadow-xs font-semibold'
              : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
          ]"
        >
          <span>Kurang Sesuai</span>
          <span
            :class="[
              'px-1.5 py-0.5 rounded-full text-[10px] font-semibold leading-none',
              matchFilter === 'not_suitable' ? 'bg-rose-50 text-rose-700' : 'bg-zinc-200/70 text-zinc-500'
            ]"
          >
            {{ notSuitableCount }}
          </span>
        </button>
      </div>

      <!-- Right Controls: Stage Filter Dropdown + Search Bar -->
      <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
        <!-- Stage Filter Dropdown -->
        <div class="relative min-w-[200px]">
          <select
            v-model="stageFilter"
            class="w-full h-8 bg-white border border-zinc-200 rounded-md pl-3 pr-8 text-xs text-zinc-800 focus:outline-none focus:ring-1 focus:ring-zinc-950 focus:border-zinc-950 appearance-none cursor-pointer transition-colors"
          >
            <option value="all">Semua Tahapan ({{ applications.length }})</option>
            <option v-for="stg in stages" :key="stg.id" :value="stg.id">
              {{ stg.name }} ({{ getStageCandidateCount(stg.id) }})
            </option>
            <option value="rejected">
              Ditolak ({{ rejectedCandidateCount }})
            </option>
          </select>
          <ChevronDown class="w-3.5 h-3.5 text-zinc-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
        </div>

        <button
          v-if="stageFilter !== 'all'"
          type="button"
          @click="stageFilter = 'all'"
          class="h-8 px-2.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-600 rounded-md text-xs font-medium transition-colors cursor-pointer shrink-0 flex items-center gap-1"
          title="Reset filter tahapan"
        >
          <span>Reset</span>
          <span class="text-zinc-400 font-bold">&times;</span>
        </button>

        <!-- Search Bar -->
        <div class="relative w-full sm:w-64">
          <Search class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-zinc-400 pointer-events-none" />
          <Input
            v-model="searchQuery"
            type="text"
            placeholder="Cari nama, email, posisi..."
            class="h-8 pl-8 pr-3 text-xs"
          />
        </div>
      </div>
    </div>
    <!-- TABLE VIEW -->
    <div
      v-if="viewMode === 'table'"
      class="bg-white rounded-xl border border-zinc-200 shadow-2xs overflow-hidden"
    >
      <!-- Bulk Selection Action Bar: Clean, modern, unified styling -->
      <div
        v-if="selectedAppIds.length"
        class="bg-blue-50/70 border-b border-blue-100 px-4 py-2.5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs animate-in fade-in duration-150"
      >
        <div class="flex items-center gap-2.5">
          <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-bold bg-white text-[#0c2340] border border-blue-200/80 shadow-2xs">
            <CheckSquare class="w-3.5 h-3.5 text-blue-600" />
            <span>{{ selectedAppIds.length }} pelamar dipilih</span>
          </div>
          <span class="text-zinc-300">&bull;</span>
          <button
            type="button"
            @click="selectedAppIds = []"
            class="text-zinc-500 hover:text-zinc-800 text-xs font-medium cursor-pointer transition-colors hover:underline"
          >
            Batalkan pilihan
          </button>
        </div>
        <div class="flex items-center gap-2">
          <Button
            type="button"
            variant="outline"
            size="xs"
            @click="bulkRejectSelected"
            class="gap-1.5 h-7.5 px-2.5 bg-white text-rose-700 border-rose-200 hover:bg-rose-50 hover:border-rose-300 font-medium shadow-2xs cursor-pointer"
            title="Tolak pelamar terpilih"
          >
            <UserX class="w-3.5 h-3.5 text-rose-600" />
            <span>Tolak</span>
          </Button>
          <Button
            type="button"
            size="xs"
            @click="openBulkNotificationModal"
            class="bg-[#0c2340] hover:bg-[#12335c] text-white gap-1.5 h-7.5 px-3 font-semibold shadow-xs cursor-pointer"
          >
            <Send class="w-3.5 h-3.5" />
            <span>Kirim Notifikasi Massal</span>
          </Button>
        </div>
      </div>

      <Table>
        <TableHeader>
          <TableRow>
            <TableHead class="w-10 text-center">
              <input
                type="checkbox"
                :checked="isAllSelected"
                @change="toggleSelectAll"
                class="rounded border-zinc-300 text-zinc-900 accent-zinc-900 focus:ring-0 cursor-pointer w-3.5 h-3.5"
                title="Pilih Semua Pelamar"
              />
            </TableHead>
            <TableHead>Kandidat Pelamar</TableHead>
            <TableHead>Posisi Dilamar</TableHead>
            <TableHead class="text-center">Kualifikasi Match</TableHead>
            <TableHead class="text-center">Tahapan Seleksi</TableHead>
            <TableHead class="text-center">Status</TableHead>
            <TableHead class="text-center">Tgl Masuk</TableHead>
            <TableHead class="text-right w-28 pr-6">Aksi</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow
            v-for="app in filteredApplications"
            :key="app.id"
            :class="['hover:bg-blue-50/30 transition-colors group', isSelected(app.id) ? 'bg-blue-50/50' : '']"
          >
            <!-- Checkbox -->
            <TableCell class="text-center" @click.stop>
              <input
                type="checkbox"
                :value="app.id"
                v-model="selectedAppIds"
                class="rounded border-zinc-300 text-[#0c2340] accent-[#0c2340] focus:ring-0 cursor-pointer w-3.5 h-3.5"
              />
            </TableCell>

            <!-- Name & Contact -->
            <TableCell>
              <div class="flex items-center gap-2.5 min-w-0">
                <div class="w-8 h-8 rounded-full bg-zinc-100 flex items-center justify-center shrink-0 border border-zinc-200 overflow-hidden relative">
                  <img
                    v-if="app.photo_url"
                    :src="app.photo_url"
                    :alt="app.full_name"
                    class="w-full h-full object-cover"
                    loading="lazy"
                    @error="(e) => e.target.style.display = 'none'"
                  />
                  <span v-else class="text-[10px] font-bold text-zinc-600 uppercase">{{ getInitials(app.full_name) }}</span>
                </div>
                <div class="min-w-0">
                  <div class="font-semibold text-xs text-zinc-900 hover:text-[#0c2340] transition-colors cursor-pointer truncate" @click="openDetail(app)">
                    {{ app.full_name }}
                  </div>
                  <div class="text-[11px] text-zinc-400 mt-0.5 truncate max-w-[200px]" :title="`${app.email || '-'} • ${app.whatsapp_number || app.phone || '-'}`">
                    {{ app.email || '-' }} &bull; {{ app.whatsapp_number || app.phone || '-' }}
                  </div>
                </div>
              </div>
            </TableCell>

            <!-- Job Title -->
            <TableCell class="text-zinc-700 text-xs font-medium">
              <div class="truncate max-w-[170px]" :title="app.job_posting?.title">
                {{ app.job_posting?.title || '-' }}
              </div>
            </TableCell>

            <!-- Score / Match (Centered) -->
            <TableCell class="text-center">
              <div v-if="app.ai_match_score !== null && app.ai_match_score !== undefined" class="flex items-center justify-center">
                <button
                  type="button"
                  @click.stop="openAnalysisModal(app)"
                  class="cursor-pointer"
                  title="Klik untuk melihat hasil analisis kualifikasi"
                >
                  <Badge
                    :variant="app.ai_match_score >= 75 ? 'success' : (app.ai_match_score >= 50 ? 'warning' : 'secondary')"
                    class="text-[10px] font-medium gap-1 px-2 py-0.5"
                  >
                    <span>{{ app.ai_match_score }}%</span>
                    <span>&bull;</span>
                    <span>{{ formatAiRecommendation(app.ai_recommendation) }}</span>
                  </Badge>
                </button>
              </div>
              <div v-else class="text-[11px] text-zinc-400 italic text-center">Menunggu evaluasi</div>
            </TableCell>

            <!-- Stage (Centered, Clean Dropdown) -->
            <TableCell class="text-center" @click.stop>
              <div class="inline-flex items-center justify-center relative">
                <select
                  :value="app.status === 'rejected' ? 'rejected' : (app.current_stage_id || app.stage?.id || 1)"
                  @change="handleStageChange(app, $event.target.value)"
                  :class="[
                    'h-7 text-xs font-medium rounded-md pl-2.5 pr-7 border cursor-pointer transition-colors appearance-none shadow-2xs',
                    app.status === 'rejected'
                      ? 'bg-rose-50 border-rose-300 text-rose-700 font-semibold'
                      : 'bg-white border-zinc-200 text-zinc-800 hover:border-zinc-300 focus:outline-none focus:ring-1 focus:ring-zinc-950'
                  ]"
                  title="Ubah tahapan kandidat"
                >
                  <option v-for="stg in stages" :key="stg.id" :value="stg.id">
                    {{ stg.name }}
                  </option>
                  <option value="rejected" class="text-rose-600 font-semibold">
                    Ditolak
                  </option>
                </select>
                <ChevronDown class="w-3 h-3 text-zinc-400 absolute right-2 pointer-events-none" />
              </div>
            </TableCell>

            <!-- Status (Centered) -->
            <TableCell class="text-center">
              <Badge
                :variant="app.status === 'rejected' ? 'destructive' : ((app.status === 'hired' || app.status === 'shortlist') ? 'success' : 'secondary')"
                class="text-[10px] px-2 py-0.5"
              >
                {{ formatStatus(app.status) }}
              </Badge>
            </TableCell>

            <!-- Date (Centered) -->
            <TableCell class="text-center text-zinc-500 whitespace-nowrap text-[11px]">
              {{ app.created_at }}
            </TableCell>

            <!-- Action Buttons -->
            <TableCell class="text-right whitespace-nowrap pr-6">
              <div class="flex items-center justify-end gap-1">
                <Button
                  variant="ghost"
                  size="xs"
                  @click="openDetail(app)"
                  class="h-7 w-7 p-0 text-zinc-500 hover:text-zinc-900"
                  title="Detail Profil Kandidat"
                >
                  <Eye class="w-3.5 h-3.5" />
                </Button>
                <Button
                  variant="ghost"
                  size="xs"
                  @click.stop="openSendEmailModal(app)"
                  class="h-7 w-7 p-0 text-zinc-500 hover:text-zinc-900"
                  title="Kirim Notifikasi (Email / WhatsApp)"
                >
                  <Send class="w-3.5 h-3.5" />
                </Button>
              </div>
            </TableCell>
          </TableRow>
          <TableRow v-if="!filteredApplications.length">
            <TableCell colspan="8" class="py-12 text-center text-xs text-zinc-400">
              Tidak ada data kandidat pelamar yang sesuai kriteria filter.
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <!-- KANBAN BOARD VIEW -->
    <div v-else class="flex gap-4 overflow-x-auto pb-4 no-scrollbar min-h-[calc(100vh-280px)] items-start select-none">
      <div
        v-for="stage in stages"
        :key="stage.id"
        class="w-80 shrink-0 bg-zinc-100/60 border rounded-xl flex flex-col max-h-[calc(100vh-280px)] transition-all shadow-2xs"
        :class="dragOverStageId === stage.id ? 'border-zinc-950 bg-zinc-100 ring-2 ring-zinc-950/10' : 'border-zinc-200'"
        @dragover.prevent="handleDragOver(stage.id)"
        @dragleave="handleDragLeave(stage.id)"
        @drop.prevent="handleDrop(stage.id, $event)"
      >
        <!-- Column Header -->
        <div class="p-3 border-b border-zinc-200 bg-white rounded-t-xl flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="w-2.5 h-2.5 rounded-full" :style="{ backgroundColor: stage.color || '#71717a' }"></span>
            <span class="text-xs font-semibold text-zinc-900 tracking-tight">{{ stage.name }}</span>
          </div>
          <Badge variant="secondary" class="text-[10px] px-2 py-0">
            {{ getStageApplications(stage.id).length }}
          </Badge>
        </div>

        <!-- Kanban Cards List -->
        <div class="p-2.5 space-y-2.5 overflow-y-auto flex-1 no-scrollbar">
          <div
            v-for="app in getStageApplications(stage.id)"
            :key="app.id"
            class="bg-white p-3.5 rounded-lg border border-zinc-200 shadow-2xs hover:shadow-xs transition-all cursor-grab active:cursor-grabbing hover:border-zinc-300 group"
            draggable="true"
            @dragstart="handleDragStart(app, $event)"
            @dragend="handleDragEnd"
            @click="openDetail(app)"
          >
            <!-- Top: Candidate Name & Match Pill -->
            <div class="flex items-start justify-between gap-2">
              <div class="flex items-center gap-2 min-w-0">
                <div class="w-7 h-7 rounded-full bg-zinc-100 flex items-center justify-center shrink-0 border border-zinc-200 text-zinc-500 overflow-hidden relative">
                  <img
                    v-if="app.photo_url"
                    :src="app.photo_url"
                    :alt="app.full_name"
                    class="w-full h-full object-cover"
                    loading="lazy"
                    @error="(e) => e.target.style.display = 'none'"
                  />
                  <span v-else class="text-[9px] font-bold text-zinc-600 uppercase">{{ getInitials(app.full_name) }}</span>
                </div>
                <div class="min-w-0">
                  <h4 class="font-semibold text-xs text-zinc-900 group-hover:text-blue-600 transition-colors truncate">
                    {{ app.full_name }}
                  </h4>
                  <p class="text-[11px] text-zinc-400 truncate">{{ app.email }}</p>
                </div>
              </div>

              <Badge
                v-if="app.ai_match_score !== null && app.ai_match_score !== undefined"
                :variant="app.ai_match_score >= 75 ? 'success' : (app.ai_match_score >= 50 ? 'warning' : 'secondary')"
                class="text-[9px] px-1.5 py-0 shrink-0 font-medium cursor-pointer"
                @click.stop="openAnalysisModal(app)"
                title="Klik untuk melihat hasil analisis kualifikasi"
              >
                {{ app.ai_match_score }}%
              </Badge>
            </div>

            <!-- Role / Details Subtitle -->
            <div v-if="!activeJobId && app.job_posting?.title" class="text-[11px] font-medium text-zinc-600 mt-2 pt-2 border-t border-zinc-100 line-clamp-1">
              {{ app.job_posting.title }}
            </div>

            <!-- Card Footer -->
            <div class="flex items-center justify-between text-[10px] text-zinc-400 mt-2 pt-2 border-t border-zinc-100">
              <span>{{ app.created_at }}</span>
              <span class="px-1.5 py-0.5 rounded bg-zinc-50 text-zinc-600 border border-zinc-200 font-medium">
                {{ app.source || 'Portal' }}
              </span>
            </div>
          </div>

          <!-- Empty State in Column -->
          <div
            v-if="!getStageApplications(stage.id).length"
            class="py-8 text-center text-xs text-zinc-400 border border-dashed border-zinc-200 rounded-lg flex flex-col items-center justify-center gap-1"
          >
            <span class="text-zinc-300 text-sm">&empty;</span>
            <span>Belum ada kandidat</span>
          </div>
        </div>
      </div>

      <!-- Ditolak Kanban Column -->
      <div
        class="w-80 shrink-0 bg-rose-50/30 border rounded-xl flex flex-col max-h-[calc(100vh-280px)] transition-all shadow-2xs"
        :class="dragOverStageId === 'rejected' ? 'border-rose-500 bg-rose-100/40 ring-2 ring-rose-400/20' : 'border-rose-200/70'"
        @dragover.prevent="handleDragOver('rejected')"
        @dragleave="handleDragLeave('rejected')"
        @drop.prevent="handleDrop('rejected', $event)"
      >
        <!-- Column Header -->
        <div class="p-3 border-b border-rose-200/70 bg-white rounded-t-xl flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span>
            <span class="text-xs font-semibold text-rose-900 tracking-tight">Ditolak</span>
          </div>
          <Badge variant="destructive" class="text-[10px] px-2 py-0">
            {{ rejectedCandidateCount }}
          </Badge>
        </div>

        <!-- Kanban Cards List for Rejected -->
        <div class="p-2.5 space-y-2.5 overflow-y-auto flex-1 no-scrollbar">
          <div
            v-for="app in rejectedApplications"
            :key="app.id"
            class="bg-white p-3.5 rounded-lg border border-rose-200/80 shadow-2xs hover:shadow-xs transition-all cursor-grab active:cursor-grabbing hover:border-rose-300 group opacity-90"
            draggable="true"
            @dragstart="handleDragStart(app, $event)"
            @dragend="handleDragEnd"
            @click="openDetail(app)"
          >
            <!-- Top: Candidate Name & Match Pill -->
            <div class="flex items-start justify-between gap-2">
              <div class="flex items-center gap-2 min-w-0">
                <div class="w-6 h-6 rounded-full bg-rose-50 flex items-center justify-center shrink-0 border border-rose-200 text-rose-500">
                  <User class="w-3 h-3" />
                </div>
                <div class="min-w-0">
                  <h4 class="font-semibold text-xs text-zinc-900 group-hover:text-rose-600 transition-colors truncate">
                    {{ app.full_name }}
                  </h4>
                  <p class="text-[11px] text-zinc-400 truncate">{{ app.email }}</p>
                </div>
              </div>

              <Badge variant="destructive" class="text-[9px] px-1.5 py-0 shrink-0 font-medium">
                Ditolak
              </Badge>
            </div>

            <!-- Role / Details Subtitle -->
            <div v-if="!activeJobId && app.job_posting?.title" class="text-[11px] font-medium text-zinc-600 mt-2 pt-2 border-t border-zinc-100 line-clamp-1">
              {{ app.job_posting.title }}
            </div>

            <!-- Card Footer -->
            <div class="flex items-center justify-between text-[10px] text-zinc-400 mt-2 pt-2 border-t border-zinc-100">
              <span>{{ app.created_at }}</span>
              <span class="px-1.5 py-0.5 rounded bg-rose-50 text-rose-600 border border-rose-100 font-medium">
                {{ app.source || 'Portal' }}
              </span>
            </div>
          </div>

          <!-- Empty State in Column -->
          <div
            v-if="!rejectedApplications.length"
            class="py-8 text-center text-xs text-zinc-400 border border-dashed border-rose-200/70 rounded-lg flex flex-col items-center justify-center gap-1"
          >
            <span class="text-rose-300 text-sm">&empty;</span>
            <span>Tidak ada kandidat ditolak</span>
          </div>
        </div>
      </div>
    </div>

    <!-- CANDIDATE ATS DETAIL MODAL -->
    <div
      v-if="selectedApp"
      class="fixed inset-0 z-[100] bg-black/60 backdrop-blur-xs flex items-center justify-center p-3 sm:p-6"
      @click.self="selectedApp = null"
    >
      <div class="bg-white rounded-xl border border-zinc-200 w-full max-w-6xl h-[90vh] max-h-[900px] flex flex-col shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
        
        <!-- Top Header Bar -->
        <div class="px-6 py-3.5 border-b border-zinc-200 bg-white flex items-center justify-between shrink-0">
          <div class="flex items-center gap-3.5 min-w-0">
            <Button
              variant="ghost"
              size="xs"
              @click="selectedApp = null"
              class="h-8 w-8 p-0 text-zinc-500 hover:text-zinc-900 shrink-0"
              title="Kembali"
            >
              <ArrowLeft class="w-4 h-4" />
            </Button>
            <div class="w-10 h-10 rounded-full bg-zinc-900 text-white flex items-center justify-center font-semibold text-xs shrink-0 overflow-hidden border border-zinc-200 relative">
              <img
                v-if="selectedApp.photo_url"
                :src="selectedApp.photo_url"
                :alt="selectedApp.full_name"
                class="w-full h-full object-cover"
                @error="(e) => e.target.style.display = 'none'"
              />
              <span v-else>{{ getInitials(selectedApp.full_name) }}</span>
            </div>
            <div class="min-w-0">
              <h3 class="text-sm font-semibold text-zinc-900 truncate">{{ selectedApp.full_name }}</h3>
              <p class="text-[11px] text-zinc-500 truncate">{{ selectedApp.email || '-' }} &bull; {{ selectedApp.whatsapp_number || selectedApp.phone || '-' }}</p>
            </div>
          </div>

          <!-- Top Right Actions -->
          <div class="flex items-center gap-2 shrink-0">
            <div class="flex items-center gap-1.5">
              <span class="text-xs font-medium text-zinc-500">Tahap:</span>
              <div class="relative">
                <select
                  :value="selectedApp.status === 'rejected' ? 'rejected' : (selectedApp.current_stage_id || selectedApp.stage?.id || 1)"
                  @change="handleStageChange(selectedApp, $event.target.value)"
                  class="h-8 text-xs font-medium rounded-md pl-3 pr-8 bg-white border border-zinc-200 text-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-950 appearance-none cursor-pointer transition-colors shadow-2xs"
                  :class="{ 'text-rose-600 font-semibold border-rose-300 bg-rose-50/50': selectedApp.status === 'rejected' }"
                >
                  <option v-for="stg in stages" :key="stg.id" :value="stg.id">
                    {{ stg.name }}
                  </option>
                  <option value="rejected" class="text-rose-600 font-semibold">
                    Ditolak
                  </option>
                </select>
                <ChevronDown class="w-3.5 h-3.5 text-zinc-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
              </div>
            </div>

            <Button
              variant="default"
              size="sm"
              @click="openSendEmailModal(selectedApp)"
              class="h-8 bg-zinc-900 hover:bg-zinc-800 text-white gap-1.5 text-xs"
            >
              <Send class="w-3.5 h-3.5" />
              <span>Kirim Notifikasi</span>
            </Button>

            <a
              v-if="selectedApp.resume_url"
              :href="selectedApp.resume_url"
              target="_blank"
              class="h-8 px-3 rounded-md border border-zinc-200 hover:bg-zinc-50 text-xs font-medium text-zinc-700 inline-flex items-center gap-1.5 transition-colors"
            >
              <span>Buka CV</span>
              <ExternalLink class="w-3.5 h-3.5 text-zinc-400" />
            </a>

            <Button
              variant="ghost"
              size="xs"
              @click="selectedApp = null"
              class="h-8 w-8 p-0 text-zinc-400 hover:text-zinc-900"
              title="Tutup"
            >
              <X class="w-4 h-4" />
            </Button>
          </div>
        </div>

        <!-- Main Body: 2 Columns Layout -->
        <div class="flex-1 flex flex-col lg:flex-row overflow-hidden min-h-0">
          
          <!-- LEFT COLUMN: Candidate Data & Evaluation (46% Width) -->
          <div
            class="w-full lg:w-[46%] p-6 overflow-y-auto no-scrollbar border-r border-zinc-200 bg-white space-y-5"
          >
            <!-- Evaluasi Kualifikasi -->
            <div class="rounded-xl bg-zinc-50 border border-zinc-200 p-4 space-y-3">
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                  <ClipboardCheck class="w-4 h-4 text-zinc-900" />
                  <h3 class="text-xs font-semibold text-zinc-900 tracking-tight">Evaluasi Kualifikasi Pelamar</h3>
                </div>
                <Badge
                  v-if="selectedApp.ai_match_score !== null && selectedApp.ai_match_score !== undefined"
                  :variant="selectedApp.ai_match_score >= 75 ? 'success' : (selectedApp.ai_match_score >= 50 ? 'warning' : 'secondary')"
                  class="text-[10px] px-2 py-0.5"
                >
                  {{ selectedApp.ai_match_score }}% &bull; {{ formatAiRecommendation(selectedApp.ai_recommendation) }}
                </Badge>
                <span v-else class="text-[11px] text-zinc-400 italic">Belum dievaluasi</span>
              </div>

              <!-- Summary Text -->
              <p class="text-xs text-zinc-700 leading-relaxed font-normal bg-white p-3 rounded-lg border border-zinc-200 whitespace-pre-line shadow-2xs">
                {{ selectedApp.ai_summary || 'Evaluasi kualifikasi membandingkan kriteria posisi dengan berkas CV pelamar.' }}
              </p>

              <!-- Actions -->
              <div class="flex items-center justify-between pt-0.5 text-[11px] text-zinc-400">
                <span v-if="selectedApp.ai_analyzed_at">Diperbarui: {{ selectedApp.ai_analyzed_at }}</span>
                <span v-else></span>
                <div class="flex items-center gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="xs"
                    @click="rescreenSingleCandidate(selectedApp)"
                    :disabled="isScreening"
                    class="h-7 text-xs gap-1.5"
                  >
                    <RotateCw class="w-3 h-3" :class="{ 'animate-spin': isScreening }" />
                    <span>Evaluasi Ulang</span>
                  </Button>
                  <Button
                    type="button"
                    variant="default"
                    size="xs"
                    @click="openAnalysisModal(selectedApp)"
                    class="h-7 text-xs bg-zinc-900 hover:bg-zinc-800 text-white"
                  >
                    Detail Komparasi &rarr;
                  </Button>
                </div>
              </div>
            </div>

            <!-- Biodata Pelamar -->
            <div class="space-y-3">
              <div class="flex items-center justify-between pb-1.5 border-b border-zinc-100">
                <div class="flex items-center gap-2">
                  <User class="w-3.5 h-3.5 text-zinc-400" />
                  <h3 class="text-xs font-semibold text-zinc-900 tracking-tight">Biodata Pelamar</h3>
                </div>
                <a
                  v-if="selectedApp.photo_url"
                  :href="selectedApp.photo_url"
                  target="_blank"
                  class="text-[11px] text-blue-600 hover:underline flex items-center gap-1 font-medium"
                >
                  <span>Lihat Foto Asli</span>
                  <ExternalLink class="w-3 h-3" />
                </a>
              </div>

              <!-- Profile Photo Card & Snapshot -->
              <div class="flex items-start gap-4 p-3 rounded-lg bg-zinc-50 border border-zinc-200">
                <div class="w-20 h-24 rounded-lg bg-white border border-zinc-200 shadow-2xs overflow-hidden shrink-0 flex items-center justify-center relative">
                  <img
                    v-if="selectedApp.photo_url"
                    :src="selectedApp.photo_url"
                    :alt="selectedApp.full_name"
                    class="w-full h-full object-cover"
                    @error="(e) => e.target.style.display = 'none'"
                  />
                  <div v-else class="flex flex-col items-center justify-center text-zinc-400 p-2 text-center">
                    <User class="w-8 h-8 text-zinc-300" />
                    <span class="text-[9px] mt-1 text-zinc-400">Tanpa Foto</span>
                  </div>
                </div>
                <div class="flex-1 min-w-0 space-y-1.5 py-0.5">
                  <div class="text-xs font-bold text-zinc-900 leading-snug">{{ selectedApp.full_name }}</div>
                  <div class="text-[11px] text-zinc-500 flex items-center gap-1.5">
                    <span class="font-medium text-zinc-700">{{ selectedApp.gender || '-' }}</span>
                    <span>&bull;</span>
                    <span>{{ selectedApp.marital_status || '-' }}</span>
                  </div>
                  <div class="text-[11px] text-zinc-600">
                    <span class="text-zinc-400">Tgl Lahir:</span> {{ selectedApp.birth_date || '-' }}
                  </div>
                  <div class="text-[11px] text-zinc-600 truncate">
                    <span class="text-zinc-400">Lowongan:</span> <span class="font-medium text-zinc-800">{{ selectedApp.job_posting?.title || '-' }}</span>
                  </div>
                </div>
              </div>

              <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-xs">
                <div>
                  <span class="block text-[11px] font-medium text-zinc-400">Nama Lengkap</span>
                  <span class="font-semibold text-zinc-900 mt-0.5 block">{{ selectedApp.full_name }}</span>
                </div>
                <div>
                  <span class="block text-[11px] font-medium text-zinc-400">Jenis Kelamin</span>
                  <span class="font-medium text-zinc-800 mt-0.5 block">{{ selectedApp.gender || '-' }}</span>
                </div>
                <div>
                  <span class="block text-[11px] font-medium text-zinc-400">Email</span>
                  <a :href="`mailto:${selectedApp.email}`" class="font-medium text-blue-600 hover:underline mt-0.5 block truncate" :title="selectedApp.email">
                    {{ selectedApp.email || '-' }}
                  </a>
                </div>
                <div>
                  <span class="block text-[11px] font-medium text-zinc-400">Tanggal Lahir</span>
                  <span class="font-medium text-zinc-800 mt-0.5 block">{{ selectedApp.birth_date || '-' }}</span>
                </div>
                <div>
                  <span class="block text-[11px] font-medium text-zinc-400">No. WhatsApp</span>
                  <a v-if="selectedApp.whatsapp_number || selectedApp.phone" :href="`https://wa.me/${(selectedApp.whatsapp_number || selectedApp.phone || '').replace(/[^0-9]/g, '')}`" target="_blank" class="font-medium text-emerald-600 hover:underline mt-0.5 inline-flex items-center gap-1">
                    <span>{{ selectedApp.whatsapp_number || selectedApp.phone }}</span>
                  </a>
                  <span v-else class="text-zinc-400 mt-0.5 block">-</span>
                </div>
                <div>
                  <span class="block text-[11px] font-medium text-zinc-400">Status Pernikahan</span>
                  <span class="font-medium text-zinc-800 mt-0.5 block">{{ selectedApp.marital_status || '-' }}</span>
                </div>
                <div>
                  <span class="block text-[11px] font-medium text-zinc-400">Sumber Pelamar</span>
                  <span class="font-medium text-zinc-800 mt-0.5 block">{{ selectedApp.source || 'Website' }}</span>
                </div>
                <div>
                  <span class="block text-[11px] font-medium text-zinc-400">No. Telepon Aktif</span>
                  <span class="font-medium text-zinc-800 mt-0.5 block">{{ selectedApp.active_phone || selectedApp.phone || '-' }}</span>
                </div>
              </div>
            </div>

            <!-- Alamat & Domisili -->
            <div class="space-y-3 pt-1">
              <div class="flex items-center gap-2 pb-1.5 border-b border-zinc-100">
                <MapPin class="w-3.5 h-3.5 text-zinc-400" />
                <h3 class="text-xs font-semibold text-zinc-900 tracking-tight">Alamat &amp; Domisili</h3>
              </div>

              <div class="space-y-2.5 text-xs">
                <div>
                  <span class="block text-[11px] font-medium text-zinc-400">Alamat KTP</span>
                  <p class="font-normal text-zinc-700 mt-0.5 leading-relaxed bg-zinc-50 p-2.5 rounded-lg border border-zinc-200">
                    {{ selectedApp.address_ktp || '-' }}
                  </p>
                </div>
                <div v-if="selectedApp.address_domicile && selectedApp.address_domicile !== selectedApp.address_ktp">
                  <span class="block text-[11px] font-medium text-zinc-400">Alamat Domisili</span>
                  <p class="font-normal text-zinc-700 mt-0.5 leading-relaxed bg-zinc-50 p-2.5 rounded-lg border border-zinc-200">
                    {{ selectedApp.address_domicile }}
                  </p>
                </div>
              </div>
            </div>

            <!-- Kontak Darurat -->
            <div class="space-y-3 pt-1">
              <div class="flex items-center gap-2 pb-1.5 border-b border-zinc-100">
                <Phone class="w-3.5 h-3.5 text-zinc-400" />
                <h3 class="text-xs font-semibold text-zinc-900 tracking-tight">Kontak Darurat</h3>
              </div>

              <div class="p-3 bg-zinc-50 rounded-xl border border-zinc-200 grid grid-cols-3 gap-3 text-xs">
                <div>
                  <span class="block text-[11px] font-medium text-zinc-400">Nama</span>
                  <span class="font-semibold text-zinc-800 mt-0.5 block">{{ selectedApp.emergency_contact_name || '-' }}</span>
                </div>
                <div>
                  <span class="block text-[11px] font-medium text-zinc-400">Hubungan</span>
                  <span class="font-medium text-zinc-700 mt-0.5 block">{{ selectedApp.emergency_contact_relation || '-' }}</span>
                </div>
                <div>
                  <span class="block text-[11px] font-medium text-zinc-400">No. Kontak</span>
                  <span class="font-medium text-zinc-700 mt-0.5 block font-mono">{{ selectedApp.emergency_contact_phone || '-' }}</span>
                </div>
              </div>
            </div>

          </div>

          <!-- RIGHT COLUMN: CV / Document Viewer (54% Width) -->
          <div class="w-full lg:w-[54%] bg-zinc-50/50 p-6 flex flex-col h-full overflow-hidden">
            
            <div class="flex items-center justify-between mb-3 shrink-0">
              <h3 class="text-xs font-semibold text-zinc-800 tracking-tight flex items-center gap-2">
                <FileText class="w-3.5 h-3.5 text-zinc-400" />
                <span>Pratinjau Dokumen CV Pelamar</span>
              </h3>
              <div class="flex items-center gap-2">
                <a
                  v-if="selectedApp.resume_url"
                  :href="selectedApp.resume_url"
                  target="_blank"
                  class="text-xs font-medium text-zinc-700 hover:text-zinc-950 inline-flex items-center gap-1 bg-white px-2.5 py-1 rounded-md border border-zinc-200 shadow-2xs transition-colors"
                >
                  <span>Unduh Berkas</span>
                  <ExternalLink class="w-3 h-3" />
                </a>
                <span v-else class="text-[11px] font-medium text-zinc-400 flex items-center gap-1.5">
                  <span class="w-1.5 h-1.5 rounded-full bg-zinc-300 inline-block"></span>
                  Portal Karir OceanSpace
                </span>
              </div>
            </div>

            <!-- Embedded PDF Document Container -->
            <div class="flex-1 rounded-xl border border-zinc-200 bg-white overflow-hidden shadow-2xs flex flex-col relative">
              <iframe
                v-if="selectedApp.resume_url"
                :src="selectedApp.resume_url"
                class="w-full h-full border-0"
              ></iframe>
              <div v-else class="flex-1 flex flex-col items-center justify-center text-center p-8 bg-zinc-50/40">
                <div class="w-12 h-12 rounded-xl bg-white border border-zinc-200 flex items-center justify-center shadow-2xs mb-3 text-zinc-400">
                  <FileText class="w-6 h-6 text-zinc-400" />
                </div>
                <p class="text-xs font-semibold text-zinc-900">Menunggu Berkas CV dari OceanSpace</p>
                <p class="text-[11px] text-zinc-400 mt-1 max-w-sm">
                  Berkas CV akan otomatis terlampir saat kandidat melamar melalui portal karir OceanSpace.
                </p>
              </div>
            </div>

          </div>

        </div>
      </div>
    </div>

    <!-- HASIL EVALUASI KUALIFIKASI & PERSYARATAN MODAL -->
    <div
      v-if="analysisModalApp"
      class="fixed inset-0 z-[110] bg-black/60 backdrop-blur-xs flex items-center justify-center p-4"
      @click.self="analysisModalApp = null"
    >
      <div class="bg-white rounded-xl border border-zinc-200 w-full max-w-2xl shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-100 flex flex-col max-h-[90vh]">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-zinc-200 flex items-center justify-between bg-white shrink-0">
          <div class="flex items-center gap-2.5">
            <div class="w-8 h-8 rounded-lg bg-zinc-100 flex items-center justify-center text-zinc-900 shrink-0">
              <ClipboardCheck class="w-4 h-4" />
            </div>
            <div>
              <h3 class="text-sm font-semibold text-zinc-900">Detail Komparasi Kualifikasi</h3>
              <p class="text-xs text-zinc-500 mt-0.5">{{ analysisModalApp.full_name }} &bull; {{ analysisModalApp.job_posting?.title || 'Posisi Lowongan' }}</p>
            </div>
          </div>
          <Button
            variant="ghost"
            size="xs"
            @click="analysisModalApp = null"
            class="h-8 w-8 p-0 text-zinc-400 hover:text-zinc-900"
          >
            <X class="w-4 h-4" />
          </Button>
        </div>

        <!-- Modal Body: Clean Table Style & Analysis Report -->
        <div class="p-6 space-y-4 text-xs overflow-y-auto no-scrollbar flex-1">
          <div class="border border-zinc-200 rounded-lg overflow-hidden shadow-2xs">
            <table class="w-full text-left text-xs">
              <tbody class="divide-y divide-zinc-200">
                <tr>
                  <td class="py-2.5 px-4 text-zinc-500 font-medium w-40 bg-zinc-50">Nama Pelamar</td>
                  <td class="py-2.5 px-4 text-zinc-900 font-semibold">{{ analysisModalApp.full_name }}</td>
                </tr>
                <tr>
                  <td class="py-2.5 px-4 text-zinc-500 font-medium bg-zinc-50">Posisi yang Dilamar</td>
                  <td class="py-2.5 px-4 text-zinc-900 font-medium">{{ analysisModalApp.job_posting?.title || '-' }}</td>
                </tr>
                <tr>
                  <td class="py-2.5 px-4 text-zinc-500 font-medium bg-zinc-50">Kesesuaian Kualifikasi</td>
                  <td class="py-2.5 px-4">
                    <Badge
                      :variant="analysisModalApp.ai_match_score >= 75 ? 'success' : (analysisModalApp.ai_match_score >= 50 ? 'warning' : 'secondary')"
                      class="text-xs font-semibold px-2.5 py-0.5"
                    >
                      {{ analysisModalApp.ai_match_score }}% Match &bull; {{ formatAiRecommendation(analysisModalApp.ai_recommendation) }}
                    </Badge>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Catatan / Rangkuman Evaluasi Komparatif -->
          <div class="space-y-2">
            <div class="flex items-center justify-between">
              <h4 class="text-xs font-semibold text-zinc-900 flex items-center gap-1.5">
                <FileText class="w-3.5 h-3.5 text-zinc-500" />
                <span>Rangkuman Evaluasi Komparasi CV vs Kualifikasi</span>
              </h4>
              <Badge variant="navy" class="text-[9px] px-1.5 py-0">
                AI &amp; ATS Screening
              </Badge>
            </div>
            
            <div class="p-4 bg-zinc-50 rounded-lg border border-zinc-200 text-zinc-700 leading-relaxed text-xs whitespace-pre-line shadow-2xs">
              {{ analysisModalApp.ai_summary || 'Kandidat memiliki kualifikasi yang relevan dengan persyaratan posisi lowongan ini.' }}
            </div>
            
            <div v-if="analysisModalApp.ai_analyzed_at" class="text-[11px] text-zinc-400 text-right">
              Terakhir dievaluasi: {{ analysisModalApp.ai_analyzed_at }}
            </div>
          </div>
        </div>

        <!-- Modal Footer -->
        <div class="px-6 py-3.5 border-t border-zinc-200 bg-zinc-50 flex items-center justify-between shrink-0">
          <Button
            variant="outline"
            size="sm"
            @click="rescreenSingleCandidate(analysisModalApp)"
            :disabled="isScreening"
            class="h-8 text-xs gap-1.5"
          >
            <RotateCw class="w-3 h-3" :class="{ 'animate-spin': isScreening }" />
            <span>Evaluasi Ulang CV</span>
          </Button>

          <div class="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              @click="analysisModalApp = null"
              class="h-8 text-xs"
            >
              Tutup
            </Button>
            <Button
              variant="default"
              size="sm"
              @click="openDetail(analysisModalApp); analysisModalApp = null"
              class="h-8 text-xs bg-zinc-900 hover:bg-zinc-800 text-white"
            >
              Buka Detail Profil
            </Button>
          </div>
        </div>
      </div>
    </div>

    <!-- SEND NOTIFICATION MODAL (Email & WhatsApp, Single & Bulk) -->
    <div
      v-if="sendEmailModalApp"
      class="fixed inset-0 z-[120] bg-black/60 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto"
      @click.self="closeNotificationModal"
    >
      <!-- Modal Notification: Unified, Modern, Cohesive Design -->
      <div class="bg-white rounded-2xl border border-zinc-200/90 w-full max-w-4xl shadow-2xl overflow-hidden flex flex-col my-4 max-h-[92vh]">
        <!-- 1. Modal Header -->
        <div class="px-6 py-4 border-b border-zinc-100 flex items-center justify-between bg-white sticky top-0 z-20">
          <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-[#0c2340] text-white flex items-center justify-center shadow-xs shrink-0">
              <Send class="w-4 h-4" />
            </div>
            <div>
              <div class="flex items-center gap-2">
                <h3 class="text-sm font-bold text-zinc-900">
                  {{ isBulkMode ? 'Kirim Notifikasi Massal' : 'Kirim Undangan / Notifikasi' }}
                </h3>
                <span v-if="isBulkMode" class="px-2 py-0.5 rounded-full text-[10.5px] font-semibold bg-blue-50 text-blue-700 border border-blue-200/60">
                  {{ selectedAppIds.length }} Pelamar
                </span>
                <span v-else class="px-2 py-0.5 rounded-full text-[10.5px] font-semibold bg-zinc-100 text-zinc-700 border border-zinc-200">
                  1 Penerima
                </span>
              </div>
              <p class="text-xs text-zinc-500 mt-0.5">
                <template v-if="isBulkMode">
                  Pesan otomatis dipersonalisasi sesuai profil & jadwal masing-masing pelamar
                </template>
                <template v-else>
                  Penerima: <strong class="text-zinc-800">{{ sendEmailModalApp.full_name }}</strong>
                  <span v-if="sendEmailModalApp.email" class="text-zinc-400"> ({{ sendEmailModalApp.email }})</span>
                  <span v-if="sendEmailModalApp.whatsapp_number || sendEmailModalApp.phone" class="text-emerald-600 font-medium ml-1.5">• WA: {{ sendEmailModalApp.whatsapp_number || sendEmailModalApp.phone }}</span>
                </template>
              </p>
            </div>
          </div>

          <button
            type="button"
            @click="closeNotificationModal"
            class="w-8 h-8 rounded-lg flex items-center justify-center text-zinc-400 hover:text-zinc-700 hover:bg-zinc-100 transition-colors cursor-pointer"
            title="Tutup"
          >
            <X class="w-4 h-4" />
          </button>
        </div>

        <!-- 2. Modal Body (Scrollable with clean spacing) -->
        <div class="p-6 space-y-4 text-xs overflow-y-auto flex-1">
          
          <!-- Section 1: Saluran & Waktu Pengiriman -->
          <div class="border border-zinc-200/80 rounded-xl p-4 bg-zinc-50/50 space-y-3">
            <div class="flex items-center justify-between">
              <span class="text-xs font-bold text-zinc-900 flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-[#0c2340]"></span>
                Saluran & Waktu Pengiriman
              </span>
              <!-- Kirim: Langsung vs Jadwalkan (Segmented Switch) -->
              <div class="inline-flex p-0.5 bg-zinc-200/70 rounded-lg text-[11px] font-medium">
                <button
                  type="button"
                  @click="sendType = 'immediate'"
                  :class="[
                    'px-2.5 py-1 rounded-md transition-all cursor-pointer select-none',
                    sendType === 'immediate' ? 'bg-white text-zinc-900 shadow-2xs font-semibold' : 'text-zinc-600 hover:text-zinc-900'
                  ]"
                >
                  ⚡ Langsung
                </button>
                <button
                  type="button"
                  @click="sendType = 'scheduled'"
                  :class="[
                    'px-2.5 py-1 rounded-md transition-all cursor-pointer select-none',
                    sendType === 'scheduled' ? 'bg-white text-zinc-900 shadow-2xs font-semibold' : 'text-zinc-600 hover:text-zinc-900'
                  ]"
                >
                  ⏰ Jadwalkan
                </button>
              </div>
            </div>

            <!-- Delivery Channel Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
              <!-- Card Email -->
              <label 
                :class="[
                  'relative border rounded-xl p-3 flex items-start gap-3 cursor-pointer transition-all select-none',
                  selectedChannels.includes('email') 
                    ? 'border-[#0c2340] bg-white shadow-2xs' 
                    : 'border-zinc-200 bg-white/70 hover:border-zinc-300'
                ]"
              >
                <input
                  type="checkbox"
                  value="email"
                  v-model="selectedChannels"
                  class="sr-only"
                />
                <div 
                  :class="[
                    'w-4 h-4 rounded mt-0.5 flex items-center justify-center border transition-colors shrink-0',
                    selectedChannels.includes('email') ? 'bg-[#0c2340] border-[#0c2340] text-white' : 'border-zinc-300 bg-white'
                  ]"
                >
                  <CheckSquare v-if="selectedChannels.includes('email')" class="w-3.5 h-3.5" />
                </div>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-1.5">
                    <Mail class="w-3.5 h-3.5 text-blue-600 shrink-0" />
                    <span class="font-bold text-zinc-900 text-xs">Email (Surat Resmi)</span>
                  </div>
                  <p class="text-[11px] text-zinc-500 mt-0.5 leading-snug">
                    Format surat HTML resmi dengan lampiran & logo perusahaan.
                  </p>
                </div>
              </label>

              <!-- Card WhatsApp -->
              <label 
                :class="[
                  'relative border rounded-xl p-3 flex items-start gap-3 cursor-pointer transition-all select-none',
                  selectedChannels.includes('whatsapp') 
                    ? 'border-emerald-600 bg-white shadow-2xs' 
                    : 'border-zinc-200 bg-white/70 hover:border-zinc-300'
                ]"
              >
                <input
                  type="checkbox"
                  value="whatsapp"
                  v-model="selectedChannels"
                  class="sr-only"
                />
                <div 
                  :class="[
                    'w-4 h-4 rounded mt-0.5 flex items-center justify-center border transition-colors shrink-0',
                    selectedChannels.includes('whatsapp') ? 'bg-emerald-600 border-emerald-600 text-white' : 'border-zinc-300 bg-white'
                  ]"
                >
                  <CheckSquare v-if="selectedChannels.includes('whatsapp')" class="w-3.5 h-3.5" />
                </div>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 text-[#25D366] shrink-0" fill="currentColor" viewBox="0 0 24 24">
                      <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/>
                    </svg>
                    <span class="font-bold text-zinc-900 text-xs">WhatsApp Message</span>
                  </div>
                  <p class="text-[11px] text-zinc-500 mt-0.5 leading-snug">
                    Pesan instan langsung ke nomor WhatsApp masing-masing pelamar.
                  </p>
                </div>
              </label>
            </div>

            <div v-if="!selectedChannels.length" class="text-rose-600 text-[11px] font-medium">
              Pilih minimal salah satu saluran pengiriman (Email atau WhatsApp).
            </div>

            <!-- WhatsApp Account Selector -->
            <div v-if="selectedChannels.includes('whatsapp')" class="pt-2 border-t border-zinc-200/60 flex items-center justify-between gap-3 flex-wrap">
              <span class="text-[11px] font-medium text-zinc-600">Nomor Pengirim WhatsApp:</span>
              <div class="relative flex-1 max-w-sm">
                <select
                  v-model="selectedWhatsappAccountId"
                  class="w-full h-8 bg-white border border-zinc-200 rounded-lg px-2.5 text-xs text-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 appearance-none pr-8 cursor-pointer"
                >
                  <option v-if="!connectedWhatsappAccounts.length" :value="null">Belum ada nomor terhubung (Scan QR di Pengaturan)</option>
                  <option v-for="account in connectedWhatsappAccounts" :key="account.id" :value="account.id">
                    {{ account.name }}{{ account.phone_number ? ` • ${account.phone_number}` : '' }}{{ account.is_default ? ' (default)' : '' }}
                  </option>
                </select>
                <ChevronDown class="w-3.5 h-3.5 text-zinc-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
              </div>
            </div>

            <!-- Scheduled Sending Inputs -->
            <div v-if="sendType === 'scheduled'" class="pt-2 border-t border-zinc-200/60 flex items-center gap-2.5 flex-wrap">
              <span class="text-[11px] font-medium text-zinc-600">Jadwal Kirim Otomatis:</span>
              <input
                type="date"
                v-model="scheduleDate"
                :min="todayDateString"
                class="h-8 px-2.5 bg-white border border-zinc-200 rounded-lg text-xs text-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 cursor-pointer"
              />
              <input
                type="time"
                v-model="scheduleTime"
                class="h-8 px-2.5 bg-white border border-zinc-200 rounded-lg text-xs text-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 cursor-pointer font-mono"
              />
              <span class="text-[11px] font-medium text-zinc-500">WIB</span>
            </div>
          </div>

          <!-- Section 2: Template Tahapan Pipeline -->
          <div class="space-y-2">
            <div class="flex items-center justify-between">
              <span class="text-xs font-bold text-zinc-900 flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-[#0c2340]"></span>
                Template Sesuai Tahapan Pipeline
              </span>
              <span class="text-[11px] text-zinc-400">Otomatis menyusun subjek dan draft pesan</span>
            </div>
            
            <!-- Modern Segmented Tabs -->
            <div class="flex flex-wrap items-center gap-1.5 p-1 bg-zinc-100 rounded-xl border border-zinc-200/80">
              <button
                v-for="tpl in pipelineTemplateTabs"
                :key="tpl.key"
                type="button"
                @click="applyEmailTemplate(tpl.key)"
                :class="[
                  'px-3 py-1.5 text-xs rounded-lg transition-all cursor-pointer select-none flex items-center gap-1.5 font-medium',
                  activeEmailTemplateKey === tpl.key
                    ? 'bg-[#0c2340] text-white shadow-xs font-semibold'
                    : 'bg-transparent text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/60'
                ]"
              >
                <span :class="activeEmailTemplateKey === tpl.key ? 'text-zinc-300' : 'text-zinc-400 text-[10px] font-mono'">{{ tpl.num }}</span>
                <span>{{ tpl.label }}</span>
              </button>
            </div>
          </div>

          <!-- Section 3: Detail Sesi & Pelaksanaan (Clean Card, Modern Form) -->
          <div class="border border-zinc-200/80 rounded-xl p-4 bg-zinc-50/50 space-y-3.5">
            <div class="flex items-center justify-between">
              <span class="text-xs font-bold text-zinc-900 flex items-center gap-1.5">
                <Calendar class="w-3.5 h-3.5 text-zinc-700" />
                Detail Sesi & Pelaksanaan
              </span>

              <!-- Clean Bulk Individual Schedule Toggle -->
              <label 
                v-if="isBulkMode && selectedCandidatesList.length > 1" 
                class="inline-flex items-center gap-2 cursor-pointer select-none px-2.5 py-1 rounded-lg border border-zinc-200 bg-white hover:border-zinc-300 transition-colors shadow-2xs"
              >
                <input
                  type="checkbox"
                  v-model="useIndividualSchedules"
                  @change="onToggleIndividualSchedules"
                  class="rounded border-zinc-300 text-zinc-900 accent-zinc-900 focus:ring-0 w-3.5 h-3.5 cursor-pointer"
                />
                <span class="text-[11.5px] font-semibold text-zinc-800">Atur jam berbeda tiap pelamar</span>
              </label>
            </div>

            <!-- Single Global Schedule Input (when individual is NOT enabled) -->
            <div v-if="!useIndividualSchedules" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-[11px] font-medium text-zinc-700 mb-1">Jadwal / Waktu Pelaksanaan</label>
                <div class="relative">
                  <Clock class="w-3.5 h-3.5 text-zinc-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                  <Input
                    type="text"
                    v-model="emailForm.schedule"
                    placeholder="Contoh: Selasa, 8 September 2026 pukul 09:00 WIB"
                    class="h-8 pl-8 text-xs bg-white"
                  />
                </div>
              </div>

              <div>
                <label class="block text-[11px] font-medium text-zinc-700 mb-1">Lokasi / Media</label>
                <div class="relative">
                  <MapPin class="w-3.5 h-3.5 text-zinc-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                  <Input
                    type="text"
                    v-model="emailForm.venue_or_method"
                    placeholder="Contoh: Online (Google Meet) / Ruang Rapat Lt. 2"
                    class="h-8 pl-8 text-xs bg-white"
                  />
                </div>
              </div>
            </div>

            <!-- Individual Schedule Table (when enabled in bulk mode) -->
            <div v-else class="space-y-3">
              <div>
                <label class="block text-[11px] font-medium text-zinc-700 mb-1">Lokasi / Media</label>
                <div class="relative">
                  <MapPin class="w-3.5 h-3.5 text-zinc-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                  <Input
                    type="text"
                    v-model="emailForm.venue_or_method"
                    placeholder="Contoh: Online (Google Meet) / Ruang Rapat Lt. 2"
                    class="h-8 pl-8 text-xs bg-white"
                  />
                </div>
              </div>

              <!-- Sleek Candidate Schedule Table -->
              <div class="border border-zinc-200 rounded-xl overflow-hidden bg-white shadow-2xs">
                <div class="bg-zinc-100/70 border-b border-zinc-200 px-3 py-2 flex items-center justify-between text-[11px] font-semibold text-zinc-700">
                  <span>Nama Pelamar ({{ selectedCandidatesList.length }})</span>
                  <span>Jadwal / Jam Khusus Pelamar</span>
                </div>
                <div class="divide-y divide-zinc-100 max-h-56 overflow-y-auto">
                  <div
                    v-for="(cand, idx) in selectedCandidatesList"
                    :key="cand.id"
                    class="px-3 py-2 flex items-center justify-between gap-3 text-xs hover:bg-zinc-50/60 transition-colors"
                  >
                    <div class="flex items-center gap-2 min-w-0 flex-1">
                      <div class="w-5 h-5 rounded-full bg-zinc-800 text-white flex items-center justify-center font-bold text-[10px] shrink-0">
                        {{ idx + 1 }}
                      </div>
                      <div class="truncate">
                        <div class="font-semibold text-zinc-900 truncate">{{ cand.full_name }}</div>
                        <div class="text-[10px] text-zinc-400 truncate">{{ cand.phone || cand.whatsapp_number || cand.email }}</div>
                      </div>
                    </div>
                    <div class="w-60 shrink-0">
                      <Input
                        type="text"
                        v-if="candidateSchedules[cand.id]"
                        v-model="candidateSchedules[cand.id].schedule"
                        :placeholder="`Jam ${cand.full_name}`"
                        class="h-7 text-xs bg-zinc-50/50 border-zinc-200 focus:bg-white font-medium"
                      />
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Optional Access Link & Special Note -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
              <div>
                <label class="block text-[11px] font-medium text-zinc-700 mb-1">Tautan Akses (Opsional)</label>
                <div class="relative">
                  <Link2 class="w-3.5 h-3.5 text-zinc-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                  <Input
                    type="url"
                    v-model="emailForm.action_url"
                    placeholder="https://meet.google.com/..."
                    class="h-8 pl-8 font-mono text-xs bg-white"
                  />
                </div>
              </div>

              <div>
                <label class="block text-[11px] font-medium text-zinc-700 mb-1">Catatan Tambahan (Opsional)</label>
                <div class="relative">
                  <FileText class="w-3.5 h-3.5 text-zinc-400 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                  <Input
                    type="text"
                    v-model="emailForm.special_note"
                    placeholder="Contoh: Hadir 10 menit lebih awal..."
                    class="h-8 pl-8 text-xs bg-white"
                  />
                </div>
              </div>
            </div>
          </div>

          <!-- Section 4: Draf Notifikasi (Subjek & Pesan) -->
          <div class="border border-zinc-200/80 rounded-xl p-4 bg-white space-y-3 shadow-2xs">
            <span class="text-xs font-bold text-zinc-900 flex items-center gap-1.5">
              <span class="w-1.5 h-1.5 rounded-full bg-[#0c2340]"></span>
              Draf Notifikasi
            </span>

            <!-- Subjek -->
            <div>
              <label class="block text-[11px] font-medium text-zinc-700 mb-1">Subjek Notifikasi</label>
              <Input
                type="text"
                v-model="emailForm.subject"
                placeholder="Subjek email atau ringkasan pesan..."
                class="h-8 text-xs font-semibold"
              />
            </div>

            <!-- Isi Pesan -->
            <div>
              <div class="flex items-center justify-between mb-1.5 flex-wrap gap-2">
                <label class="block text-[11px] font-medium text-zinc-700">Isi Pesan Notifikasi</label>
                
                <!-- Variable Chips -->
                <div class="flex items-center gap-1 flex-wrap">
                  <span class="text-[10px] text-zinc-400 font-medium">Sisipkan:</span>
                  <button type="button" @click="insertTag('{nama_pelamar}')" class="px-2 py-0.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-800 rounded-md text-[10.5px] font-mono cursor-pointer transition-colors">+ {nama_pelamar}</button>
                  <button type="button" @click="insertTag('{jadwal}')" class="px-2 py-0.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-800 rounded-md text-[10.5px] font-mono cursor-pointer transition-colors">+ {jadwal}</button>
                  <button type="button" @click="insertTag('{posisi}')" class="px-2 py-0.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-800 rounded-md text-[10.5px] font-mono cursor-pointer transition-colors">+ {posisi}</button>
                  <button type="button" @click="insertTag('{perusahaan}')" class="px-2 py-0.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-800 rounded-md text-[10.5px] font-mono cursor-pointer transition-colors">+ {perusahaan}</button>
                  <button type="button" @click="insertTag('{link_aksi}')" class="px-2 py-0.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-800 rounded-md text-[10.5px] font-mono cursor-pointer transition-colors">+ {link_aksi}</button>
                </div>
              </div>

              <textarea
                ref="bodyTextareaRef"
                v-model="emailForm.body_message"
                rows="6"
                @input="adjustTextareaHeight"
                class="w-full bg-zinc-50/50 hover:bg-white focus:bg-white border border-zinc-200 rounded-xl p-3 text-xs text-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 focus:border-zinc-900 leading-relaxed font-sans shadow-2xs transition-all overflow-y-hidden resize-none"
                style="min-height: 130px;"
                placeholder="Tulis pesan..."
              ></textarea>
            </div>
          </div>

          <!-- Offering Letter PDF Upload -->
          <div v-if="activeEmailTemplateKey === 'offering'" class="p-4 bg-zinc-50/80 rounded-xl border border-zinc-200/80 space-y-2">
            <div class="flex items-center justify-between">
              <label class="block font-bold text-xs text-zinc-800 flex items-center gap-1.5">
                <FileText class="w-3.5 h-3.5 text-zinc-900" />
                <span>Dokumen Lampiran Offering Letter (PDF)</span>
              </label>
              <span class="text-[10.5px] text-zinc-500">Maks. 15MB &bull; Khusus Email</span>
            </div>

            <div v-if="!emailForm.attachment" class="relative border border-dashed border-zinc-300 hover:border-zinc-400 bg-white rounded-lg p-3 text-center cursor-pointer transition-colors group">
              <input
                type="file"
                accept="application/pdf,.pdf"
                @change="handleAttachmentUpload"
                class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
              />
              <div class="flex items-center justify-center gap-2 text-zinc-500 group-hover:text-zinc-800">
                <Upload class="w-4 h-4 text-zinc-400 group-hover:text-zinc-600" />
                <span class="text-xs font-medium">Unggah file PDF Offering Letter</span>
              </div>
            </div>

            <div v-else class="flex items-center justify-between bg-white p-2.5 rounded-lg border border-zinc-200 shadow-2xs">
              <div class="flex items-center gap-2 overflow-hidden">
                <div class="w-7 h-7 rounded bg-rose-50 text-rose-600 flex items-center justify-center font-bold text-[10px] shrink-0">
                  PDF
                </div>
                <div class="truncate">
                  <div class="text-xs font-medium text-zinc-800 truncate">{{ emailForm.attachment_name }}</div>
                  <div class="text-[10px] text-zinc-400">{{ formatFileSize(emailForm.attachment?.size) }}</div>
                </div>
              </div>
              <Button
                variant="ghost"
                size="xs"
                type="button"
                @click="removeAttachment"
                class="h-7 w-7 p-0 text-zinc-400 hover:text-rose-600"
                title="Hapus Lampiran"
              >
                <X class="w-3.5 h-3.5" />
              </Button>
            </div>
          </div>
        </div>

        <!-- 3. Modal Footer -->
        <div class="px-6 py-3.5 border-t border-zinc-200 bg-zinc-50 flex items-center justify-between sticky bottom-0 z-20">
          <div class="text-xs text-zinc-500">
            <template v-if="isBulkMode">
              Siap dikirim ke <strong class="text-zinc-800">{{ selectedAppIds.length }} pelamar</strong> terpilih.
            </template>
            <template v-else>
              Penerima: <strong class="text-zinc-800">{{ sendEmailModalApp.full_name }}</strong>
            </template>
          </div>

          <div class="flex items-center gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              @click="closeNotificationModal"
              class="h-8 text-xs cursor-pointer"
            >
              Batal
            </Button>

            <Button
              type="button"
              variant="default"
              size="sm"
              @click="executeSendNotification"
              :disabled="isSendingEmail || !selectedChannels.length || (sendType === 'scheduled' && (!scheduleDate || !scheduleTime))"
              class="h-8 text-xs bg-[#0c2340] hover:bg-[#12335c] text-white gap-1.5 cursor-pointer shadow-xs font-semibold"
            >
              <RotateCw v-if="isSendingEmail" class="w-3.5 h-3.5 animate-spin" />
              <CalendarClock v-else-if="sendType === 'scheduled'" class="w-3.5 h-3.5" />
              <Send v-else class="w-3.5 h-3.5" />
              <span>
                {{ isSendingEmail ? 'Memproses...' : (sendType === 'scheduled' ? (isBulkMode ? `Jadwalkan untuk ${selectedAppIds.length} Pelamar` : 'Jadwalkan Notifikasi') : (isBulkMode ? `Kirim ke ${selectedAppIds.length} Pelamar` : 'Kirim Notifikasi')) }}
              </span>
            </Button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, onActivated, nextTick, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useRekrutmenStore } from '../stores/rekrutmen';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';
import axios from 'axios';

// Shadcn UI Components
import { Button } from '../components/ui/button';
import { Badge } from '../components/ui/badge';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '../components/ui/card';
import { Table, TableHeader, TableBody, TableHead, TableRow, TableCell } from '../components/ui/table';
import { Input } from '../components/ui/input';

import { 
  Search, ListFilter, Kanban, ArrowLeft, ExternalLink, Eye,
  CheckCircle2, AlertCircle, Mail, Phone, FileText, RefreshCw, RotateCw, User, UserCheck, ClipboardCheck,
  Link2, Users, Bell, ChevronDown, Upload, MessageSquare, Send, CheckSquare,
  Calendar, MapPin, X, Clock, CalendarClock, UserX
} from 'lucide-vue-next';

const store = useRekrutmenStore();
const route = useRoute();
const router = useRouter();

const logoUrl = '/images/logo.png';
const oceanSpaceLogoUrl = '/images/oceanspace-logo.png';
const viewMode = ref('table');
const matchFilter = ref('all');
const searchQuery = ref('');
const toastMessage = ref(null);
const toastType = ref('success');
const selectedApp = ref(null);
const analysisModalApp = ref(null);
const dragOverStageId = ref(null);
const isScreening = ref(false);

// Bulk selection state
const selectedAppIds = ref([]);
const isBulkMode = ref(false);
const selectedChannels = ref(['email', 'whatsapp']);
const whatsappAccounts = ref([]);
const selectedWhatsappAccountId = ref(null);
const connectedWhatsappAccounts = computed(() => (whatsappAccounts.value || []).filter((account) => account.is_active && account.status === 'connected'));

// Individual schedules per candidate state (for bulk notifications)
const useIndividualSchedules = ref(false);
const candidateSchedules = ref({});

const selectedCandidatesList = computed(() => {
  return selectedAppIds.value
    .map(id => applications.value.find(a => a.id === id))
    .filter(Boolean);
});

const isAllSelected = computed(() => {
  if (!filteredApplications.value.length) return false;
  return filteredApplications.value.every(app => selectedAppIds.value.includes(app.id));
});

const isSelected = (id) => selectedAppIds.value.includes(id);

const toggleSelectAll = () => {
  if (isAllSelected.value) {
    selectedAppIds.value = [];
  } else {
    selectedAppIds.value = filteredApplications.value.map(app => app.id);
  }
};

const defaultStages = [
  { id: 1, name: 'Screening CV', color: '#2563eb' },
  { id: 2, name: 'Interview HR', color: '#d97706' },
  { id: 3, name: 'Psikotes', color: '#7c3aed' },
  { id: 4, name: 'Tes Kompetensi', color: '#4f46e5' },
  { id: 5, name: 'Interview User', color: '#0284c7' },
  { id: 6, name: 'Background Check', color: '#0d9488' },
  { id: 7, name: 'Offering Letter', color: '#ea580c' },
  { id: 8, name: 'Hired', color: '#059669' }
];

let heartbeatTimer = null;

const checkHeartbeat = async () => {
  try {
    const res = await axios.post('/rekrutmen/api/notifications/heartbeat');
    if (res.data?.processed > 0) {
      await store.fetchApplications('', false).catch(() => {});
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: `${res.data.processed} Notifikasi terjadwal berhasil terkirim!`,
        showConfirmButton: false,
        timer: 4000,
        timerProgressBar: true,
      });
    }
  } catch (_) {}
};

const activeJobId = computed(() => route.query.id || route.query.job_id || null);

onMounted(() => {
  const targetId = activeJobId.value;
  store.fetchApplications(targetId ? { job_id: targetId } : '', false).catch(() => {});
  if (!store.postings?.length) {
    store.fetchPostings('', false).catch(() => {});
  }
  checkHeartbeat();
  heartbeatTimer = setInterval(checkHeartbeat, 25000);
});

onActivated(() => {
  const targetId = activeJobId.value;
  store.fetchApplications(targetId ? { job_id: targetId } : '', true).catch(() => {});
  if (!store.postings?.length) {
    store.fetchPostings('', false).catch(() => {});
  }
});

onUnmounted(() => {
  if (heartbeatTimer) clearInterval(heartbeatTimer);
});

watch(
  () => activeJobId.value,
  (newId) => {
    store.fetchApplications(newId ? { job_id: newId } : '', true).catch(() => {});
  }
);

const applications = computed(() => {
  if (activeJobId.value) {
    return (store.applications || []).filter(a =>
      String(a.job_posting_id) === String(activeJobId.value) ||
      String(a.job_posting?.id) === String(activeJobId.value)
    );
  }
  return store.applications || [];
});

const stages = computed(() => {
  if (store.stages && store.stages.length) return store.stages;
  if (store.configurations?.stages && store.configurations.stages.length) return store.configurations.stages;
  return defaultStages;
});

const activeJobTitle = computed(() => {
  if (!activeJobId.value) return null;
  const job = store.postings?.find(j => String(j.id) === String(activeJobId.value));
  if (job?.title) return job.title;
  if (store.activeJob?.title && String(store.activeJob.id) === String(activeJobId.value)) {
    return store.activeJob.title;
  }
  const app = store.applications?.find(a =>
    String(a.job_posting_id) === String(activeJobId.value) ||
    String(a.job_posting?.id) === String(activeJobId.value)
  );
  if (app?.job_posting?.title) return app.job_posting.title;
  return `ID #${activeJobId.value}`;
});

const stageFilter = ref('all');

const getStageCandidateCount = (stageId) => {
  return applications.value.filter(a => {
    if (a.status === 'rejected') return false;
    const cur = a.current_stage_id || a.stage?.id || 1;
    return String(cur) === String(stageId);
  }).length;
};

const rejectedCandidateCount = computed(() => {
  return applications.value.filter(a => a.status === 'rejected').length;
});

const recommendedCount = computed(() => applications.value.filter(a => a.ai_match_score >= 75).length);
const consideredCount = computed(() => applications.value.filter(a => a.ai_match_score >= 50 && a.ai_match_score < 75).length);
const notSuitableCount = computed(() => applications.value.filter(a => a.ai_match_score !== null && a.ai_match_score < 50).length);

const filteredApplications = computed(() => {
  let list = applications.value;

  // Filter by Stage
  if (stageFilter.value === 'rejected') {
    list = list.filter(a => a.status === 'rejected');
  } else if (stageFilter.value !== 'all') {
    list = list.filter(a => {
      if (a.status === 'rejected') return false;
      const currentStage = a.current_stage_id || a.stage?.id || 1;
      return String(currentStage) === String(stageFilter.value);
    });
  }

  // Filter by Match Score
  if (matchFilter.value === 'recommended') {
    list = list.filter(a => a.ai_match_score >= 75);
  } else if (matchFilter.value === 'considered') {
    list = list.filter(a => a.ai_match_score >= 50 && a.ai_match_score < 75);
  } else if (matchFilter.value === 'not_suitable') {
    list = list.filter(a => a.ai_match_score !== null && a.ai_match_score < 50);
  }

  if (!searchQuery.value) return list;
  const q = searchQuery.value.toLowerCase();
  return list.filter(a => 
    (a.full_name && a.full_name.toLowerCase().includes(q)) ||
    (a.email && a.email.toLowerCase().includes(q)) ||
    (a.phone && a.phone.includes(q)) ||
    (a.whatsapp_number && a.whatsapp_number.includes(q)) ||
    (a.job_posting && a.job_posting.title && a.job_posting.title.toLowerCase().includes(q)) ||
    (a.job_posting && a.job_posting.company_name && a.job_posting.company_name.toLowerCase().includes(q))
  );
});

const getStageApplications = (stageId) => {
  return filteredApplications.value.filter(a => {
    if (a.status === 'rejected') return false;
    const currentStage = a.current_stage_id || a.stage?.id || 1;
    return String(currentStage) === String(stageId);
  });
};

const rejectedApplications = computed(() => {
  return filteredApplications.value.filter(a => a.status === 'rejected');
});

const getInitials = (name) => {
  if (!name) return 'PL';
  const parts = name.trim().split(/\s+/);
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase();
  }
  return name.substring(0, 2).toUpperCase();
};

const getAiBadgeClasses = (score) => {
  if (score === null || score === undefined) return 'bg-slate-50 text-slate-500 border-slate-200';
  if (score >= 75) return 'bg-emerald-50 text-emerald-700 border-emerald-300';
  if (score >= 50) return 'bg-amber-50 text-amber-700 border-amber-300';
  return 'bg-rose-50 text-rose-700 border-rose-300';
};

const formatAiRecommendation = (rec) => {
  if (!rec) return 'Direkomendasikan';
  const lower = String(rec).toLowerCase();
  if (lower.includes('rekomend') || (lower.includes('recommend') && !lower.includes('not') && !lower.includes('un'))) return 'Direkomendasikan';
  if (lower.includes('timbang') || lower.includes('consider')) return 'Dipertimbangkan';
  if (lower.includes('kurang') || lower.includes('tidak') || lower.includes('not') || lower.includes('reject')) return 'Kurang Sesuai';
  return rec;
};

const formatStatus = (status) => {
  if (!status) return 'Dalam Proses';
  const s = String(status).toLowerCase().replace(/_/g, ' ');
  if (s.includes('in progress')) return 'Dalam Proses';
  if (s.includes('shortlist')) return 'Shortlisted';
  if (s.includes('reject')) return 'Ditolak';
  if (s.includes('hire')) return 'Diterima';
  return s.charAt(0).toUpperCase() + s.slice(1);
};

const getStatusBadge = (status) => {
  const s = String(status).toLowerCase();
  if (s.includes('hire') || s.includes('shortlist')) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
  if (s.includes('reject')) return 'bg-rose-50 text-rose-700 border-rose-200';
  return 'bg-slate-50 text-slate-700 border-slate-200';
};

const resetJobFilter = () => {
  router.push({ path: '/admin/job-applications' });
};

const isUploadingCv = ref(false);

const openDetail = (app) => {
  selectedApp.value = app;
};

const handleCvUploadForApplicant = async (event) => {
  const file = event.target.files?.[0];
  if (!file || !selectedApp.value) return;

  if (file.size > 20 * 1024 * 1024) {
    Swal.fire({
      icon: 'warning',
      title: 'Ukuran File Terlalu Besar',
      text: 'Maksimal ukuran file CV adalah 20MB.',
      confirmButtonColor: '#0c2340',
    });
    return;
  }

  const formData = new FormData();
  formData.append('cv', file);

  isUploadingCv.value = true;
  Swal.fire({
    title: 'Mengunggah CV...',
    html: '<div class="text-xs text-slate-500 mt-2">Sedang menyimpan dokumen dan melakukan evaluasi kualifikasi...</div>',
    allowOutsideClick: false,
    showConfirmButton: false,
    didOpen: () => {
      Swal.showLoading();
    }
  });

  try {
    const res = await axios.post(`/rekrutmen/api/applications/${selectedApp.value.id}/upload-cv`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });

    isUploadingCv.value = false;
    if (res.data?.success) {
      selectedApp.value.resume_path = res.data.resume_path;
      selectedApp.value.resume_url = res.data.resume_url;
      if (res.data.ai_match_score !== undefined) {
        selectedApp.value.ai_match_score = res.data.ai_match_score;
        selectedApp.value.ai_recommendation = res.data.ai_recommendation;
        selectedApp.value.ai_summary = res.data.ai_summary;
        selectedApp.value.ai_analyzed_at = res.data.ai_analyzed_at;
      }

      const found = store.applications.find(a => a.id === selectedApp.value.id);
      if (found) {
        found.resume_path = res.data.resume_path;
        found.resume_url = res.data.resume_url;
        found.has_resume = true;
        found.ai_match_score = res.data.ai_match_score;
        found.ai_recommendation = res.data.ai_recommendation;
        found.ai_summary = res.data.ai_summary;
      }

      Swal.fire({
        icon: 'success',
        title: 'CV Berhasil Diunggah!',
        text: 'Dokumen CV asli pelamar telah tersimpan dan ditampilkan di pratinjau.',
        timer: 2200,
        showConfirmButton: false,
        iconColor: '#10b981',
        customClass: {
          popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
          title: 'text-sm font-bold text-slate-900',
        }
      });
    }
  } catch (err) {
    isUploadingCv.value = false;
    Swal.fire({
      icon: 'error',
      title: 'Gagal Mengunggah CV',
      text: err.response?.data?.message || 'Terjadi kesalahan saat mengunggah file CV.',
      confirmButtonColor: '#e11d48',
    });
  }
};

const openAnalysisModal = (app) => {
  if (!app) return;
  analysisModalApp.value = app;
};

const isSyncingCvs = ref(false);

const startSyncCvs = async () => {
  isSyncingCvs.value = true;
  Swal.fire({
    title: 'Mencocokkan Berkas CV',
    html: '<div class="text-xs text-slate-500 mt-2 leading-relaxed">Sedang memindai folder storage/app/public/rekrutmen/cv dan mencocokkan dokumen ke masing-masing kandidat...</div>',
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    didOpen: () => {
      Swal.showLoading();
    }
  });

  try {
    const res = await store.syncCandidateCvs();
    isSyncingCvs.value = false;
    Swal.fire({
      icon: 'success',
      title: 'Pencocokan CV Selesai',
      html: `<div class="text-xs text-slate-600 mt-1">${res.message || 'Berkas CV berhasil dicocokkan ke kandidat.'}</div>`,
      confirmButtonText: 'Evaluasi Sekarang',
      showCancelButton: true,
      cancelButtonText: 'Tutup',
      confirmButtonColor: '#0c2340',
      cancelButtonColor: '#64748b',
    }).then((result) => {
      if (result.isConfirmed) {
        startRescreening();
      }
    });
  } catch (e) {
    isSyncingCvs.value = false;
    Swal.fire({
      icon: 'error',
      title: 'Gagal Mencocokkan CV',
      text: e.response?.data?.message || 'Terjadi kesalahan saat memproses sinkronisasi CV.',
      confirmButtonColor: '#e11d48',
    });
  }
};

const startRescreening = async () => {
  // If no job filter, show warning first
  if (!activeJobId.value) {
    const confirm = await Swal.fire({
      title: 'Evaluasi Semua Pelamar?',
      html: `<div class="text-xs text-slate-600 mt-1 leading-relaxed">
        Anda tidak sedang memfilter ke lowongan tertentu.<br><br>
        Evaluasi akan tetap berjalan untuk <b>semua pelamar</b>, namun skor kualifikasi akan dibandingkan ke masing-masing lowongan yang dilamar.<br><br>
        Untuk hasil lebih akurat, pilih lowongan terlebih dahulu dari halaman <b>Lowongan Kerja → Lihat Pelamar</b>.
      </div>`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Lanjutkan Evaluasi',
      cancelButtonText: 'Batal',
      confirmButtonColor: '#0c2340',
      cancelButtonColor: '#64748b',
      customClass: {
        popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
        title: 'text-sm font-bold text-slate-900',
      }
    });
    if (!confirm.isConfirmed) return;
  }

  const jobTitle = activeJobTitle.value;
  isScreening.value = true;
  Swal.fire({
    title: jobTitle ? `Screening AI: ${jobTitle}` : 'Evaluasi Kualifikasi',
    html: `<div class="text-xs text-slate-500 mt-2 leading-relaxed">${jobTitle ? `Mengevaluasi kandidat terhadap kualifikasi lowongan <b>${jobTitle}</b>...` : 'Menyiapkan evaluasi kandidat...'}</div>
           <div id="swal-progress" class="text-xs font-semibold text-slate-700 mt-2"></div>`,
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    customClass: {
      popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
      title: 'text-sm font-bold text-slate-900',
    },
    didOpen: () => {
      Swal.showLoading();
    }
  });

  try {
    const res = await store.batchAnalyzeWithAi(activeJobId.value, ({ processed, total }) => {
      const el = document.getElementById('swal-progress');
      if (el) {
        const pct = total ? Math.round((processed / total) * 100) : 0;
        el.textContent = `Memproses ${processed} dari ${total} kandidat (${pct}%)`;
      }
    });
    isScreening.value = false;
    Swal.fire({
      icon: 'success',
      title: 'Evaluasi Selesai',
      html: `<div class="text-xs text-slate-600 mt-1">${res.message || 'Evaluasi kualifikasi berhasil diperbarui.'}</div>`,
      timer: 3000,
      showConfirmButton: false,
      iconColor: '#10b981',
      customClass: {
        popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
        title: 'text-sm font-bold text-slate-900',
      }
    });
  } catch (e) {
    isScreening.value = false;
    Swal.fire({
      icon: 'error',
      title: 'Gagal Evaluasi',
      html: '<div class="text-xs text-slate-600 mt-1">Terjadi kesalahan saat memproses evaluasi kualifikasi.</div>',
      confirmButtonColor: '#739ec5',
      customClass: {
        popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
        title: 'text-sm font-bold text-slate-900',
        confirmButton: 'px-4 py-2 rounded-xl text-xs font-bold'
      }
    });
  }
};

const rescreenSingleCandidate = async (app) => {
  if (!app) return;
  isScreening.value = true;
  Swal.fire({
    title: 'Mengevaluasi Pelamar',
    html: `<div class="text-xs text-slate-500 mt-2 leading-relaxed">Menganalisis data kualifikasi <b>${app.full_name}</b>...</div>`,
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    customClass: {
      popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
      title: 'text-sm font-bold text-slate-900',
    },
    didOpen: () => {
      Swal.showLoading();
    }
  });

  try {
    const res = await store.analyzeCandidateWithAi(app.id);
    isScreening.value = false;
    if (res.application && selectedApp.value && selectedApp.value.id === app.id) {
      selectedApp.value = { ...selectedApp.value, ...res.application };
    }
    Swal.fire({
      icon: 'success',
      title: 'Evaluasi Berhasil',
      html: `<div class="text-xs text-slate-600 mt-1">${res.message || `Evaluasi untuk "${app.full_name}" berhasil diperbarui.`}</div>`,
      timer: 2000,
      showConfirmButton: false,
      iconColor: '#10b981',
      customClass: {
        popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
        title: 'text-sm font-bold text-slate-900',
      }
    });
  } catch (e) {
    isScreening.value = false;
    Swal.fire({
      icon: 'error',
      title: 'Gagal',
      html: '<div class="text-xs text-slate-600 mt-1">Gagal mengevaluasi data pelamar.</div>',
      confirmButtonColor: '#739ec5',
      customClass: {
        popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
        title: 'text-sm font-bold text-slate-900',
        confirmButton: 'px-4 py-2 rounded-xl text-xs font-bold'
      }
    });
  }
};

const handleDragStart = (app, event) => {
  if (event && event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(app.id));
  }
};

const handleDragEnd = () => {
  dragOverStageId.value = null;
};

const handleDragOver = (stageId) => {
  dragOverStageId.value = stageId;
};

const handleDragLeave = (stageId) => {
  if (dragOverStageId.value === stageId) {
    dragOverStageId.value = null;
  }
};

const handleDrop = async (stageId, event) => {
  dragOverStageId.value = null;
  const appId = event?.dataTransfer?.getData('text/plain');
  if (!appId) return;

  const app = store.applications.find(a => String(a.id) === String(appId));
  if (!app) return;

  if (String(stageId) === 'rejected') {
    if (app.status !== 'rejected') {
      await rejectCandidate(app);
    }
    return;
  }

  const currentStageId = app.current_stage_id || app.stage?.id || 1;
  if (app.status === 'rejected' || String(currentStageId) !== String(stageId)) {
    await moveCandidateStage(app, stageId);
  }
};

const handleStageChange = async (app, stageId) => {
  if (String(stageId) === 'rejected') {
    await rejectCandidate(app);
  } else {
    await moveCandidateStage(app, stageId);
  }
};

const rejectCandidate = async (app) => {
  try {
    const res = await store.updateApplicationStage(app.id, 'rejected');
    if (res && res.success) {
      app.status = 'rejected';
      if (selectedApp.value && String(selectedApp.value.id) === String(app.id)) {
        selectedApp.value.status = 'rejected';
      }
      toastType.value = 'success';
      toastMessage.value = `Kandidat "${app.full_name}" telah ditolak.`;
      setTimeout(() => { toastMessage.value = null; }, 3000);
    }
  } catch (e) {
    toastType.value = 'error';
    toastMessage.value = 'Gagal mengubah status kandidat menjadi Ditolak.';
  }
};

const bulkRejectSelected = async () => {
  if (!selectedAppIds.value.length) return;

  const count = selectedAppIds.value.length;

  const confirm = await Swal.fire({
    title: `Tolak ${count} Pelamar?`,
    text: `Apakah Anda yakin ingin menolak ${count} pelamar yang dipilih?`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e11d48',
    cancelButtonColor: '#64748b',
    confirmButtonText: `Ya, Tolak (${count})`,
    cancelButtonText: 'Batal',
    reverseButtons: true,
  });

  if (!confirm.isConfirmed) return;

  try {
    const res = await store.batchRejectApplications(selectedAppIds.value);
    const rejectedCount = res.count || count;

    selectedAppIds.value = [];

    // Immediately refresh data
    await store.fetchApplications('', false).catch(() => {});

    toastType.value = 'success';
    toastMessage.value = `${rejectedCount} pelamar berhasil ditolak.`;
    setTimeout(() => { toastMessage.value = null; }, 3000);

    Swal.fire({
      icon: 'success',
      title: 'Pelamar Berhasil Ditolak',
      text: `${rejectedCount} pelamar telah diubah statusnya menjadi Ditolak.`,
      timer: 2000,
      showConfirmButton: false,
    });
  } catch (err) {
    toastType.value = 'error';
    toastMessage.value = 'Gagal memproses penolakan pelamar.';
    Swal.fire({
      icon: 'error',
      title: 'Gagal',
      text: 'Terjadi kesalahan saat memproses penolakan pelamar.',
      confirmButtonColor: '#e11d48',
    });
  }
};

const moveCandidateStage = async (app, stageId) => {
  try {
    const res = await store.moveStage(app.id, stageId);
    if (res && res.success) {
      app.current_stage_id = parseInt(stageId);
      if (app.status === 'rejected') {
        app.status = 'in_progress';
      }
      const targetStage = stages.value.find(s => String(s.id) === String(stageId));
      if (targetStage) {
        app.stage = { id: targetStage.id, name: targetStage.name, color: targetStage.color };
      }
      if (selectedApp.value && String(selectedApp.value.id) === String(app.id)) {
        selectedApp.value.current_stage_id = parseInt(stageId);
        if (selectedApp.value.status === 'rejected') {
          selectedApp.value.status = 'in_progress';
        }
        if (targetStage) {
          selectedApp.value.stage = { id: targetStage.id, name: targetStage.name, color: targetStage.color };
        }
      }
      toastType.value = 'success';
      toastMessage.value = `Kandidat "${app.full_name}" dipindahkan ke tahap ${targetStage ? targetStage.name : 'baru'}.`;
      setTimeout(() => { toastMessage.value = null; }, 3000);
    }
  } catch (e) {
    toastType.value = 'error';
    toastMessage.value = 'Gagal memindahkan tahapan kandidat.';
  }
};

// Send Email Logic
const sendEmailModalApp = ref(null);
const emailTemplatesList = ref({});
const activeEmailTemplateKey = ref('psikotes');
const isSendingEmail = ref(false);

// Opsi Waktu Pengiriman (Kirim Langsung vs Jadwalkan)
const sendType = ref('immediate'); // 'immediate' | 'scheduled'
const scheduleDate = ref('');
const scheduleTime = ref('');

const todayDateString = computed(() => {
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
});

const schedulePreviewText = computed(() => {
  if (!scheduleDate.value || !scheduleTime.value) return '';
  try {
    const [year, month, day] = scheduleDate.value.split('-').map(Number);
    const [hour, minute] = scheduleTime.value.split(':').map(Number);
    const dateObj = new Date(year, month - 1, day, hour, minute);
    return dateObj.toLocaleDateString('id-ID', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    }) + `, pukul ${scheduleTime.value} WIB`;
  } catch {
    return `${scheduleDate.value} ${scheduleTime.value} WIB`;
  }
});

const emailForm = ref({
  subject: '',
  body_message: '',
  badge_text: 'Notifikasi Rekrutmen',
  info_box_title: 'Detail Informasi',
  action_url: '',
  action_label: '',
  schedule: '',
  venue_or_method: '',
  special_note: '',
  attachment: null,
  attachment_name: '',
});

const handleAttachmentUpload = (event) => {
  const file = event.target.files?.[0];
  if (!file) return;

  if (file.size > 15 * 1024 * 1024) {
    Swal.fire({
      icon: 'warning',
      title: 'Ukuran File Terlalu Besar',
      text: 'Maksimal ukuran file dokumen adalah 15MB.',
      confirmButtonColor: '#0c2340',
    });
    return;
  }

  emailForm.value.attachment = file;
  emailForm.value.attachment_name = file.name;
};

const removeAttachment = () => {
  emailForm.value.attachment = null;
  emailForm.value.attachment_name = '';
};

const formatFileSize = (bytes) => {
  if (!bytes) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};

const fetchWhatsappAccounts = async () => {
  try {
    const res = await axios.get('/rekrutmen/api/settings/whatsapp');
    whatsappAccounts.value = res.data?.accounts || [];
    const current = connectedWhatsappAccounts.value.find((account) => account.id === selectedWhatsappAccountId.value);
    const fallback = connectedWhatsappAccounts.value.find((account) => account.is_default) || connectedWhatsappAccounts.value[0];
    selectedWhatsappAccountId.value = current ? current.id : (fallback ? fallback.id : null);
  } catch (err) {
    console.error('Failed to fetch WhatsApp accounts', err);
  }
};

const fetchEmailTemplates = async () => {
  try {
    const res = await axios.get('/rekrutmen/api/settings/mail-templates');
    if (res.data?.templates) {
      emailTemplatesList.value = res.data.templates;
    }
  } catch (err) {
    console.error('Failed to fetch email templates', err);
  }
};

const pipelineTemplateTabs = [
  { key: 'screening', num: '1', label: 'Screening CV' },
  { key: 'interview_hr', num: '2', label: 'Interview HR' },
  { key: 'psikotes', num: '3', label: 'Psikotes' },
  { key: 'kompetensi', num: '4', label: 'Tes Kompetensi' },
  { key: 'interview_user', num: '5', label: 'Interview User' },
  { key: 'background_check', num: '6', label: 'Background Check' },
  { key: 'offering', num: '7', label: 'Offering Letter' },
  { key: 'hired', num: '8', label: 'Hired' },
  { key: 'rejection', num: '✕', label: 'Penolakan' },
];

const openSendEmailModal = async (app) => {
  if (!app) return;
  isBulkMode.value = false;
  sendEmailModalApp.value = app;
  sendType.value = 'immediate';
  scheduleDate.value = todayDateString.value;
  const nextHour = new Date();
  nextHour.setHours(nextHour.getHours() + 1);
  scheduleTime.value = `${String(nextHour.getHours()).padStart(2, '0')}:00`;

  if (!selectedChannels.value.length) {
    selectedChannels.value = ['email', 'whatsapp'];
  }
  emailForm.value.attachment = null;
  emailForm.value.attachment_name = '';

  if (!Object.keys(emailTemplatesList.value).length) {
    await fetchEmailTemplates();
  }
  await fetchWhatsappAccounts();

  const stgName = (app.stage?.name || '').toLowerCase();
  let defaultKey = 'interview_hr';
  if (stgName.includes('screen')) defaultKey = 'interview_hr';
  else if (stgName.includes('interview hr') || stgName === 'interview') defaultKey = 'psikotes';
  else if (stgName.includes('psiko')) defaultKey = 'kompetensi';
  else if (stgName.includes('kompetensi')) defaultKey = 'interview_user';
  else if (stgName.includes('user')) defaultKey = 'background_check';
  else if (stgName.includes('backgro') || stgName.includes('check')) defaultKey = 'offering';
  else if (stgName.includes('offer')) defaultKey = 'hired';
  else if (stgName.includes('hire')) defaultKey = 'hired';
  else if (stgName.includes('reject') || stgName.includes('tolak')) defaultKey = 'rejection';
  
  applyEmailTemplate(defaultKey);
};

const onToggleIndividualSchedules = () => {
  if (useIndividualSchedules.value) {
    const map = {};
    const baseSchedule = emailForm.value.schedule || '';
    selectedAppIds.value.forEach((id, idx) => {
      const hour = 8 + idx;
      const defaultTime = `${String(hour).padStart(2, '0')}:00 WIB`;
      map[id] = {
        schedule: candidateSchedules.value[id]?.schedule || baseSchedule || defaultTime,
        action_url: '',
        venue_or_method: '',
      };
    });
    candidateSchedules.value = map;
  }
};

const openBulkNotificationModal = async () => {
  if (!selectedAppIds.value.length) return;
  isBulkMode.value = true;
  useIndividualSchedules.value = false;
  candidateSchedules.value = {};

  sendType.value = 'immediate';
  scheduleDate.value = todayDateString.value;
  const nextHour = new Date();
  nextHour.setHours(nextHour.getHours() + 1);
  scheduleTime.value = `${String(nextHour.getHours()).padStart(2, '0')}:00`;
  
  // Use first selected app for template variable preview
  const firstApp = applications.value.find(a => a.id === selectedAppIds.value[0]) || applications.value[0];
  sendEmailModalApp.value = firstApp || { full_name: 'Pelamar Terpilih', email: 'multi@pelamar' };
  
  if (!selectedChannels.value.length) {
    selectedChannels.value = ['email', 'whatsapp'];
  }
  emailForm.value.attachment = null;
  emailForm.value.attachment_name = '';

  if (!Object.keys(emailTemplatesList.value).length) {
    await fetchEmailTemplates();
  }
  await fetchWhatsappAccounts();

  applyEmailTemplate('interview_hr');
};

const closeNotificationModal = () => {
  sendEmailModalApp.value = null;
  isBulkMode.value = false;
  useIndividualSchedules.value = false;
  candidateSchedules.value = {};
  sendType.value = 'immediate';
  scheduleDate.value = '';
  scheduleTime.value = '';
};

const applyEmailTemplate = (key) => {
  activeEmailTemplateKey.value = key;
  const tpl = emailTemplatesList.value[key];
  if (!tpl || !sendEmailModalApp.value) return;

  const app = sendEmailModalApp.value;
  const name = isBulkMode.value ? '{nama_pelamar}' : (app.full_name || 'Pelamar');
  const pos = app.job_posting?.title || 'Posisi Lowongan';
  const comp = 'OCEAN SPACE';
  const loc = app.job_posting?.location || 'Indonesia';

  const replaceTags = (text) => {
    if (!text) return '';
    if (isBulkMode.value) {
      return text
        .replaceAll('{posisi}', pos)
        .replaceAll('{perusahaan}', comp)
        .replaceAll('{lokasi}', loc);
    }
    return text
      .replaceAll('{nama_pelamar}', name)
      .replaceAll('{posisi}', pos)
      .replaceAll('{perusahaan}', comp)
      .replaceAll('{lokasi}', loc);
  };

  emailForm.value.subject = replaceTags(tpl.subject);
  emailForm.value.body_message = replaceTags(tpl.body);
  emailForm.value.badge_text = tpl.badge || 'Notifikasi Rekrutmen';
  emailForm.value.info_box_title = tpl.info_title || 'Detail Informasi';
  emailForm.value.action_label = tpl.action_label || '';
  emailForm.value.special_note = tpl.default_note || '';

  if (key === 'interview_hr' || key === 'interview_user' || key === 'interview') {
    emailForm.value.venue_or_method = 'Online (Google Meet)';
    emailForm.value.schedule = '';
    emailForm.value.action_url = '';
  } else if (key === 'psikotes') {
    emailForm.value.venue_or_method = 'Online Assessment Platform';
    emailForm.value.schedule = 'Batas Pengerjaan: 3 hari kerja';
    emailForm.value.action_url = '';
  } else if (key === 'kompetensi') {
    emailForm.value.venue_or_method = 'Online Assignment / Submission';
    emailForm.value.schedule = 'Batas Pengumpulan: 3 hari kerja';
    emailForm.value.action_url = '';
  } else if (key === 'background_check') {
    emailForm.value.venue_or_method = 'Online Form / Verifikasi HR';
    emailForm.value.schedule = 'Batas Pengisian: 2 hari kerja';
    emailForm.value.action_url = '';
  } else if (key === 'offering') {
    emailForm.value.venue_or_method = loc;
    emailForm.value.schedule = 'Batas Konfirmasi: 3 hari kerja';
    emailForm.value.action_url = '';
  } else if (key === 'hired') {
    emailForm.value.venue_or_method = `Kantor ${comp} (${loc})`;
    emailForm.value.schedule = 'Hari Pertama Masuk Kerja: 08:30 WIB';
    emailForm.value.action_url = '';
  } else if (key === 'screening') {
    emailForm.value.venue_or_method = 'Tahap Peninjauan Berkas';
    emailForm.value.schedule = '';
    emailForm.value.action_url = '';
  } else {
    emailForm.value.action_url = '';
    emailForm.value.schedule = '';
    emailForm.value.venue_or_method = '';
  }

  adjustTextareaHeight();
};

const bodyTextareaRef = ref(null);

const adjustTextareaHeight = () => {
  nextTick(() => {
    if (bodyTextareaRef.value) {
      bodyTextareaRef.value.style.height = 'auto';
      bodyTextareaRef.value.style.height = `${Math.max(140, bodyTextareaRef.value.scrollHeight + 8)}px`;
    }
  });
};

const insertTag = (tag) => {
  if (!emailForm.value.body_message) {
    emailForm.value.body_message = tag;
  } else {
    emailForm.value.body_message += ' ' + tag;
  }
  adjustTextareaHeight();
};

const executeSendNotification = async () => {
  if (!sendEmailModalApp.value) return;

  if (!selectedChannels.value.length) {
    Swal.fire({
      icon: 'warning',
      title: 'Pilih Kanal Notifikasi',
      text: 'Harap centang minimal salah satu kanal: Email atau WhatsApp.',
      confirmButtonColor: '#0c2340',
    });
    return;
  }

  if (sendType.value === 'scheduled') {
    if (!scheduleDate.value || !scheduleTime.value) {
      Swal.fire({
        icon: 'warning',
        title: 'Tentukan Jadwal Pengiriman',
        text: 'Harap lengkapi tanggal dan jam pengiriman notifikasi.',
        confirmButtonColor: '#0c2340',
      });
      return;
    }

    const scheduledDateObj = new Date(`${scheduleDate.value}T${scheduleTime.value}:00`);
    if (scheduledDateObj <= new Date()) {
      Swal.fire({
        icon: 'warning',
        title: 'Waktu Tidak Valid',
        text: 'Waktu pengiriman terjadwal harus berada di waktu masa depan (setelah waktu saat ini).',
        confirmButtonColor: '#0c2340',
      });
      return;
    }
  }

  isSendingEmail.value = true;

  try {
    const formData = new FormData();
    formData.append('subject', emailForm.value.subject || '');
    formData.append('body_message', emailForm.value.body_message || '');
    formData.append('schedule', emailForm.value.schedule || '');
    formData.append('venue_or_method', emailForm.value.venue_or_method || '');
    formData.append('action_url', emailForm.value.action_url || '');
    formData.append('action_label', emailForm.value.action_label || '');
    formData.append('special_note', emailForm.value.special_note || '');
    formData.append('badge_text', emailForm.value.badge_text || '');
    formData.append('info_box_title', emailForm.value.info_box_title || '');

    formData.append('send_type', sendType.value);
    if (sendType.value === 'scheduled') {
      formData.append('scheduled_at', `${scheduleDate.value} ${scheduleTime.value}:00`);
    }

    formData.append('template_key', activeEmailTemplateKey.value);

    selectedChannels.value.forEach((ch) => {
      formData.append('channels[]', ch);
    });

    if (selectedWhatsappAccountId.value) {
      formData.append('whatsapp_account_id', selectedWhatsappAccountId.value);
    }

    if (emailForm.value.attachment) {
      formData.append('attachment', emailForm.value.attachment);
    }

    if (isBulkMode.value) {
      selectedAppIds.value.forEach((id) => {
        formData.append('application_ids[]', id);
      });

      if (useIndividualSchedules.value) {
        formData.append('candidate_schedules', JSON.stringify(candidateSchedules.value));
      }

      const res = await axios.post('/rekrutmen/api/applications/bulk-send-notification', formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
      });

      const deliveryTimeStr = res.data.formatted_scheduled_at || schedulePreviewText.value;
      isSendingEmail.value = false;
      const count = selectedAppIds.value.length;
      selectedAppIds.value = [];
      closeNotificationModal();

      // Refresh applications immediately so stage changes reflect in Table & Kanban
      await store.fetchApplications('', false).catch(() => {});

      if (res.data.scheduled) {
        Swal.fire({
          icon: 'success',
          title: 'Notifikasi Massal Berhasil Dijadwalkan',
          html: `
            <div class="text-xs text-slate-600 mt-2 space-y-3">
              <p class="text-slate-700">Notifikasi untuk <strong>${count} pelamar terpilih</strong> akan dikirim secara otomatis pada:</p>
              <div class="inline-flex items-center gap-2 px-3.5 py-2 bg-blue-50 text-blue-700 font-semibold rounded-lg border border-blue-100 text-xs shadow-2xs">
                <span>📅</span>
                <span>${deliveryTimeStr}</span>
              </div>
            </div>
          `,
          confirmButtonText: 'Selesai',
          confirmButtonColor: '#2563eb',
          customClass: {
            popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
            title: 'text-base font-bold text-slate-900',
            confirmButton: 'px-6 py-2 rounded-lg text-xs font-semibold'
          }
        });
      } else {
        const stats = res.data.stats || {};
        let recapHtml = `
          <div class="text-xs text-left space-y-1.5 mt-2 bg-slate-50 p-3 rounded-lg border border-slate-200">
            <div><strong>Total Sasaran:</strong> ${stats.total || count} pelamar</div>
        `;

        if (selectedChannels.value.includes('email')) {
          recapHtml += `
            <div class="text-blue-700">📧 <strong>Email:</strong> ${stats.email_success || 0} berhasil terkirim (Gagal: ${stats.email_failed || 0}, Email Kosong: ${stats.skipped_no_email || 0})</div>
          `;
        }

        if (selectedChannels.value.includes('whatsapp')) {
          recapHtml += `
            <div class="text-emerald-700">💬 <strong>WhatsApp:</strong> ${stats.whatsapp_success || 0} berhasil terkirim (Gagal: ${stats.whatsapp_failed || 0})</div>
          `;
        }

        recapHtml += `</div>`;

        Swal.fire({
          icon: 'success',
          title: 'Notifikasi Massal Berhasil Dikirim!',
          html: recapHtml,
          confirmButtonColor: '#2563eb',
          customClass: {
            popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
            title: 'text-sm font-bold text-slate-900',
            confirmButton: 'px-6 py-2 rounded-lg text-xs font-semibold'
          }
        });
      }
    } else {
      // Single candidate notification
      const app = sendEmailModalApp.value;

      const res = await axios.post(`/rekrutmen/api/applications/${app.id}/send-notification`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
      });

      const deliveryTimeStr = res.data.formatted_scheduled_at || schedulePreviewText.value;
      isSendingEmail.value = false;
      closeNotificationModal();

      // Update local state and refresh applications list so stage change is immediate
      if (res.data?.new_stage) {
        app.current_stage_id = res.data.new_stage.id;
        const targetStage = stages.value.find(s => String(s.id) === String(res.data.new_stage.id));
        if (targetStage) {
          app.stage = { id: targetStage.id, name: targetStage.name, color: targetStage.color };
        }
        if (selectedApp.value && String(selectedApp.value.id) === String(app.id)) {
          selectedApp.value.current_stage_id = res.data.new_stage.id;
          if (targetStage) {
            selectedApp.value.stage = { id: targetStage.id, name: targetStage.name, color: targetStage.color };
          }
        }
      }
      await store.fetchApplications('', false).catch(() => {});

      if (res.data.scheduled) {
        Swal.fire({
          icon: 'success',
          title: 'Notifikasi Berhasil Dijadwalkan',
          html: `
            <div class="text-xs text-slate-600 mt-2 space-y-3">
              <p class="text-slate-700">Notifikasi untuk <strong>${app.full_name}</strong> akan dikirim secara otomatis pada:</p>
              <div class="inline-flex items-center gap-2 px-3.5 py-2 bg-blue-50 text-blue-700 font-semibold rounded-lg border border-blue-100 text-xs shadow-2xs">
                <span>📅</span>
                <span>${deliveryTimeStr}</span>
              </div>
            </div>
          `,
          confirmButtonText: 'Selesai',
          confirmButtonColor: '#2563eb',
          customClass: {
            popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
            title: 'text-base font-bold text-slate-900',
            confirmButton: 'px-6 py-2 rounded-lg text-xs font-semibold'
          }
        });
      } else {
        Swal.fire({
          icon: 'success',
          title: 'Notifikasi Berhasil Terkirim!',
          html: `<div class="text-xs text-slate-600 mt-1">${res.data.message || 'Notifikasi berhasil dikirimkan ke kandidat.'}</div>`,
          timer: 3000,
          showConfirmButton: false,
          iconColor: '#10b981',
          customClass: {
            popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
            title: 'text-sm font-bold text-slate-900',
          }
        });
      }
    }
  } catch (err) {
    isSendingEmail.value = false;
    Swal.fire({
      icon: 'error',
      title: sendType.value === 'scheduled' ? 'Gagal Menjadwalkan Notifikasi' : 'Gagal Mengirim Notifikasi',
      text: err.response?.data?.message || 'Terjadi kesalahan saat memproses notifikasi.',
      confirmButtonColor: '#e11d48',
      customClass: {
        popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
        title: 'text-sm font-bold text-slate-900',
        confirmButton: 'px-4 py-2 rounded-xl text-xs font-bold'
      }
    });
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
