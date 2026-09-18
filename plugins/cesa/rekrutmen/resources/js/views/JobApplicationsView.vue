<template>
  <div class="space-y-4 pb-12">
    <!-- Top Header: Title, Live Metrics & Primary Actions (Elevated "Asoy" Card) -->
    <div class="p-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3.5">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-surface-gray-2 border border-outline-gray-2 flex items-center justify-center shrink-0 text-ink-gray-8 shadow-2xs">
          <Users class="w-5 h-5 stroke-[1.75]" />
        </div>
        <div>
          <div class="flex items-center gap-2.5">
            <h1 class="text-base sm:text-lg font-bold text-ink-gray-9 tracking-tight">
              {{ activeJobTitle ? `Pelamar: ${activeJobTitle}` : 'Data Pelamar Kerja' }}
            </h1>
            <FBadge theme="blue" variant="subtle" size="sm" class="tabular-nums font-semibold">
              <template #prefix>
                <span class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse shrink-0"></span>
              </template>
              {{ applications.length }} Total Pelamar
            </FBadge>
          </div>
          <p class="text-xs text-ink-gray-5 mt-0.5">
            Pantau seluruh data kandidat pelamar, kualifikasi kecocokan, dan alur tahapan seleksi rekrutmen
          </p>
        </div>
      </div>

      <!-- Controls & View Switcher -->
      <div class="flex items-center gap-2 flex-wrap">
        <!-- Active Job Filter Tag -->
        <button
          v-if="activeJobId"
          type="button"
          @click="resetJobFilter"
          class="h-8 px-2.5 bg-surface-gray-2 hover:bg-surface-gray-3 text-ink-gray-8 rounded-md text-xs font-medium border border-outline-gray-2 flex items-center gap-1.5 transition-colors cursor-pointer"
          title="Tampilkan Semua Pelamar"
        >
          <span>Filter: {{ activeJobTitle }}</span>
          <span class="text-ink-gray-5 font-bold">&times;</span>
        </button>

        <!-- Sinkronkan Berkas CV Action Button -->
        <FButton
          theme="gray"
          variant="outline"
          size="sm"
          :icon-left="FileText"
          :loading="isSyncingCvs"
          @click="startSyncCvs"
          title="Cocokkan berkas CV di folder storage dengan data kandidat pelamar"
        >
          Cocokkan CV
        </FButton>

        <!-- Evaluasi Kualifikasi Action Button -->
        <FButton
          :theme="selectedAppIds.length ? 'blue' : 'gray'"
          :variant="selectedAppIds.length ? 'solid' : 'outline'"
          size="sm"
          :icon-left="RotateCw"
          :loading="isScreening"
          @click="startRescreening"
          :title="selectedAppIds.length === 1 ? 'Jalankan evaluasi kualifikasi AI khusus untuk 1 pelamar terpilih' : (selectedAppIds.length > 1 ? `Jalankan evaluasi kualifikasi AI khusus untuk ${selectedAppIds.length} pelamar terpilih` : 'Jalankan evaluasi kualifikasi otomatis untuk pelamar')"
        >
          {{ selectedAppIds.length === 1 ? 'Evaluasi AI (1)' : (selectedAppIds.length > 1 ? `Evaluasi AI (${selectedAppIds.length})` : 'Evaluasi AI') }}
        </FButton>

        <!-- View Switcher (Daftar / Kanban) -->
        <div class="inline-flex items-center p-0.5 bg-surface-gray-2 rounded-md border border-outline-gray-2">
          <button
            type="button"
            @click="viewMode = 'table'"
            :class="[
              'px-2.5 py-1 rounded text-xs font-medium transition-all cursor-pointer select-none flex items-center gap-1.5',
              viewMode === 'table'
                ? 'bg-surface-white text-ink-gray-9 shadow-2xs font-semibold'
                : 'text-ink-gray-6 hover:text-ink-gray-9'
            ]"
          >
            <ListFilter class="w-3.5 h-3.5" />
            <span>Daftar</span>
          </button>
          <button
            type="button"
            @click="viewMode = 'kanban'"
            :class="[
              'px-2.5 py-1 rounded text-xs font-medium transition-all cursor-pointer select-none flex items-center gap-1.5',
              viewMode === 'kanban'
                ? 'bg-surface-white text-ink-gray-9 shadow-2xs font-semibold'
                : 'text-ink-gray-6 hover:text-ink-gray-9'
            ]"
          >
            <Kanban class="w-3.5 h-3.5" />
            <span>Kanban</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Notification Progress Bar -->
    <section v-if="notificationProgress" aria-label="Progres pengiriman notifikasi" class="rounded-lg border border-outline-gray-2 bg-surface-white p-4 space-y-3 shadow-2xs">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h2 class="text-xs font-bold text-ink-gray-9">{{ notificationProgressTitle }}</h2>
          <p class="text-[11px] text-ink-gray-5">Pengiriman #{{ notificationProgress.id }} · {{ notificationProgress.stats?.total || 0 }} pelamar</p>
        </div>
        <FButton theme="gray" variant="outline" size="sm" @click="refreshNotificationProgress">Perbarui Status</FButton>
      </div>
      <div class="flex flex-wrap gap-3 text-xs text-ink-gray-7" aria-live="polite">
        <span>Email terkirim: {{ notificationProgress.stats?.email_success || 0 }}</span>
        <span>WhatsApp terkirim: {{ notificationProgress.stats?.whatsapp_success || 0 }}</span>
        <span>Gagal: {{ (notificationProgress.stats?.email_failed || 0) + (notificationProgress.stats?.whatsapp_failed || 0) }}</span>
        <span>Menunggu: {{ notificationPendingCount }}</span>
        <span>Belum pasti: {{ notificationUnknownCount }}</span>
      </div>
      <p v-if="notificationProgressError" role="status" class="text-xs text-amber-700">{{ notificationProgressError }}</p>
      <p v-if="notificationUnknownCount" class="text-xs text-amber-700">Sebagian hasil belum dapat dipastikan. Periksa penerimaan pesan sebelum membuat pengiriman baru.</p>
      <div v-if="notificationProgress.details?.length" class="max-h-64 overflow-auto">
        <table class="w-full text-left text-xs">
          <thead>
            <tr><th class="py-2">Pelamar</th><th>Email</th><th>WhatsApp</th></tr>
          </thead>
          <tbody>
            <tr v-for="detail in notificationProgress.details" :key="detail.id" class="border-t border-outline-gray-2">
              <td class="py-2 pr-3">{{ detail.name }}</td>
              <td class="py-2 pr-3" :title="detail.email?.message">
                {{ deliveryStatusLabel(detail.email) }}
                <span v-if="detail.email?.stage_error" :title="detail.email.stage_error" class="block text-amber-700">Tahapan belum diperbarui</span>
              </td>
              <td class="py-2" :title="detail.whatsapp?.message">
                {{ deliveryStatusLabel(detail.whatsapp) }}
                <span v-if="detail.whatsapp?.stage_error" :title="detail.whatsapp.stage_error" class="block text-amber-700">Tahapan belum diperbarui</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

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
      <!-- Total Pelamar -->
      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Total Pelamar</span>
          <span class="text-xl font-bold text-ink-gray-9 mt-1 block tabular-nums">{{ applications.length }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Semua berkas lamaran masuk</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-blue-2 text-surface-blue-3 flex items-center justify-center shrink-0">
          <Users class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>

      <!-- Sangat Sesuai -->
      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Sangat Sesuai</span>
          <span class="text-xl font-bold text-emerald-700 mt-1 block tabular-nums">{{ recommendedCount }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Skor kecocokan &ge; 75%</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-green-2 text-surface-green-3 flex items-center justify-center shrink-0">
          <UserCheck class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>

      <!-- Dipertimbangkan -->
      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Dipertimbangkan</span>
          <span class="text-xl font-bold text-amber-700 mt-1 block tabular-nums">{{ consideredCount }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Skor kecocokan 50% - 74%</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-gray-2 text-amber-600 flex items-center justify-center shrink-0">
          <Clock class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>

      <!-- Ditolak / Kurang Sesuai -->
      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Ditolak / Kurang Sesuai</span>
          <span class="text-xl font-bold text-rose-700 mt-1 block tabular-nums">{{ rejectedCandidateCount + notSuitableCount }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">{{ rejectedCandidateCount }} ditolak &bull; {{ notSuitableCount }} skor &lt; 50%</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-red-2 text-rose-600 flex items-center justify-center shrink-0">
          <UserX class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>
    </div>

    <!-- Filters & Search Toolbar -->
    <div class="p-2.5 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex flex-col md:flex-row gap-2.5 items-stretch md:items-center justify-between">
      <!-- Match Filter Tabs -->
      <div class="inline-flex items-center p-0.5 bg-surface-gray-2 rounded-md border border-outline-gray-2 overflow-x-auto no-scrollbar">
        <button
          type="button"
          @click="matchFilter = 'all'"
          :class="[
            'px-2.5 h-7 rounded text-xs font-medium transition-all cursor-pointer inline-flex items-center justify-center gap-1.5 select-none leading-none',
            matchFilter === 'all'
              ? 'bg-surface-white text-ink-gray-8 shadow-2xs font-semibold'
              : 'text-ink-gray-5 hover:text-ink-gray-8'
          ]"
        >
          <span>Semua</span>
          <span :class="['text-[10px] tabular-nums leading-none', matchFilter === 'all' ? 'text-ink-gray-8 font-semibold' : 'text-ink-gray-4']">
            {{ applications.length }}
          </span>
        </button>

        <button
          type="button"
          @click="matchFilter = 'recommended'"
          :class="[
            'px-2.5 h-7 rounded text-xs font-medium transition-all cursor-pointer inline-flex items-center justify-center gap-1.5 select-none leading-none',
            matchFilter === 'recommended'
              ? 'bg-surface-white text-emerald-800 shadow-2xs font-semibold'
              : 'text-ink-gray-5 hover:text-ink-gray-8'
          ]"
        >
          <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0"></span>
          <span>Sangat Sesuai</span>
          <span :class="['text-[10px] tabular-nums leading-none', matchFilter === 'recommended' ? 'text-emerald-800 font-semibold' : 'text-ink-gray-4']">
            {{ recommendedCount }}
          </span>
        </button>

        <button
          type="button"
          @click="matchFilter = 'considered'"
          :class="[
            'px-2.5 h-7 rounded text-xs font-medium transition-all cursor-pointer inline-flex items-center justify-center gap-1.5 select-none leading-none',
            matchFilter === 'considered'
              ? 'bg-surface-white text-amber-800 shadow-2xs font-semibold'
              : 'text-ink-gray-5 hover:text-ink-gray-8'
          ]"
        >
          <span class="w-1.5 h-1.5 rounded-full bg-amber-500 shrink-0"></span>
          <span>Dipertimbangkan</span>
          <span :class="['text-[10px] tabular-nums leading-none', matchFilter === 'considered' ? 'text-amber-800 font-semibold' : 'text-ink-gray-4']">
            {{ consideredCount }}
          </span>
        </button>

        <button
          type="button"
          @click="matchFilter = 'not_suitable'"
          :class="[
            'px-2.5 h-7 rounded text-xs font-medium transition-all cursor-pointer inline-flex items-center justify-center gap-1.5 select-none leading-none',
            matchFilter === 'not_suitable'
              ? 'bg-surface-white text-rose-800 shadow-2xs font-semibold'
              : 'text-ink-gray-5 hover:text-ink-gray-8'
          ]"
        >
          <span class="w-1.5 h-1.5 rounded-full bg-rose-500 shrink-0"></span>
          <span>Kurang Sesuai</span>
          <span :class="['text-[10px] tabular-nums leading-none', matchFilter === 'not_suitable' ? 'text-rose-800 font-semibold' : 'text-ink-gray-4']">
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
            class="w-full h-8 bg-surface-white border border-outline-gray-2 rounded-md pl-3 pr-8 text-xs text-ink-gray-8 hover:border-outline-gray-3 focus:outline-none focus:ring-1 focus:ring-outline-gray-4 appearance-none cursor-pointer"
          >
            <option value="all">Semua Tahapan ({{ applications.length }})</option>
            <option v-for="stg in stages" :key="stg.id" :value="stg.id">
              {{ stg.name }} ({{ getStageCandidateCount(stg.id) }})
            </option>
            <option value="rejected">
              Ditolak ({{ rejectedCandidateCount }})
            </option>
          </select>
          <ChevronDown class="w-3.5 h-3.5 text-ink-gray-4 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
        </div>

        <FButton
          v-if="stageFilter !== 'all'"
          theme="gray"
          variant="ghost"
          size="sm"
          @click="stageFilter = 'all'"
          title="Reset filter tahapan"
        >
          Reset
        </FButton>

        <!-- Search Bar -->
        <FTextInput
          v-model="searchQuery"
          size="md"
          variant="outline"
          placeholder="Cari kandidat, email..."
          class="w-full sm:w-64"
        >
          <template #prefix>
            <Search class="w-3.5 h-3.5 text-zinc-400" />
          </template>
        </FTextInput>
      </div>
    </div>

    <!-- CANDIDATE CARD FEED VIEW (No Table Layout) -->
    <div v-if="viewMode === 'table'" class="space-y-3">
      <!-- Unified Selection & Bulk Action Toolbar (Single Clean Strip) -->
      <div
        class="px-3.5 py-2.5 rounded-lg border shadow-2xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2.5 text-xs transition-all bg-surface-white"
        :class="selectedAppIds.length ? 'border-blue-200 ring-1 ring-blue-100' : 'border-outline-gray-2'"
      >
        <!-- Left: Checkbox & Selection Info -->
        <div class="flex items-center gap-2.5">
          <label class="inline-flex items-center gap-2 cursor-pointer select-none">
            <input
              type="checkbox"
              :checked="isAllSelected"
              @change="toggleSelectAll"
              class="rounded border-outline-gray-3 text-ink-gray-9 accent-ink-gray-9 focus:ring-0 cursor-pointer w-4 h-4"
            />
            <span v-if="!selectedAppIds.length" class="font-medium text-ink-gray-7">
              Pilih Semua ({{ filteredApplications.length }} kandidat)
            </span>
          </label>

          <template v-if="selectedAppIds.length">
            <FBadge theme="blue" variant="subtle" size="sm" class="font-bold">
              <template #prefix>
                <CheckSquare class="w-3.5 h-3.5 text-blue-600 shrink-0" />
              </template>
              {{ selectedAppIds.length }} Pelamar Dipilih
            </FBadge>
            <button
              type="button"
              @click="selectedAppIds = []"
              class="text-ink-gray-5 hover:text-ink-gray-9 text-xs font-medium cursor-pointer transition-colors hover:underline"
            >
              Batalkan pilihan
            </button>
          </template>
        </div>

        <!-- Right: Default Summary or Bulk Action Buttons -->
        <div v-if="!selectedAppIds.length" class="text-[11px] text-ink-gray-4">
          Menampilkan {{ filteredApplications.length }} dari {{ applications.length }} pelamar
        </div>

        <div v-else class="flex items-center gap-2 flex-wrap">
          <FButton
            theme="gray"
            variant="outline"
            size="sm"
            :icon-left="RotateCw"
            :loading="isScreening"
            @click="rescreenSelectedCandidates"
            :title="selectedAppIds.length === 1 ? 'Jalankan evaluasi kualifikasi AI hanya untuk 1 pelamar terpilih' : 'Jalankan evaluasi kualifikasi AI hanya untuk kandidat terpilih'"
          >
            {{ selectedAppIds.length === 1 ? 'Evaluasi AI (1)' : `Evaluasi AI (${selectedAppIds.length})` }}
          </FButton>

          <FButton
            theme="red"
            variant="outline"
            size="sm"
            :icon-left="UserX"
            @click="bulkRejectSelected"
            title="Tolak pelamar terpilih"
          >
            Tolak
          </FButton>

          <FButton
            theme="gray"
            variant="solid"
            size="sm"
            :icon-left="Send"
            @click="openBulkNotificationModal"
          >
            Kirim Notifikasi Massal
          </FButton>
        </div>
      </div>

      <!-- Candidate Cards Feed -->
      <div v-if="filteredApplications.length" class="space-y-2.5">
        <div
          v-for="app in filteredApplications"
          :key="app.id"
          role="group"
          :aria-label="`Pelamar ${app.full_name}`"
          class="p-4 bg-surface-white rounded-lg border transition-all duration-150 shadow-2xs hover:shadow-xs group"
          :class="[
            isSelected(app.id) ? 'border-blue-400 ring-1 ring-blue-200 bg-blue-50/20' : 'border-outline-gray-2 hover:border-outline-gray-3'
          ]"
        >
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-3.5">
            <!-- Left Side: Checkbox + Avatar + Info -->
            <div class="flex items-center gap-3 min-w-0 flex-1">
              <div class="shrink-0 flex items-center justify-center" @click.stop>
                <input
                  type="checkbox"
                  :value="app.id"
                  v-model="selectedAppIds"
                  class="rounded border-outline-gray-3 text-ink-gray-9 accent-ink-gray-9 focus:ring-0 cursor-pointer w-4 h-4"
                />
              </div>

              <!-- Avatar Circle -->
              <div class="w-10 h-10 rounded-lg bg-surface-gray-2 border border-outline-gray-2 text-ink-gray-7 flex items-center justify-center shrink-0 overflow-hidden relative shadow-2xs">
                <img
                  v-if="app.photo_url"
                  :src="app.photo_url"
                  :alt="app.full_name"
                  class="w-full h-full object-cover"
                  loading="lazy"
                  @error="(e) => e.target.style.display = 'none'"
                />
                <span v-else class="text-xs font-bold uppercase">{{ getInitials(app.full_name) }}</span>
              </div>

              <!-- Details -->
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                  <h3
                    class="font-bold text-sm text-ink-gray-9 hover:text-blue-600 transition-colors cursor-pointer truncate"
                    @click="openDetail(app)"
                  >
                    {{ app.full_name }}
                  </h3>

                  <!-- Job Title Badge -->
                  <FBadge theme="gray" variant="subtle" size="sm" class="font-medium truncate max-w-[200px]">
                    {{ app.job_posting?.title || 'Posisi Terhapus' }}
                  </FBadge>

                  <!-- Source Badge -->
                  <span v-if="app.source" class="text-[10px] px-1.5 py-0.5 rounded bg-surface-gray-2 text-ink-gray-6 border border-outline-gray-2">
                    {{ app.source }}
                  </span>
                </div>

                <!-- Contact & Meta row -->
                <div class="flex items-center gap-3 text-xs text-ink-gray-5 mt-1.5 flex-wrap">
                  <span class="flex items-center gap-1 truncate" :title="app.email">
                    <Mail class="w-3.5 h-3.5 text-ink-gray-4 shrink-0" />
                    {{ app.email || '-' }}
                  </span>
                  <span class="text-outline-gray-3">&bull;</span>
                  <span class="flex items-center gap-1 truncate" :title="app.whatsapp_number || app.phone">
                    <Phone class="w-3.5 h-3.5 text-ink-gray-4 shrink-0" />
                    {{ app.whatsapp_number || app.phone || '-' }}
                  </span>
                  <span class="text-outline-gray-3">&bull;</span>
                  <span class="flex items-center gap-1 text-[11px] text-ink-gray-4">
                    <Clock class="w-3 h-3 text-ink-gray-4 shrink-0" />
                    {{ app.created_at }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Right Side: AI Match Score + Stage Selector + Actions -->
            <div class="flex items-center gap-3 shrink-0 flex-wrap sm:flex-nowrap justify-between md:justify-end pt-2 md:pt-0 border-t md:border-t-0 border-outline-gray-1">
              <!-- AI Match Score -->
              <div class="shrink-0" @click.stop>
                <div v-if="app.ai_match_score !== null && app.ai_match_score !== undefined">
                  <button
                    type="button"
                    @click="openAnalysisModal(app)"
                    class="cursor-pointer transition-transform hover:scale-105"
                    title="Klik untuk melihat hasil analisis kualifikasi"
                  >
                    <FBadge
                      :theme="app.ai_match_score >= 75 ? 'green' : (app.ai_match_score >= 50 ? 'orange' : 'gray')"
                      variant="subtle"
                      size="sm"
                      class="font-semibold tabular-nums"
                    >
                      {{ app.ai_match_score }}% &bull; {{ formatAiRecommendation(app.ai_recommendation) }}
                    </FBadge>
                  </button>
                </div>
                <div v-else>
                  <FButton
                    theme="gray"
                    variant="outline"
                    size="sm"
                    :icon-left="RotateCw"
                    :loading="isScreening"
                    @click="rescreenSingleCandidate(app)"
                    title="Klik untuk evaluasi kualifikasi kandidat ini dengan AI"
                  >
                    Evaluasi AI
                  </FButton>
                </div>
              </div>

              <!-- Stage Selector Dropdown -->
              <div class="relative min-w-[140px] shrink-0" @click.stop>
                <select
                  :value="app.status === 'rejected' ? 'rejected' : (app.current_stage_id || app.stage?.id || 1)"
                  @change="handleStageChange(app, $event.target.value)"
                  :class="[
                    'w-full h-8 text-xs font-medium rounded-md pl-2.5 pr-7 border cursor-pointer transition-colors appearance-none shadow-2xs',
                    app.status === 'rejected'
                      ? 'bg-rose-50 border-rose-300 text-rose-700 font-semibold'
                      : 'bg-surface-white border-outline-gray-2 text-ink-gray-8 hover:border-outline-gray-3 focus:outline-none focus:ring-1 focus:ring-outline-gray-4'
                  ]"
                  title="Ubah tahapan kandidat"
                >
                  <option v-for="stg in getApplicationStages(app)" :key="stg.id" :value="stg.id">
                    {{ stg.name }}
                  </option>
                  <option value="rejected" class="text-rose-600 font-semibold">
                    Ditolak
                  </option>
                </select>
                <ChevronDown class="w-3 h-3 text-ink-gray-4 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none" />
              </div>

              <!-- Action Buttons -->
              <div class="flex items-center gap-1.5 shrink-0" @click.stop>
                <FButton
                  theme="gray"
                  variant="outline"
                  size="sm"
                  :icon-left="Eye"
                  @click="openDetail(app)"
                  title="Detail Profil Kandidat"
                >
                  Detail
                </FButton>

                <FButton
                  theme="gray"
                  variant="ghost"
                  size="sm"
                  :icon-left="Send"
                  @click="openSendEmailModal(app)"
                  title="Kirim Notifikasi (Email / WhatsApp)"
                />
              </div>
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
          <Users class="w-6 h-6 stroke-[1.5]" />
        </div>
        <p class="text-sm font-semibold text-ink-gray-8">Tidak ada data kandidat pelamar</p>
        <p class="text-xs text-ink-gray-5 max-w-sm">
          Tidak ada pelamar yang cocok dengan kriteria filter atau pencarian Anda saat ini.
        </p>
        <FButton
          theme="gray"
          variant="outline"
          size="sm"
          class="mt-2"
          @click="resetJobFilter(); matchFilter = 'all'; stageFilter = 'all'; searchQuery = '';"
        >
          Reset Semua Filter
        </FButton>
      </div>
    </div>

    <!-- KANBAN BOARD VIEW -->
    <div v-else class="flex gap-4 overflow-x-auto pb-4 no-scrollbar min-h-[calc(100vh-280px)] items-start select-none">
      <div
        v-for="stage in stages"
        :key="stage.id"
        class="w-80 shrink-0 bg-surface-gray-1 border rounded-lg flex flex-col max-h-[calc(100vh-280px)] transition-all shadow-2xs"
        :class="dragOverStageId === stage.id ? 'border-outline-gray-4 bg-surface-gray-2 ring-2 ring-outline-gray-3' : 'border-outline-gray-2'"
        @dragover.prevent="handleDragOver(stage.id)"
        @dragleave="handleDragLeave(stage.id)"
        @drop.prevent="handleDrop(stage.id, $event)"
      >
        <!-- Column Header -->
        <div class="p-3 border-b border-outline-gray-2 bg-surface-white rounded-t-lg flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="w-2.5 h-2.5 rounded-full" :style="{ backgroundColor: stage.color || '#71717a' }"></span>
            <span class="text-xs font-bold text-ink-gray-9 tracking-tight">{{ stage.name }}</span>
          </div>
          <FBadge theme="gray" variant="subtle" size="sm" class="tabular-nums font-semibold">
            {{ getStageApplications(stage.id).length }}
          </FBadge>
        </div>

        <!-- Kanban Cards List -->
        <div class="p-2.5 space-y-2.5 overflow-y-auto flex-1 no-scrollbar">
          <div
            v-for="app in getStageApplications(stage.id)"
            :key="app.id"
            class="bg-surface-white p-3.5 rounded-lg border border-outline-gray-2 shadow-2xs hover:shadow-xs transition-all cursor-grab active:cursor-grabbing hover:border-outline-gray-3 group"
            draggable="true"
            @dragstart="handleDragStart(app, $event)"
            @dragend="handleDragEnd"
            @click="openDetail(app)"
          >
            <!-- Top: Candidate Name & Match Pill -->
            <div class="flex items-start justify-between gap-2">
              <div class="flex items-center gap-2 min-w-0">
                <div class="w-7 h-7 rounded-lg bg-surface-gray-2 flex items-center justify-center shrink-0 border border-outline-gray-2 text-ink-gray-6 overflow-hidden relative">
                  <img
                    v-if="app.photo_url"
                    :src="app.photo_url"
                    :alt="app.full_name"
                    class="w-full h-full object-cover"
                    loading="lazy"
                    @error="(e) => e.target.style.display = 'none'"
                  />
                  <span v-else class="text-[9px] font-bold uppercase">{{ getInitials(app.full_name) }}</span>
                </div>
                <div class="min-w-0">
                  <h4 class="font-bold text-xs text-ink-gray-9 group-hover:text-blue-600 transition-colors truncate">
                    {{ app.full_name }}
                  </h4>
                  <p class="text-[11px] text-ink-gray-5 truncate">{{ app.email }}</p>
                </div>
              </div>

              <FBadge
                v-if="app.ai_match_score !== null && app.ai_match_score !== undefined"
                :theme="app.ai_match_score >= 75 ? 'green' : (app.ai_match_score >= 50 ? 'orange' : 'gray')"
                variant="subtle"
                size="sm"
                class="shrink-0 font-semibold cursor-pointer tabular-nums"
                @click.stop="openAnalysisModal(app)"
                title="Klik untuk melihat hasil analisis kualifikasi"
              >
                {{ app.ai_match_score }}%
              </FBadge>
            </div>

            <!-- Role / Details Subtitle -->
            <div v-if="!activeJobId && app.job_posting?.title" class="text-[11px] font-medium text-ink-gray-6 mt-2 pt-2 border-t border-outline-gray-1 line-clamp-1">
              {{ app.job_posting.title }}
            </div>

            <!-- Card Footer -->
            <div class="flex items-center justify-between text-[10px] text-ink-gray-4 mt-2 pt-2 border-t border-outline-gray-1">
              <span>{{ app.created_at }}</span>
              <span class="px-1.5 py-0.5 rounded bg-surface-gray-2 text-ink-gray-6 border border-outline-gray-2 font-medium">
                {{ app.source || 'Portal' }}
              </span>
            </div>
          </div>

          <!-- Empty State in Column -->
          <div
            v-if="!getStageApplications(stage.id).length"
            class="py-8 text-center text-xs text-ink-gray-4 border border-dashed border-outline-gray-2 rounded-lg flex flex-col items-center justify-center gap-1"
          >
            <span class="text-ink-gray-3 text-sm">&empty;</span>
            <span>Belum ada kandidat</span>
          </div>
        </div>
      </div>

      <!-- Ditolak Kanban Column -->
      <div
        class="w-80 shrink-0 bg-surface-red-1/40 border rounded-lg flex flex-col max-h-[calc(100vh-280px)] transition-all shadow-2xs"
        :class="dragOverStageId === 'rejected' ? 'border-surface-red-3 bg-surface-red-2/50 ring-2 ring-surface-red-2' : 'border-surface-red-2'"
        @dragover.prevent="handleDragOver('rejected')"
        @dragleave="handleDragLeave('rejected')"
        @drop.prevent="handleDrop('rejected', $event)"
      >
        <!-- Column Header -->
        <div class="p-3 border-b border-surface-red-2 bg-surface-white rounded-t-lg flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span>
            <span class="text-xs font-bold text-rose-900 tracking-tight">Ditolak</span>
          </div>
          <FBadge theme="red" variant="subtle" size="sm" class="tabular-nums font-semibold">
            {{ rejectedCandidateCount }}
          </FBadge>
        </div>

        <!-- Kanban Cards List for Rejected -->
        <div class="p-2.5 space-y-2.5 overflow-y-auto flex-1 no-scrollbar">
          <div
            v-for="app in rejectedApplications"
            :key="app.id"
            class="bg-surface-white p-3.5 rounded-lg border border-surface-red-2 shadow-2xs hover:shadow-xs transition-all cursor-grab active:cursor-grabbing hover:border-surface-red-3 group opacity-90"
            draggable="true"
            @dragstart="handleDragStart(app, $event)"
            @dragend="handleDragEnd"
            @click="openDetail(app)"
          >
            <!-- Top: Candidate Name & Match Pill -->
            <div class="flex items-start justify-between gap-2">
              <div class="flex items-center gap-2 min-w-0">
                <div class="w-6 h-6 rounded-lg bg-surface-red-2 flex items-center justify-center shrink-0 border border-surface-red-2 text-rose-600">
                  <User class="w-3 h-3" />
                </div>
                <div class="min-w-0">
                  <h4 class="font-bold text-xs text-ink-gray-9 group-hover:text-rose-600 transition-colors truncate">
                    {{ app.full_name }}
                  </h4>
                  <p class="text-[11px] text-ink-gray-4 truncate">{{ app.email }}</p>
                </div>
              </div>

              <FBadge theme="red" variant="subtle" size="sm" class="shrink-0 font-semibold">
                Ditolak
              </FBadge>
            </div>

            <!-- Role / Details Subtitle -->
            <div v-if="!activeJobId && app.job_posting?.title" class="text-[11px] font-medium text-ink-gray-6 mt-2 pt-2 border-t border-outline-gray-1 line-clamp-1">
              {{ app.job_posting.title }}
            </div>

            <!-- Card Footer -->
            <div class="flex items-center justify-between text-[10px] text-ink-gray-4 mt-2 pt-2 border-t border-outline-gray-1">
              <span>{{ app.created_at }}</span>
              <span class="px-1.5 py-0.5 rounded bg-surface-red-1 text-rose-600 border border-surface-red-2 font-medium">
                {{ app.source || 'Portal' }}
              </span>
            </div>
          </div>

          <!-- Empty State in Column -->
          <div
            v-if="!rejectedApplications.length"
            class="py-8 text-center text-xs text-ink-gray-4 border border-dashed border-surface-red-2 rounded-lg flex flex-col items-center justify-center gap-1"
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
      role="dialog"
      aria-modal="true"
      aria-label="Profil pelamar"
      class="fixed inset-0 z-[100] bg-black/60 backdrop-blur-xs flex items-center justify-center p-3 sm:p-6"
      @click.self="selectedApp = null"
    >
      <div class="bg-white rounded-xl border border-zinc-200 w-full max-w-6xl h-[90vh] max-h-[900px] flex flex-col shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">

        <!-- Top Header Bar -->
        <div class="px-6 py-3.5 border-b border-zinc-200 bg-white flex items-center justify-between shrink-0">
          <div class="flex items-center gap-3.5 min-w-0">
            <FButton
              theme="gray"
              variant="ghost"
              size="sm"
              :icon-left="ArrowLeft"
              @click="selectedApp = null"
              title="Kembali"
            />
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
                  <option v-for="stg in getApplicationStages(selectedApp)" :key="stg.id" :value="stg.id">
                    {{ stg.name }}
                  </option>
                  <option value="rejected" class="text-rose-600 font-semibold">
                    Ditolak
                  </option>
                </select>
                <ChevronDown class="w-3.5 h-3.5 text-zinc-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
              </div>
            </div>

            <FButton
              theme="gray"
              variant="solid"
              size="sm"
              :icon-left="Send"
              @click="openSendEmailModal(selectedApp)"
            >
              Kirim Notifikasi
            </FButton>

            <a
              v-if="selectedApp.resume_url"
              :href="selectedApp.resume_url"
              target="_blank"
              class="h-8 px-3 rounded-md border border-zinc-200 hover:bg-zinc-50 text-xs font-medium text-zinc-700 inline-flex items-center gap-1.5 transition-colors"
            >
              <span>Buka CV</span>
              <ExternalLink class="w-3.5 h-3.5 text-zinc-400" />
            </a>

            <FButton
              theme="gray"
              variant="ghost"
              size="sm"
              :icon-left="X"
              @click="selectedApp = null"
              title="Tutup"
            />
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
                  <FButton
                    theme="gray"
                    variant="outline"
                    size="sm"
                    :icon-left="RotateCw"
                    :loading="isScreening"
                    @click="rescreenSingleCandidate(selectedApp)"
                  >
                    Evaluasi Ulang
                  </FButton>
                  <FButton
                    theme="gray"
                    variant="solid"
                    size="sm"
                    @click="openAnalysisModal(selectedApp)"
                  >
                    Detail Komparasi &rarr;
                  </FButton>
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
                  <span class="font-medium text-zinc-700 mt-0.5 block tabular-nums">{{ selectedApp.emergency_contact_phone || '-' }}</span>
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
          <FButton
            theme="gray"
            variant="outline"
            size="sm"
            :icon-left="RotateCw"
            :loading="isScreening"
            @click="rescreenSingleCandidate(analysisModalApp)"
          >
            Evaluasi Ulang CV
          </FButton>

          <div class="flex items-center gap-2">
            <FButton
              theme="gray"
              variant="outline"
              size="sm"
              @click="analysisModalApp = null"
            >
              Tutup
            </FButton>
            <FButton
              theme="gray"
              variant="solid"
              size="sm"
              @click="openDetail(analysisModalApp); analysisModalApp = null"
            >
              Buka Detail Profil
            </FButton>
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
                  <span v-if="sendEmailModalApp.whatsapp_number || sendEmailModalApp.phone" class="text-zinc-500 font-medium ml-1.5">WA: {{ sendEmailModalApp.whatsapp_number || sendEmailModalApp.phone }}</span>
                  <span class="mx-1.5 text-zinc-300">•</span>
                  Tahap: <strong class="text-zinc-800">{{ getCandidateCurrentStageName(sendEmailModalApp) }}</strong>
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

        <!-- 2. Modal Body (Simple, Clean, Spacious Flow) -->
        <div class="p-6 space-y-5 text-xs overflow-y-auto flex-1 font-sans">

          <!-- Saluran & Waktu Pengiriman (Compact Toolbar) -->
          <div class="p-3.5 bg-zinc-50/80 rounded-xl border border-zinc-200/80 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="text-xs font-semibold text-zinc-700 mr-1">Saluran:</span>

              <!-- Card Email -->
              <label
                :class="[
                  'px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-2 border transition-all cursor-pointer select-none',
                  selectedChannels.includes('email')
                    ? 'border-zinc-900 bg-white text-zinc-900 shadow-2xs ring-1 ring-zinc-900/10'
                    : 'border-zinc-200 bg-white text-zinc-500 hover:border-zinc-300'
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
                    'w-3.5 h-3.5 rounded flex items-center justify-center border transition-colors shrink-0 text-white',
                    selectedChannels.includes('email') ? 'bg-zinc-900 border-zinc-900' : 'border-zinc-300 bg-white'
                  ]"
                >
                  <CheckSquare v-if="selectedChannels.includes('email')" class="w-3 h-3" />
                </div>
                <span>Email Resmi</span>
              </label>

              <!-- Card WhatsApp -->
              <label
                :class="[
                  'px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-2 border transition-all cursor-pointer select-none',
                  selectedChannels.includes('whatsapp')
                    ? 'border-zinc-900 bg-white text-zinc-900 shadow-2xs ring-1 ring-zinc-900/10'
                    : 'border-zinc-200 bg-white text-zinc-500 hover:border-zinc-300'
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
                    'w-3.5 h-3.5 rounded flex items-center justify-center border transition-colors shrink-0 text-white',
                    selectedChannels.includes('whatsapp') ? 'bg-zinc-900 border-zinc-900' : 'border-zinc-300 bg-white'
                  ]"
                >
                  <CheckSquare v-if="selectedChannels.includes('whatsapp')" class="w-3 h-3" />
                </div>
                <span>WhatsApp</span>
              </label>
            </div>

            <!-- Kirim: Langsung vs Jadwalkan (Segmented Switch) -->
            <div class="inline-flex p-0.5 bg-zinc-200/70 rounded-lg text-xs font-medium self-start sm:self-auto">
              <button
                type="button"
                @click="sendType = 'immediate'"
                :class="[
                  'px-3 py-1 rounded-md transition-all cursor-pointer select-none font-medium',
                  sendType === 'immediate' ? 'bg-white text-zinc-900 shadow-2xs font-semibold' : 'text-zinc-600 hover:text-zinc-900'
                ]"
              >
                Langsung
              </button>
              <button
                type="button"
                @click="sendType = 'scheduled'"
                :class="[
                  'px-3 py-1 rounded-md transition-all cursor-pointer select-none font-medium',
                  sendType === 'scheduled' ? 'bg-white text-zinc-900 shadow-2xs font-semibold' : 'text-zinc-600 hover:text-zinc-900'
                ]"
              >
                Jadwalkan
              </button>
            </div>
          </div>

          <!-- Peringatan jika belum pilih saluran -->
          <div v-if="!selectedChannels.length" class="text-rose-600 text-xs font-medium">
            Pilih minimal salah satu saluran pengiriman (Email atau WhatsApp).
          </div>

          <!-- Dynamic Options (WhatsApp Sender / Scheduled Inputs) -->
          <div v-if="selectedChannels.includes('whatsapp') || sendType === 'scheduled'" class="grid grid-cols-1 sm:grid-cols-2 gap-3.5 pt-0.5">
            <!-- WhatsApp Account Selector -->
            <div v-if="selectedChannels.includes('whatsapp')" class="space-y-1">
              <label class="block text-[11px] font-medium text-zinc-600">Nomor Pengirim WhatsApp</label>
              <div class="relative">
                <select
                  v-model="selectedWhatsappAccountId"
                  class="w-full h-8.5 bg-white border border-zinc-200 rounded-lg px-2.5 text-xs text-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 appearance-none pr-8 cursor-pointer shadow-2xs"
                >
                  <option v-if="!connectedWhatsappAccounts.length" :value="null">Nomor belum siap; hubungi pengelola WhatsApp</option>
                  <option v-for="account in connectedWhatsappAccounts" :key="account.id" :value="account.id">
                    {{ account.name }}{{ account.phone_number ? ` • ${account.phone_number}` : '' }}{{ account.is_default ? ' (default)' : '' }}
                  </option>
                </select>
                <ChevronDown class="w-3.5 h-3.5 text-zinc-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
              </div>
            </div>

            <!-- Scheduled Sending Inputs -->
            <div v-if="sendType === 'scheduled'" class="space-y-1" :class="[!selectedChannels.includes('whatsapp') ? 'sm:col-span-2' : '']">
              <label class="block text-[11px] font-medium text-zinc-600">Waktu Jadwal Pengiriman</label>
              <div class="flex items-center gap-2">
                <input
                  type="date"
                  v-model="scheduleDate"
                  :min="todayDateString"
                  class="h-8.5 px-2.5 bg-white border border-zinc-200 rounded-lg text-xs text-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 cursor-pointer flex-1 shadow-2xs"
                />
                <input
                  type="time"
                  v-model="scheduleTime"
                  class="h-8.5 px-2.5 bg-white border border-zinc-200 rounded-lg text-xs text-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 cursor-pointer tabular-nums w-28 shadow-2xs"
                />
                <span class="text-xs text-zinc-500 font-medium">WIB</span>
              </div>
            </div>
          </div>

          <!-- Section 2: Template Tahapan Pipeline (Clean Horizontal Pills) -->
          <div class="space-y-1.5 pt-1 border-t border-zinc-100">
            <div class="flex items-center justify-between">
              <label class="block text-xs font-semibold text-zinc-800">Template Sesuai Tahapan Pipeline</label>
              <span class="text-[11px] text-zinc-500">
                <template v-if="isBulkMode">
                  Tahap pelamar: <strong class="text-zinc-900 font-semibold">{{ getCandidateCurrentStageName(sendEmailModalApp) }}</strong>
                </template>
                <template v-else>
                  Status saat ini: <strong class="text-zinc-900 font-semibold">{{ getCandidateCurrentStageName(sendEmailModalApp) }}</strong>
                </template>
              </span>
            </div>

            <div class="flex items-center gap-1.5 overflow-x-auto no-scrollbar py-1">
              <button
                v-for="tpl in pipelineTemplateTabs"
                :key="tpl.key"
                type="button"
                @click="applyEmailTemplate(tpl.key)"
                :class="[
                  'px-3 py-1.5 text-xs rounded-lg transition-all cursor-pointer select-none shrink-0 font-medium whitespace-nowrap border',
                  activeEmailTemplateKey === tpl.key
                    ? 'bg-[#0c2340] text-white border-[#0c2340] shadow-xs font-semibold'
                    : 'bg-zinc-50 hover:bg-zinc-100 text-zinc-700 hover:text-zinc-900 border-zinc-200/80'
                ]"
              >
                {{ tpl.label }}
              </button>
            </div>
          </div>

          <!-- Section 3: Detail Sesi & Pelaksanaan (Clean Form) -->
          <div class="space-y-3 pt-2 border-t border-zinc-100">
            <div class="flex items-center justify-between">
              <label class="block text-xs font-semibold text-zinc-800">
                Detail Sesi, Lokasi & Tautan
              </label>

              <!-- Individual Schedule Toggle in Bulk Mode -->
              <label
                v-if="isBulkMode && selectedCandidatesList.length > 1"
                class="inline-flex items-center gap-1.5 cursor-pointer select-none text-xs text-zinc-700"
              >
                <input
                  type="checkbox"
                  v-model="useIndividualSchedules"
                  @change="onToggleIndividualSchedules"
                  class="rounded border-zinc-300 text-[#0c2340] focus:ring-0 w-3.5 h-3.5 cursor-pointer"
                />
                <span>Atur jam berbeda tiap pelamar</span>
              </label>
            </div>

            <!-- Single Global Schedule Inputs -->
            <div v-if="!useIndividualSchedules" class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
              <div class="space-y-1">
                <label class="block text-[11px] font-medium text-zinc-600">Jadwal / Waktu Pelaksanaan</label>
                <Input
                  type="text"
                  v-model="emailForm.schedule"
                  placeholder="Contoh: Selasa, 8 September 2026 pukul 09:00 WIB"
                  class="h-8.5 text-xs bg-white border-zinc-200 focus:border-zinc-900 shadow-2xs"
                />
              </div>

              <div class="space-y-1">
                <label class="block text-[11px] font-medium text-zinc-600">Lokasi / Media</label>
                <Input
                  type="text"
                  v-model="emailForm.venue_or_method"
                  placeholder="Contoh: Online (Google Meet) / Kantor Cirebon"
                  class="h-8.5 text-xs bg-white border-zinc-200 focus:border-zinc-900 shadow-2xs"
                />
              </div>

              <div class="space-y-1">
                <label class="block text-[11px] font-medium text-zinc-600">Welcome Link / Tautan Akses (CTA)</label>
                <Input
                  type="url"
                  v-model="emailForm.action_url"
                  placeholder="https://meet.google.com/... atau welcome link"
                  class="h-8.5 text-xs bg-white border-zinc-200 focus:border-zinc-900 shadow-2xs"
                />
              </div>

              <div class="space-y-1">
                <label class="block text-[11px] font-medium text-zinc-600">Catatan Tambahan (Instruksi)</label>
                <Input
                  type="text"
                  v-model="emailForm.special_note"
                  placeholder="Contoh: Hadir 10 menit lebih awal..."
                  class="h-8.5 text-xs bg-white border-zinc-200 focus:border-zinc-900 shadow-2xs"
                />
              </div>
            </div>

            <!-- Individual Schedule Table (when enabled in bulk mode) -->
            <div v-else class="space-y-3">
              <div class="space-y-1">
                <label class="block text-[11px] font-medium text-zinc-600">Lokasi / Media</label>
                <Input
                  type="text"
                  v-model="emailForm.venue_or_method"
                  placeholder="Contoh: Online (Google Meet) / Ruang Rapat Lt. 2"
                  class="h-8.5 text-xs bg-white border-zinc-200 focus:border-zinc-900 shadow-2xs"
                />
              </div>

              <!-- Candidate Schedule Table -->
              <div class="border border-zinc-200 rounded-xl overflow-hidden bg-white shadow-2xs">
                <div class="bg-zinc-50 border-b border-zinc-200 px-3 py-2 flex items-center justify-between text-[11px] font-semibold text-zinc-700">
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
          </div>

          <!-- Section 4: Draf Notifikasi -->
          <div class="space-y-3 pt-2 border-t border-zinc-100">
            <div class="space-y-1">
              <label class="block text-xs font-semibold text-zinc-800">Subjek Notifikasi</label>
              <Input
                type="text"
                v-model="emailForm.subject"
                placeholder="Subjek email atau ringkasan pesan..."
                class="h-8.5 text-xs font-semibold bg-white border-zinc-200 focus:border-zinc-900 shadow-2xs"
              />
            </div>

            <!-- Isi Pesan -->
            <div class="space-y-1.5">
              <div class="flex items-center justify-between flex-wrap gap-2">
                <label class="block text-xs font-semibold text-zinc-800">Isi Pesan Notifikasi</label>

                <!-- Variable Chips -->
                <div class="flex items-center gap-1 flex-wrap">
                  <span class="text-[10.5px] text-zinc-400 font-medium">Variabel:</span>
                  <button type="button" @click="insertTag('{nama_pelamar}')" class="px-2 py-0.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-700 rounded text-[10.5px] font-medium cursor-pointer transition-colors">+ {nama_pelamar}</button>
                  <button type="button" @click="insertTag('{posisi}')" class="px-2 py-0.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-700 rounded text-[10.5px] font-medium cursor-pointer transition-colors">+ {posisi}</button>
                  <button type="button" @click="insertTag('{perusahaan}')" class="px-2 py-0.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-700 rounded text-[10.5px] font-medium cursor-pointer transition-colors">+ {perusahaan}</button>
                  <button type="button" @click="insertTag('{jadwal}')" class="px-2 py-0.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-700 rounded text-[10.5px] font-medium cursor-pointer transition-colors">+ {jadwal}</button>
                  <button type="button" @click="insertTag('{link_aksi}')" class="px-2 py-0.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-700 rounded text-[10.5px] font-medium cursor-pointer transition-colors">+ {link_aksi}</button>
                </div>
              </div>

              <textarea
                ref="bodyTextareaRef"
                v-model="emailForm.body_message"
                rows="7"
                class="w-full bg-white hover:border-zinc-300 focus:border-zinc-900 border border-zinc-200 rounded-xl p-3.5 text-xs text-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 leading-relaxed font-sans shadow-2xs transition-colors resize-y min-h-[140px]"
                placeholder="Tulis pesan..."
              ></textarea>
            </div>
          </div>

          <!-- Offering Letter PDF Upload -->
          <div v-if="activeEmailTemplateKey === 'offering'" class="p-3.5 bg-zinc-50/80 rounded-xl border border-zinc-200/80 space-y-2">
            <div class="flex items-center justify-between">
              <label class="block font-semibold text-xs text-zinc-800">
                Dokumen Lampiran Offering Letter (PDF)
              </label>
              <span class="text-[10.5px] text-zinc-500">Maks. 10MB &bull; Khusus Email</span>
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
                <div class="w-7 h-7 rounded bg-zinc-100 text-zinc-700 flex items-center justify-center font-bold text-[10px] shrink-0">
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
                class="h-7 w-7 p-0 text-zinc-400 hover:text-zinc-700"
                title="Hapus Lampiran"
              >
                <X class="w-3.5 h-3.5" />
              </Button>
            </div>
          </div>
        </div>

        <!-- 3. Modal Footer -->
        <div class="px-6 py-3.5 border-t border-zinc-200 bg-zinc-50/80 flex items-center justify-between sticky bottom-0 z-20 font-sans">
          <div class="text-xs text-zinc-500">
            <template v-if="isBulkMode">
              Siap dikirim ke <strong class="text-zinc-800">{{ selectedAppIds.length }} pelamar</strong> terpilih.
            </template>
            <template v-else>
              Penerima: <strong class="text-zinc-800">{{ sendEmailModalApp.full_name }}</strong>
            </template>
          </div>

          <div class="flex items-center gap-2">
            <FButton
              theme="gray"
              variant="outline"
              size="sm"
              @click="closeNotificationModal"
            >
              Batal
            </FButton>

            <FButton
              theme="gray"
              variant="solid"
              size="sm"
              :icon-left="isSendingEmail ? RotateCw : (sendType === 'scheduled' ? CalendarClock : Send)"
              :loading="isSendingEmail"
              :disabled="isSendingEmail || !selectedChannels.length || (sendType === 'scheduled' && (!scheduleDate || !scheduleTime))"
              @click="executeSendNotification"
              class="bg-[#0c2340] hover:bg-[#153459] text-white"
            >
              {{ isSendingEmail ? 'Memproses...' : (sendType === 'scheduled' ? (isBulkMode ? `Jadwalkan untuk ${selectedAppIds.length} Pelamar` : 'Jadwalkan Notifikasi') : (isBulkMode ? `Kirim ke ${selectedAppIds.length} Pelamar` : 'Kirim Notifikasi')) }}
            </FButton>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, onActivated, onDeactivated, nextTick, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useRekrutmenStore } from '../stores/rekrutmen';
import { createPoller } from '../lib/polling';
import { createRequestKey, escapeHtml } from '../lib/utils';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';
import axios from 'axios';

// Frappe UI Components
import FButton from '../components/frappe/Button.vue';
import FBadge from 'frappe-ui/src/components/Badge/Badge.vue';
import FTextInput from '../components/frappe/TextInput.vue';
import FTextarea from 'frappe-ui/src/components/Textarea/Textarea.vue';

// Shadcn UI Components
import { Button } from '../components/ui/button';
import { Badge } from '../components/ui/badge';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '../components/ui/card';
import { Input } from '../components/ui/input';

import {
  Search, ListFilter, Kanban, ArrowLeft, ExternalLink, Eye,
  CheckCircle2, AlertCircle, Mail, Phone, FileText, RefreshCw, RotateCw, User, UserCheck, ClipboardCheck,
  Link2, Users, Bell, ChevronDown, Upload, MessageSquare, Send, CheckSquare,
  Calendar, MapPin, X, Clock, CalendarClock, UserX, Zap
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
const whatsappEngineReady = ref(false);
const connectedWhatsappAccounts = computed(() => (whatsappAccounts.value || []).filter((account) => whatsappEngineReady.value && account.is_active && account.delivery_ready === true));
const notificationProgress = ref(null);
const notificationProgressError = ref('');
let notificationRequest = null;
const notificationTerminalStatuses = ['sent', 'partial', 'failed', 'unknown', 'cancelled'];
const notificationPendingCount = computed(() => notificationProgress.value?.stats?.pending ?? ((notificationProgress.value?.stats?.email_pending || 0) + (notificationProgress.value?.stats?.whatsapp_pending || 0)));
const notificationUnknownCount = computed(() => notificationProgress.value?.stats?.unknown ?? ((notificationProgress.value?.stats?.email_unknown || 0) + (notificationProgress.value?.stats?.whatsapp_unknown || 0)));
const notificationProgressTitle = computed(() => ({
  pending: 'Notifikasi dalam antrean', processing: 'Pengiriman sedang diproses', sending: 'Pengiriman sedang diproses',
  scheduled: 'Notifikasi dijadwalkan', sent: 'Pengiriman selesai', partial: 'Pengiriman selesai sebagian',
  failed: 'Pengiriman gagal', unknown: 'Hasil pengiriman belum pasti', cancelled: 'Pengiriman dibatalkan',
}[notificationProgress.value?.status] || 'Menunggu progres pengiriman'));
const deliveryStatusLabel = (delivery) => {
  if (!delivery) return 'Tidak dipilih';
  return { pending: 'Menunggu', processing: 'Diproses', sending: 'Sedang dikirim', sent: 'Terkirim', failed: 'Gagal', unknown: 'Belum pasti', skipped: 'Dilewati', cancelled: 'Dibatalkan' }[delivery.status] || 'Menunggu';
};
const notificationPoller = createPoller({
  maxDuration: 600000,
  request: async (id, signal) => (await axios.get(`/rekrutmen/api/notifications/${id}`, { signal, timeout: 10000 })).data,
  onData: (data) => {
    notificationProgress.value = data;
    notificationProgressError.value = '';
    if (notificationTerminalStatuses.includes(data.status)) {
      store.fetchApplications('', true).catch(() => {});
      return false;
    }
  },
  onError: (error) => {
    notificationProgressError.value = error.response?.status === 403
      ? 'Anda tidak memiliki akses untuk melihat pengiriman ini.'
      : 'Progres belum dapat dimuat. Tekan Perbarui status untuk memeriksa pengiriman yang sama.';
    return false;
  },
  onTimeout: () => {
    notificationProgressError.value = 'Pembaruan otomatis dijeda. Pengiriman tetap berjalan; tekan Perbarui status untuk melihat hasil terbaru.';
  },
});
const refreshNotificationProgress = () => {
  if (!notificationProgress.value?.id) return;
  notificationProgressError.value = '';
  notificationPoller.start(notificationProgress.value.id);
};
const trackQueuedNotification = (response, total) => {
  const waitingForSchedule = response.scheduled && !notificationTerminalStatuses.includes(response.status);
  notificationProgress.value = { id: response.batch_id, status: waitingForSchedule ? 'scheduled' : (response.status || 'pending'), stats: response.stats || { total }, details: response.details || [] };
  notificationProgressError.value = waitingForSchedule ? 'Pengiriman akan dimulai sesuai jadwal. Perbarui status setelah waktu pengiriman.' : '';
  notificationPoller.stop();
  if (!response.scheduled && !notificationTerminalStatuses.includes(response.status)) refreshNotificationProgress();
};

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
  notificationPoller.stop();
});
onDeactivated(() => notificationPoller.stop());

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

const getApplicationStages = (app) => {
  if (Array.isArray(app?.pipeline_stages)) return app.pipeline_stages;
  const pipelineId = app?.job_posting?.rekrutmen_pipeline_id;
  if (!pipelineId) return stages.value;
  return stages.value.filter(stage => String(stage.rekrutmen_pipeline_id) === String(pipelineId));
};

watch(
  () => [route.query.application_id, applications.value],
  ([applicationId, availableApplications]) => {
    if (!applicationId) return;
    const application = availableApplications.find(app => String(app.id) === String(applicationId));
    if (!application) return;
    selectedApp.value = application;
    const query = { ...route.query };
    delete query.application_id;
    router.replace({ path: route.path, query });
  },
  { immediate: true }
);

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
      html: `<div class="text-xs text-slate-600 mt-1">${escapeHtml(res.message || 'Berkas CV berhasil dicocokkan ke kandidat.')}</div>`,
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

const rescreenSelectedCandidates = async () => {
  if (!selectedAppIds.value.length) return;
  const count = selectedAppIds.value.length;

  if (count === 1) {
    const targetId = selectedAppIds.value[0];
    const targetApp = applications.value.find(a => Number(a.id) === Number(targetId));
    const candidateName = targetApp ? targetApp.full_name : 'Pelamar Terpilih';

    const confirm = await Swal.fire({
      titleText: `Evaluasi AI: ${candidateName}?`,
      html: `<div class="text-xs text-slate-600 mt-1 leading-relaxed">
        Sistem AI hanya akan mengevaluasi kualifikasi CV untuk kandidat <b>${escapeHtml(candidateName)}</b> saja.<br><br>
        Apakah Anda ingin melanjutkan evaluasi AI kandidat ini?
      </div>`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Evaluasi Sekarang',
      cancelButtonText: 'Batal',
      confirmButtonColor: '#0c2340',
      cancelButtonColor: '#64748b',
      customClass: {
        popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
        title: 'text-sm font-bold text-slate-900',
      }
    });

    if (!confirm.isConfirmed) return;

    if (targetApp) {
      await rescreenSingleCandidate(targetApp);
    } else {
      isScreening.value = true;
      Swal.fire({
        title: 'Mengevaluasi Pelamar',
        html: `<div class="text-xs text-slate-500 mt-2 leading-relaxed">Menganalisis data kualifikasi kandidat...</div>`,
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
        const res = await store.analyzeCandidateWithAi(targetId);
        Swal.fire({
          icon: 'success',
          title: 'Evaluasi Berhasil',
          html: `<div class="text-xs text-slate-600 mt-1">${escapeHtml(res.message || 'Evaluasi kualifikasi berhasil diperbarui.')}</div>`,
          timer: 2000,
          showConfirmButton: false,
          iconColor: '#10b981',
          customClass: {
            popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
            title: 'text-sm font-bold text-slate-900',
          }
        });
      } catch (err) {
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
      } finally {
        isScreening.value = false;
      }
    }
    selectedAppIds.value = [];
    return;
  }

  const confirm = await Swal.fire({
    title: `Evaluasi ${count} Pelamar Terpilih?`,
    html: `<div class="text-xs text-slate-600 mt-1 leading-relaxed">
      Sistem AI hanya akan mengevaluasi kualifikasi CV untuk <b>${count} pelamar yang Anda centang</b>.<br><br>
      Apakah Anda ingin melanjutkan evaluasi ulang AI?
    </div>`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: `Evaluasi ${count} Pelamar`,
    cancelButtonText: 'Batal',
    confirmButtonColor: '#0c2340',
    cancelButtonColor: '#64748b',
    customClass: {
      popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
      title: 'text-sm font-bold text-slate-900',
    }
  });

  if (!confirm.isConfirmed) return;

  isScreening.value = true;
  Swal.fire({
    title: `Evaluasi AI: ${count} Pelamar Terpilih`,
    html: `<div class="text-xs text-slate-500 mt-2 leading-relaxed">Mengevaluasi kualifikasi pelamar yang dipilih...</div>
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
    }, [...selectedAppIds.value]);

    isScreening.value = false;
    selectedAppIds.value = [];
    Swal.fire({
      icon: 'success',
      title: 'Evaluasi Selesai',
      html: `<div class="text-xs text-slate-600 mt-1">${escapeHtml(res.message || `Evaluasi kualifikasi ${count} pelamar berhasil diperbarui.`)}</div>`,
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
      html: '<div class="text-xs text-slate-600 mt-1">Terjadi kesalahan saat memproses evaluasi kualifikasi pelamar terpilih.</div>',
      confirmButtonColor: '#739ec5',
      customClass: {
        popup: 'rounded-2xl border border-slate-100 shadow-2xl p-6 font-sans',
        title: 'text-sm font-bold text-slate-900',
        confirmButton: 'px-4 py-2 rounded-xl text-xs font-bold'
      }
    });
  }
};

const startRescreening = async () => {
  if (selectedAppIds.value.length > 0) {
    return rescreenSelectedCandidates();
  }
  // If no job filter, show warning first
  if (!activeJobId.value) {
    const confirm = await Swal.fire({
      title: 'Evaluasi Semua Pelamar?',
      html: `<div class="text-xs text-slate-600 mt-1 leading-relaxed">
        Anda tidak mencentang pelamar dan tidak sedang memfilter ke lowongan tertentu.<br><br>
        Evaluasi AI akan berjalan untuk <b>seluruh pelamar</b>.<br><br>
        <i>Tips: Centang kotak pada nama pelamar jika hanya ingin mengevaluasi 1 atau beberapa orang saja.</i>
      </div>`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Lanjutkan Evaluasi Semua',
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
    titleText: jobTitle ? `Screening AI: ${jobTitle}` : 'Evaluasi Kualifikasi',
    html: `<div class="text-xs text-slate-500 mt-2 leading-relaxed">${jobTitle ? `Mengevaluasi kandidat terhadap kualifikasi lowongan <b>${escapeHtml(jobTitle)}</b>...` : 'Menyiapkan evaluasi kandidat...'}</div>
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
      html: `<div class="text-xs text-slate-600 mt-1">${escapeHtml(res.message || 'Evaluasi kualifikasi berhasil diperbarui.')}</div>`,
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
    html: `<div class="text-xs text-slate-500 mt-2 leading-relaxed">Menganalisis data kualifikasi <b>${escapeHtml(app.full_name)}</b>...</div>`,
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
      html: `<div class="text-xs text-slate-600 mt-1">${escapeHtml(res.message || `Evaluasi untuk "${app.full_name}" berhasil diperbarui.`)}</div>`,
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
  if (!getApplicationStages(app).some(stage => String(stage.id) === String(stageId))) {
    toastType.value = 'error';
    toastMessage.value = 'Pilih tahapan dari pipeline lowongan kandidat ini.';
    setTimeout(() => { toastMessage.value = null; }, 3000);
    return;
  }
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

  if (file.size > 10 * 1024 * 1024) {
    Swal.fire({
      icon: 'warning',
      title: 'Ukuran File Terlalu Besar',
      text: 'Maksimal ukuran file dokumen adalah 10MB.',
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
    const res = await axios.get('/rekrutmen/api/whatsapp/senders');
    whatsappAccounts.value = res.data?.accounts || [];
    whatsappEngineReady.value = res.data?.engine_ready === true;
    const current = connectedWhatsappAccounts.value.find((account) => account.id === selectedWhatsappAccountId.value);
    const fallback = connectedWhatsappAccounts.value.find((account) => account.is_default) || connectedWhatsappAccounts.value[0];
    selectedWhatsappAccountId.value = current ? current.id : (fallback ? fallback.id : null);
  } catch (err) {
    whatsappAccounts.value = [];
    whatsappEngineReady.value = false;
    selectedWhatsappAccountId.value = null;
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
  { key: 'screening', label: 'Screening CV' },
  { key: 'interview_hr', label: 'Interview HR' },
  { key: 'psikotes', label: 'Psikotes' },
  { key: 'kompetensi', label: 'Tes Kompetensi' },
  { key: 'interview_user', label: 'Interview User' },
  { key: 'background_check', label: 'Background Check' },
  { key: 'offering', label: 'Offering Letter' },
  { key: 'hired', label: 'Hired' },
  { key: 'rejection', label: 'Penolakan' },
];

const getCandidateStageKey = (app) => {
  if (!app) return 'screening';
  if (app.status === 'rejected') return 'rejection';

  const stageName = (app.stage?.name || app.current_stage_name || '').toLowerCase();
  if (stageName.includes('screen')) return 'screening';
  if (stageName.includes('interview hr') || stageName === 'interview') return 'interview_hr';
  if (stageName.includes('psiko')) return 'psikotes';
  if (stageName.includes('kompetensi') || stageName.includes('tes')) return 'kompetensi';
  if (stageName.includes('interview user') || stageName.includes('user')) return 'interview_user';
  if (stageName.includes('background') || stageName.includes('check')) return 'background_check';
  if (stageName.includes('offer')) return 'offering';
  if (stageName.includes('hire')) return 'hired';

  const stageId = app.current_stage_id || app.stage?.id || 1;
  const stageObj = stages.value.find(s => s.id === stageId);
  if (stageObj) {
    const sName = (stageObj.name || '').toLowerCase();
    if (sName.includes('screen')) return 'screening';
    if (sName.includes('interview hr') || sName === 'interview') return 'interview_hr';
    if (sName.includes('psiko')) return 'psikotes';
    if (sName.includes('kompetensi') || sName.includes('tes')) return 'kompetensi';
    if (sName.includes('interview user') || sName.includes('user')) return 'interview_user';
    if (sName.includes('background') || sName.includes('check')) return 'background_check';
    if (sName.includes('offer')) return 'offering';
    if (sName.includes('hire')) return 'hired';
  }

  const stageIdMap = {
    1: 'screening',
    2: 'interview_hr',
    3: 'psikotes',
    4: 'kompetensi',
    5: 'interview_user',
    6: 'background_check',
    7: 'offering',
    8: 'hired',
  };
  return stageIdMap[stageId] || 'screening';
};

const getCandidateCurrentStageName = (app) => {
  if (!app) return '-';
  if (app.status === 'rejected') return 'Ditolak';
  if (app.stage?.name) return app.stage.name;
  const stageId = app.current_stage_id || app.stage?.id || 1;
  const stageObj = stages.value.find(s => s.id === stageId);
  return stageObj?.name || 'Screening CV';
};

const openSendEmailModal = async (app) => {
  if (!app) return;
  notificationRequest = null;
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

  // Otomatis hubungkan dengan tahapan/status pelamar saat ini
  const defaultKey = getCandidateStageKey(app);
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
  notificationRequest = null;
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

  // Otomatis hubungkan dengan tahapan pelamar yang dipilih
  const defaultKey = getCandidateStageKey(firstApp);
  applyEmailTemplate(defaultKey);
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

  const defaultActionUrl = tpl.action_url || '';
  if (key === 'interview_hr' || key === 'interview_user' || key === 'interview') {
    emailForm.value.venue_or_method = 'Online (Google Meet)';
    emailForm.value.schedule = '';
    emailForm.value.action_url = defaultActionUrl;
  } else if (key === 'psikotes') {
    emailForm.value.venue_or_method = 'Online Assessment Platform';
    emailForm.value.schedule = 'Batas Pengerjaan: 3 hari kerja';
    emailForm.value.action_url = defaultActionUrl;
  } else if (key === 'kompetensi') {
    emailForm.value.venue_or_method = 'Online Assignment / Submission';
    emailForm.value.schedule = 'Batas Pengumpulan: 3 hari kerja';
    emailForm.value.action_url = defaultActionUrl;
  } else if (key === 'background_check') {
    emailForm.value.venue_or_method = 'Online Form / Verifikasi HR';
    emailForm.value.schedule = 'Batas Pengisian: 2 hari kerja';
    emailForm.value.action_url = defaultActionUrl;
  } else if (key === 'offering') {
    emailForm.value.venue_or_method = loc;
    emailForm.value.schedule = 'Batas Konfirmasi: 3 hari kerja';
    emailForm.value.action_url = defaultActionUrl;
  } else if (key === 'hired') {
    emailForm.value.venue_or_method = `Kantor ${comp} (${loc})`;
    emailForm.value.schedule = 'Hari Pertama Masuk Kerja: 08:30 WIB';
    emailForm.value.action_url = defaultActionUrl;
  } else if (key === 'screening') {
    emailForm.value.venue_or_method = 'Tahap Peninjauan Berkas';
    emailForm.value.schedule = '';
    emailForm.value.action_url = defaultActionUrl;
  } else {
    emailForm.value.action_url = defaultActionUrl;
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
  if (!sendEmailModalApp.value || isSendingEmail.value) return;

  if (!selectedChannels.value.length) {
    Swal.fire({
      icon: 'warning',
      title: 'Pilih Kanal Notifikasi',
      text: 'Harap centang minimal salah satu kanal: Email atau WhatsApp.',
      confirmButtonColor: '#0c2340',
    });
    return;
  }

  if (selectedChannels.value.includes('whatsapp') && !selectedWhatsappAccountId.value) {
    Swal.fire({ icon: 'warning', title: 'Nomor WhatsApp Belum Siap', text: 'Pilih nomor pengirim yang siap atau gunakan kanal email.', confirmButtonColor: '#0c2340' });
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

    const fingerprint = JSON.stringify({
      fields: [...formData.entries()].map(([key, value]) => [key, value instanceof File ? [value.name, value.size, value.lastModified] : value]),
      applicationIds: isBulkMode.value ? selectedAppIds.value : [sendEmailModalApp.value.id],
      candidateSchedules: useIndividualSchedules.value ? candidateSchedules.value : null,
    });
    if (!notificationRequest || notificationRequest.fingerprint !== fingerprint) {
      notificationRequest = { fingerprint, key: createRequestKey() };
    }
    formData.append('request_key', notificationRequest.key);

    const bulk = isBulkMode.value;
    const app = sendEmailModalApp.value;
    const count = bulk ? selectedAppIds.value.length : 1;
    if (bulk) {
      selectedAppIds.value.forEach((id) => formData.append('application_ids[]', id));
      if (useIndividualSchedules.value) {
        formData.append('candidate_schedules', JSON.stringify(candidateSchedules.value));
      }
    }
    const endpoint = bulk ? '/rekrutmen/api/applications/bulk-send-notification' : `/rekrutmen/api/applications/${app.id}/send-notification`;
    const res = await axios.post(endpoint, formData, { headers: { 'Content-Type': 'multipart/form-data' } });
    isSendingEmail.value = false;

    if (res.data.batch_id && (res.status === 202 || res.data.queued || res.data.scheduled)) {
      trackQueuedNotification(res.data, count);
      if (bulk) selectedAppIds.value = [];
      closeNotificationModal();
      notificationRequest = null;
      return;
    }

    if (bulk) {
      throw new Error('Respons antrean belum lengkap. Periksa status sebelum membuat pengiriman baru.');
    }
    if (res.data.batch_id) trackQueuedNotification(res.data, 1);
    closeNotificationModal();
    notificationRequest = null;
    if (res.data?.new_stage) {
      app.current_stage_id = res.data.new_stage.id;
      const targetStage = stages.value.find((stage) => String(stage.id) === String(res.data.new_stage.id));
      if (targetStage) app.stage = { id: targetStage.id, name: targetStage.name, color: targetStage.color };
    }
    await store.fetchApplications('', true).catch(() => {});
    const resultEntries = Object.values(res.data.results || {}).filter((result) => result && typeof result === 'object');
    const incomplete = res.data.success === false || resultEntries.some((result) => result.success === false || ['failed', 'unknown', 'pending', 'skipped'].includes(result.status));
    Swal.fire({
      icon: incomplete ? 'warning' : 'success',
      title: res.data.status === 'unknown' ? 'Hasil Pengiriman Belum Pasti' : (incomplete ? 'Sebagian notifikasi belum terkirim' : (res.data.scheduled ? 'Notifikasi Dijadwalkan' : 'Notifikasi Terkirim')),
      text: res.data.message || 'Hasil pengiriman telah diperbarui.',
      confirmButtonColor: '#2563eb',
    });
  } catch (err) {
    isSendingEmail.value = false;
    if (err.response?.data?.batch_id) {
      trackQueuedNotification(err.response.data, isBulkMode.value ? selectedAppIds.value.length : 1);
      closeNotificationModal();
      notificationRequest = null;
    }
    Swal.fire({
      icon: err.response?.data?.status === 'unknown' ? 'warning' : 'error',
      title: err.response?.data?.status === 'unknown' ? 'Hasil Pengiriman Belum Pasti' : (sendType.value === 'scheduled' ? 'Gagal Menjadwalkan Notifikasi' : 'Gagal Mengirim Notifikasi'),
      text: err.response?.data?.message || (err.response ? 'Terjadi kesalahan saat memproses notifikasi.' : 'Respons pengiriman belum diterima. Jangan membuat pengiriman baru; mencoba lagi dengan formulir yang sama memakai permintaan yang sama.'),
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
