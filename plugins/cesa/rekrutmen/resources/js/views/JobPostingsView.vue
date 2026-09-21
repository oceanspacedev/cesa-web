<template>
  <div class="space-y-4 pb-12">
    <!-- Top Header: Title, Quick Metrics & Primary Actions (Elevated "Asoy" Card) -->
    <div class="p-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3.5">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-surface-gray-2 border border-outline-gray-2 flex items-center justify-center shrink-0 text-ink-gray-8 shadow-2xs">
          <Briefcase class="w-5 h-5 stroke-[1.75]" />
        </div>
        <div>
          <div class="flex items-center gap-2.5">
            <h1 class="text-base sm:text-lg font-bold text-ink-gray-9 tracking-tight">Lowongan Pekerjaan</h1>
            <FBadge theme="green" variant="subtle" size="sm" class="tabular-nums font-semibold">
              <template #prefix>
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
              </template>
              {{ publishedCount }} Tayang Aktif
            </FBadge>
          </div>
          <p class="text-xs text-ink-gray-5 mt-0.5">
            Kelola posisi lowongan, pantau kuota penempatan, dan proses kandidat pelamar
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

        <FButton
          theme="gray"
          variant="solid"
          size="sm"
          :icon-left="Plus"
          @click="openCreateSheet"
        >
          Tambah Lowongan
        </FButton>
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
          class="fixed bottom-6 right-6 z-50 max-w-sm w-auto p-3 rounded-lg border border-zinc-200 bg-white text-zinc-900 shadow-lg flex items-center gap-3 text-xs font-medium select-none"
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
            class="text-zinc-400 hover:text-zinc-700 font-bold p-1 rounded hover:bg-zinc-100 cursor-pointer ml-auto"
          >
            &times;
          </button>
        </div>
      </transition>
    </teleport>

    <!-- Metrics Strip (Comfortable vertical proportion, clean & calibrated) -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Total Lowongan</span>
          <span class="text-xl font-bold text-ink-gray-9 mt-1 block tabular-nums">{{ postings.length }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Semua posisi terdaftar</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-gray-2 text-ink-gray-6 flex items-center justify-center shrink-0">
          <Briefcase class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>

      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Tayang Aktif</span>
          <span class="text-xl font-bold text-emerald-700 mt-1 block tabular-nums">{{ publishedCount }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Dipublikasikan di portal</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-green-2 text-surface-green-3 flex items-center justify-center shrink-0">
          <CheckCircle2 class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>

      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Draft</span>
          <span class="text-xl font-bold text-ink-gray-8 mt-1 block tabular-nums">{{ draftCount }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Belum dipublikasikan</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-gray-2 text-ink-gray-6 flex items-center justify-center shrink-0">
          <Clock class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>

      <div class="py-4 px-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex items-center justify-between">
        <div>
          <span class="text-xs font-medium text-ink-gray-5 block">Total Pelamar</span>
          <span class="text-xl font-bold text-ink-gray-9 mt-1 block tabular-nums">{{ totalApplicationsCount }}</span>
          <span class="text-[11px] text-ink-gray-4 block mt-0.5">Seluruh berkas masuk</span>
        </div>
        <div class="w-9 h-9 rounded-lg bg-surface-blue-2 text-surface-blue-3 flex items-center justify-center shrink-0">
          <Users class="w-4.5 h-4.5 stroke-[1.75]" />
        </div>
      </div>
    </div>

    <!-- Filters & Search Toolbar -->
    <div class="p-2.5 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex flex-col md:flex-row gap-2.5 items-stretch md:items-center justify-between">
      <!-- Status Filter Tabs -->
      <div class="inline-flex items-center p-0.5 bg-surface-gray-2 rounded-md border border-outline-gray-2">
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
          <span>Semua</span>
          <span :class="['text-[10px] tabular-nums leading-none', statusFilter === 'all' ? 'text-ink-gray-8 font-semibold' : 'text-ink-gray-4']">
            {{ postings.length }}
          </span>
        </button>
        <button
          type="button"
          @click="statusFilter = 'published'"
          :class="[
            'px-2.5 h-7 rounded text-xs font-medium transition-all cursor-pointer inline-flex items-center justify-center gap-1.5 select-none leading-none',
            statusFilter === 'published'
              ? 'bg-surface-white text-emerald-800 shadow-2xs font-semibold'
              : 'text-ink-gray-5 hover:text-ink-gray-8'
          ]"
        >
          <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0"></span>
          <span>Tayang</span>
          <span :class="['text-[10px] tabular-nums leading-none', statusFilter === 'published' ? 'text-emerald-800 font-semibold' : 'text-ink-gray-4']">
            {{ publishedCount }}
          </span>
        </button>
        <button
          type="button"
          @click="statusFilter = 'draft'"
          :class="[
            'px-2.5 h-7 rounded text-xs font-medium transition-all cursor-pointer inline-flex items-center justify-center gap-1.5 select-none leading-none',
            statusFilter === 'draft'
              ? 'bg-surface-white text-ink-gray-8 shadow-2xs font-semibold'
              : 'text-ink-gray-5 hover:text-ink-gray-8'
          ]"
        >
          <span class="w-1.5 h-1.5 rounded-full bg-zinc-400 shrink-0"></span>
          <span>Draft</span>
          <span :class="['text-[10px] tabular-nums leading-none', statusFilter === 'draft' ? 'text-ink-gray-8 font-semibold' : 'text-ink-gray-4']">
            {{ draftCount }}
          </span>
        </button>
      </div>

      <!-- Right: Company Filter & Search -->
      <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
        <div class="relative min-w-[200px]">
          <select
            v-model="companyFilter"
            class="w-full h-8 bg-surface-white border border-outline-gray-2 rounded-md pl-3 pr-8 text-xs text-ink-gray-8 hover:border-outline-gray-3 focus:outline-none focus:ring-1 focus:ring-outline-gray-4 appearance-none cursor-pointer"
          >
            <option value="all">Semua Perusahaan</option>
            <option v-for="comp in companies" :key="comp.id" :value="String(comp.id)">
              {{ comp.name }}
            </option>
          </select>
          <ChevronDown class="w-3.5 h-3.5 text-ink-gray-4 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
        </div>

        <FTextInput
          v-model="searchQuery"
          size="md"
          variant="outline"
          placeholder="Cari posisi atau lokasi..."
          class="w-full sm:w-64"
        >
          <template #prefix>
            <Search class="w-3.5 h-3.5 text-zinc-400" />
          </template>
        </FTextInput>
      </div>
    </div>

    <!-- SKELETON LOADING STATE (Grid of 6 Cards) -->
    <div v-if="isLoading" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3.5">
      <div
        v-for="i in 6"
        :key="i"
        class="p-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs space-y-3"
      >
        <div class="flex items-center justify-between">
          <div class="h-3.5 w-28 bg-surface-gray-2 rounded animate-pulse"></div>
          <div class="h-4.5 w-14 bg-surface-gray-2 rounded-full animate-pulse"></div>
        </div>
        <div class="h-4.5 w-44 bg-surface-gray-2 rounded animate-pulse"></div>
        <div class="flex items-center gap-3">
          <div class="h-3 w-24 bg-surface-gray-2 rounded animate-pulse"></div>
          <div class="h-3 w-28 bg-surface-gray-2 rounded animate-pulse"></div>
        </div>
        <div class="pt-3 border-t border-outline-gray-1 flex items-center justify-between">
          <div class="h-3 w-24 bg-surface-gray-2 rounded animate-pulse"></div>
          <div class="flex items-center gap-1.5">
            <div class="h-7 w-12 bg-surface-gray-2 rounded-md animate-pulse"></div>
            <div class="h-7 w-16 bg-surface-gray-2 rounded-md animate-pulse"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- EMPTY STATE -->
    <div
      v-else-if="!filteredPostings.length"
      class="p-12 text-center bg-surface-white rounded-lg border border-dashed border-outline-gray-3 shadow-2xs"
    >
      <div class="w-10 h-10 rounded-full bg-surface-gray-2 text-ink-gray-5 flex items-center justify-center mx-auto mb-3">
        <Briefcase class="w-5 h-5 stroke-[1.5]" />
      </div>
      <h3 class="text-sm font-semibold text-ink-gray-9">Tidak ada lowongan ditemukan</h3>
      <p class="text-xs text-ink-gray-5 mt-1 max-w-sm mx-auto">
        Tidak ada data lowongan yang sesuai dengan kriteria filter atau pencarian Anda.
      </p>
      <div class="mt-4 flex items-center justify-center gap-2">
        <FButton
          theme="gray"
          variant="outline"
          size="sm"
          @click="resetFilters"
        >
          Reset Filter
        </FButton>
        <FButton
          theme="gray"
          variant="solid"
          size="sm"
          :icon-left="Plus"
          @click="openCreateSheet"
        >
          Tambah Lowongan
        </FButton>
      </div>
    </div>

    <!-- PRIMARY CARD GRID VIEW (No Table) -->
    <div
      v-else
      class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3.5"
    >
      <div
        v-for="job in filteredPostings"
        :key="job.id"
        class="bg-surface-white rounded-lg border border-outline-gray-2 hover:border-outline-gray-3 hover:shadow-xs transition-all duration-150 p-4 flex flex-col justify-between space-y-3 cursor-pointer group"
        @click="inspectJob(job)"
      >
        <div class="space-y-2">
          <!-- Top Row: Company & Status Badge -->
          <div class="flex items-center justify-between gap-2">
            <span class="text-[11px] font-medium text-ink-gray-5 truncate block">
              {{ job.company_name || 'PT Complete Selular Group' }}
            </span>
            <FBadge
              :theme="job.is_published ? 'green' : 'gray'"
              variant="subtle"
              size="sm"
              class="cursor-pointer select-none shrink-0"
              @click.stop="togglePublish(job)"
              :title="job.is_published ? 'Klik untuk jadikan Draft' : 'Klik untuk Tayangkan'"
            >
              <template #prefix>
                <span
                  :class="[
                    'w-1.5 h-1.5 rounded-full shrink-0',
                    job.is_published ? 'bg-emerald-500' : 'bg-surface-gray-5'
                  ]"
                ></span>
              </template>
              {{ job.is_published ? 'Tayang' : 'Draft' }}
            </FBadge>
          </div>

          <!-- Position Title -->
          <h2 class="text-sm font-semibold text-ink-gray-9 group-hover:text-blue-900 transition-colors leading-snug line-clamp-2">
            {{ job.title }}
          </h2>

          <!-- Location & Closing Date -->
          <div class="flex flex-wrap items-center gap-y-1 gap-x-3 text-[11px] text-ink-gray-5">
            <div class="flex items-center gap-1.5 truncate">
              <MapPin class="w-3.5 h-3.5 text-zinc-400 shrink-0" />
              <span class="truncate">{{ job.location || 'Indonesia' }}</span>
            </div>
            <div class="flex items-center gap-1.5 tabular-nums text-ink-gray-4">
              <Clock class="w-3.5 h-3.5 text-zinc-400 shrink-0" />
              <span>Batas: {{ job.closing_date_formatted || '-' }}</span>
            </div>
          </div>
        </div>

        <!-- Card Footer: Metrics & Action Buttons -->
        <div class="pt-3 border-t border-outline-gray-1 flex items-center justify-between text-[11px] text-ink-gray-5">
          <div class="flex items-center gap-2">
            <span class="font-medium text-ink-gray-8 tabular-nums">{{ job.applications_count || 0 }} Pelamar</span>
            <span class="text-ink-gray-3">&bull;</span>
            <span class="tabular-nums">{{ job.needed_count || 1 }} Kuota</span>
          </div>

          <div class="flex items-center gap-1.5" @click.stop>
            <FButton
              theme="gray"
              variant="ghost"
              size="sm"
              @click="inspectJob(job)"
            >
              Detail
            </FButton>
            <FButton
              theme="gray"
              variant="outline"
              size="sm"
              :icon-left="Edit3"
              @click="openEditSheet(job)"
            >
              Edit
            </FButton>
            <FButton
              theme="gray"
              variant="solid"
              size="sm"
              :icon-left="Users"
              @click="goToApplications(job)"
            >
              Pelamar
            </FButton>
            <FButton
              theme="red"
              variant="ghost"
              size="sm"
              :icon="Trash2"
              @click="handleDeleteJob(job)"
              title="Hapus Lowongan"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- SLIDE-OVER INSPECTOR & FORM DRAWER (Sheet) -->
    <Sheet :open="isSheetOpen" @update:open="handleSheetUpdate">
      <SheetContent class="sm:max-w-xl" data-job-posting-sheet>
        <!-- MODE 1: VIEW / INSPECT JOB SPECIFICATION -->
        <div v-if="sheetMode === 'view' && activeJob" class="flex flex-col flex-1 overflow-hidden bg-white">
          <!-- Drawer Header -->
          <div class="px-6 py-5 pr-14 border-b border-zinc-100">
            <div class="space-y-1">
              <div class="flex items-center gap-2">
                <SheetTitle class="text-base sm:text-lg font-semibold text-zinc-900 truncate">
                  {{ activeJob.title }}
                </SheetTitle>
                <span class="text-xs font-medium text-zinc-400 tabular-nums">#{{ activeJob.id }}</span>
              </div>
              <div class="flex items-center gap-2 text-xs text-zinc-500 flex-wrap">
                <span>{{ activeJob.company_name || 'PT Complete Selular Group' }}</span>
                <template v-if="activeJob.location">
                  <span class="text-zinc-300">&bull;</span>
                  <span>{{ activeJob.location }}</span>
                </template>
              </div>
            </div>
          </div>

          <!-- Action Toolbar -->
          <div class="px-6 py-2.5 bg-zinc-50/70 border-b border-zinc-100 flex items-center justify-between gap-2 shrink-0">
            <div class="flex items-center gap-2">
              <FButton
                theme="gray"
                variant="solid"
                size="sm"
                @click="goToApplications(activeJob)"
              >
                <template #prefix><Users class="w-3.5 h-3.5" /></template>
                Pelamar ({{ activeJob.applications_count || 0 }})
              </FButton>

              <FButton
                theme="gray"
                variant="outline"
                size="sm"
                @click="openEditFromInspect"
              >
                <template #prefix><Edit3 class="w-3.5 h-3.5" /></template>
                Edit
              </FButton>

              <FButton
                theme="gray"
                variant="outline"
                size="sm"
                @click="copyJobLink(activeJob)"
              >
                <template #prefix><Copy class="w-3.5 h-3.5" /></template>
                Salin Info
              </FButton>
            </div>

            <!-- Publish Status Badge / Toggle (positioned in toolbar away from close button) -->
            <button
              type="button"
              @click="togglePublish(activeJob)"
              class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium transition-colors shrink-0 cursor-pointer"
              :class="activeJob.is_published ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200'"
              title="Klik untuk mengubah status publikasi"
            >
              <span class="w-1.5 h-1.5 rounded-full" :class="activeJob.is_published ? 'bg-emerald-500' : 'bg-zinc-400'"></span>
              {{ activeJob.is_published ? 'Tayang Aktif' : 'Draft' }}
            </button>
          </div>

          <!-- Key Metrics Strip (Clean grid, zero nested box borders) -->
          <div class="px-6 py-3.5 grid grid-cols-3 gap-4 border-b border-zinc-100 bg-white text-xs">
            <div>
              <span class="text-zinc-400 block text-[11px] mb-0.5">Kuota Posisi</span>
              <span class="font-semibold text-zinc-900 tabular-nums">{{ activeJob.needed_count || 1 }} Orang</span>
            </div>
            <div>
              <span class="text-zinc-400 block text-[11px] mb-0.5">Total Pelamar</span>
              <span class="font-semibold text-zinc-900 tabular-nums">{{ activeJob.applications_count || 0 }} Kandidat</span>
            </div>
            <div>
              <span class="text-zinc-400 block text-[11px] mb-0.5">Batas Lamaran</span>
              <span class="font-semibold text-zinc-900 tabular-nums">{{ activeJob.closing_date_formatted || '-' }}</span>
            </div>
          </div>

          <!-- Content Body -->
          <div class="p-6 space-y-6 overflow-y-auto flex-1 text-xs text-zinc-700 leading-relaxed">
            <!-- Deskripsi -->
            <div class="space-y-2">
              <h3 class="text-[11px] font-semibold uppercase tracking-wider text-zinc-400">Deskripsi Tugas & Tanggung Jawab</h3>
              <div v-if="activeJob.description" class="whitespace-pre-line text-zinc-800 text-xs leading-relaxed">
                {{ activeJob.description }}
              </div>
              <p v-else class="text-zinc-400 italic text-xs">Belum ada deskripsi tugas yang dicantumkan.</p>
            </div>

            <!-- Kualifikasi & Persyaratan -->
            <div class="space-y-2">
              <h3 class="text-[11px] font-semibold uppercase tracking-wider text-zinc-400">Kualifikasi & Persyaratan</h3>
              <ul v-if="parsedRequirements.length" class="space-y-2 text-xs text-zinc-800">
                <li v-for="(req, idx) in parsedRequirements" :key="idx" class="flex items-start gap-2.5">
                  <span class="text-zinc-300 select-none mt-0.5 text-sm leading-none">&bull;</span>
                  <span class="flex-1 leading-relaxed">{{ req }}</span>
                </li>
              </ul>
              <div v-else-if="activeJob.requirements" class="whitespace-pre-line text-zinc-800 text-xs leading-relaxed">
                {{ activeJob.requirements }}
              </div>
              <p v-else class="text-zinc-400 italic text-xs">Belum ada rincian kualifikasi yang dicantumkan.</p>
            </div>

            <!-- Banner (Only shown if banner exists) -->
            <div v-if="activeJob.thumbnail_url" class="space-y-2">
              <h3 class="text-[11px] font-semibold uppercase tracking-wider text-zinc-400">Banner Lowongan</h3>
              <div class="p-1.5 rounded-lg border border-zinc-200 inline-block bg-zinc-50">
                <img
                  :src="activeJob.thumbnail_url"
                  alt="Banner Lowongan"
                  class="max-h-56 rounded object-contain bg-white"
                />
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div class="px-6 py-3 border-t border-zinc-100 flex items-center justify-between shrink-0 bg-zinc-50/50">
            <button
              type="button"
              @click="handleDeleteJob(activeJob)"
              class="text-xs font-medium text-rose-600 hover:text-rose-700 transition-colors cursor-pointer flex items-center gap-1.5"
            >
              <Trash2 class="w-3.5 h-3.5" />
              <span>Hapus Posisi</span>
            </button>
            <FButton
              type="button"
              theme="gray"
              variant="outline"
              size="sm"
              @click="closeSheet"
            >
              Tutup
            </FButton>
          </div>
        </div>

        <!-- Mode: Create / Edit Form -->
        <div v-else class="flex flex-col h-full overflow-hidden">
          <SheetHeader class="p-6 border-b border-zinc-100 shrink-0">
            <SheetTitle class="text-base font-bold text-zinc-900">
              {{ isEditMode ? 'Edit Lowongan Pekerjaan' : 'Tambah Lowongan Pekerjaan Baru' }}
            </SheetTitle>
            <SheetDescription class="text-xs text-zinc-500">
              {{ isEditMode ? 'Perbarui informasi detail posisi dan status penayangan lowongan.' : 'Lengkapi formulir untuk membuat lowongan pekerjaan baru.' }}
            </SheetDescription>
          </SheetHeader>

          <form @submit.prevent="saveEditJob" class="flex-1 overflow-y-auto p-6 space-y-4">
            <!-- Alert jika dari FPTK -->
            <div v-if="isEditMode && editingJob?.context_description" class="p-3 bg-amber-50/80 rounded-lg border border-amber-200 text-xs text-amber-900">
              <span class="font-semibold block">Catatan FPTK Terkait:</span>
              {{ editingJob.context_description }}
            </div>

            <div class="space-y-3.5">
              <!-- Judul Posisi -->
              <div>
                <label class="block text-xs font-semibold text-zinc-700 mb-1.5">
                  Judul Posisi Lowongan <span class="text-rose-500">*</span>
                </label>
                <FTextInput
                  v-model="editForm.title"
                  placeholder="Contoh: Senior Fullstack Developer, Account Executive, dsb."
                  size="sm"
                  variant="outline"
                  required
                />
              </div>

              <!-- Perusahaan & Pipeline -->
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-semibold text-zinc-700 mb-1.5">Perusahaan / PT</label>
                  <div class="relative">
                    <select
                      v-model="editForm.company_id"
                      class="w-full h-8 bg-white border border-zinc-200 rounded-md px-3 text-xs text-zinc-800 hover:border-zinc-300 focus:outline-none focus:ring-1 focus:ring-zinc-900 appearance-none pr-8 cursor-pointer transition-colors shadow-2xs"
                    >
                      <option :value="null">-- Default (PT Complete Selular Group) --</option>
                      <option v-for="c in companies" :key="c.id" :value="c.id">
                        {{ c.name }}
                      </option>
                    </select>
                    <ChevronDown class="w-3.5 h-3.5 text-zinc-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                  </div>
                </div>

                <div>
                  <label class="block text-xs font-semibold text-zinc-700 mb-1.5">Pipeline Tahapan Seleksi</label>
                  <div class="relative">
                    <select
                      v-model="editForm.rekrutmen_pipeline_id"
                      class="w-full h-8 bg-white border border-zinc-200 rounded-md px-3 text-xs text-zinc-800 hover:border-zinc-300 focus:outline-none focus:ring-1 focus:ring-zinc-900 appearance-none pr-8 cursor-pointer transition-colors shadow-2xs"
                    >
                      <option v-for="p in pipelinesList" :key="p.id" :value="p.id">
                        {{ p.name }}
                      </option>
                    </select>
                    <ChevronDown class="w-3.5 h-3.5 text-zinc-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                  </div>
                </div>
              </div>

              <!-- Lokasi Kerja & Batas Waktu Lamaran -->
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-semibold text-zinc-700 mb-1.5">Lokasi Kerja</label>
                  <FTextInput
                    v-model="editForm.location"
                    size="sm"
                    variant="outline"
                    placeholder="Kota Cirebon, Jakarta, dsb."
                  />
                </div>

                <div>
                  <label class="block text-xs font-semibold text-zinc-700 mb-1.5">Batas Waktu Lamaran</label>
                  <FTextInput
                    v-model="editForm.closing_date"
                    type="date"
                    size="sm"
                    variant="outline"
                  />
                </div>
              </div>

              <!-- Status Publikasi -->
              <div>
                <label class="block text-xs font-semibold text-zinc-700 mb-1.5">Status Publikasi</label>
                <div class="grid grid-cols-2 gap-2 h-8 max-w-xs">
                  <!-- Tayang Aktif (Emerald Green) -->
                  <label
                    class="flex items-center justify-center gap-1.5 px-3 h-8 rounded-md border text-xs cursor-pointer transition-all select-none"
                    :class="editForm.publication_state === 'active'
                      ? 'bg-emerald-50 border-emerald-400 text-emerald-800 font-semibold shadow-2xs ring-1 ring-emerald-300'
                      : 'bg-white border-zinc-200 text-zinc-600 hover:bg-zinc-50 hover:border-zinc-300'"
                  >
                    <input
                      type="radio"
                      name="publication_state"
                      value="active"
                      v-model="editForm.publication_state"
                      class="sr-only"
                    />
                    <span
                      class="w-2 h-2 rounded-full shrink-0 transition-all"
                      :class="editForm.publication_state === 'active' ? 'bg-emerald-500 ring-2 ring-emerald-200' : 'bg-zinc-300'"
                    ></span>
                    <span>Tayang Aktif</span>
                  </label>

                  <!-- Draft (Warm Amber) -->
                  <label
                    class="flex items-center justify-center gap-1.5 px-3 h-8 rounded-md border text-xs cursor-pointer transition-all select-none"
                    :class="editForm.publication_state === 'draft'
                      ? 'bg-amber-50 border-amber-400 text-amber-800 font-semibold shadow-2xs ring-1 ring-amber-300'
                      : 'bg-white border-zinc-200 text-zinc-600 hover:bg-zinc-50 hover:border-zinc-300'"
                  >
                    <input
                      type="radio"
                      name="publication_state"
                      value="draft"
                      v-model="editForm.publication_state"
                      class="sr-only"
                    />
                    <span
                      class="w-2 h-2 rounded-full shrink-0 transition-all"
                      :class="editForm.publication_state === 'draft' ? 'bg-amber-500 ring-2 ring-amber-200' : 'bg-zinc-300'"
                    ></span>
                    <span>Draft</span>
                  </label>
                </div>
              </div>

              <!-- Deskripsi Tugas -->
              <div>
                <label class="block text-xs font-semibold text-zinc-700 mb-1.5">Deskripsi Tugas & Tanggung Jawab</label>
                <FTextarea
                  v-model="editForm.description"
                  size="sm"
                  variant="outline"
                  :rows="4"
                  placeholder="• Tugas dan tanggung jawab harian posisi ini..."
                />
              </div>

              <!-- Kualifikasi -->
              <div>
                <label class="block text-xs font-semibold text-zinc-700 mb-1.5">Kualifikasi & Persyaratan</label>
                <FTextarea
                  v-model="editForm.requirements"
                  size="sm"
                  variant="outline"
                  :rows="4"
                  placeholder="• Minimal pendidikan...&#10;• Pengalaman kerja...&#10;• Kemampuan teknis..."
                />
              </div>

              <!-- Media Banner -->
              <div>
                <label class="block text-xs font-semibold text-zinc-700 mb-1.5">Banner Lowongan (Opsional)</label>
                <div class="border border-zinc-200 rounded-lg p-3 flex items-center justify-between gap-3 bg-zinc-50/60">
                  <div class="flex items-center gap-3 min-w-0">
                    <div class="w-11 h-11 rounded-md bg-white border border-zinc-200 text-zinc-400 flex items-center justify-center shrink-0 overflow-hidden shadow-2xs">
                      <img
                        v-if="(thumbnailPreview || editForm.thumbnail_url) && !isThumbnailRemoved"
                        :src="thumbnailPreview || editForm.thumbnail_url"
                        alt="Banner"
                        class="w-full h-full object-cover"
                        @error="editForm.thumbnail_url = null"
                      />
                      <ImageIcon v-else class="w-5 h-5 text-zinc-400 stroke-1" />
                    </div>
                    <div class="min-w-0">
                      <span class="text-xs font-medium text-zinc-800 block truncate">
                        {{ (thumbnailPreview || editForm.thumbnail_url) && !isThumbnailRemoved ? (thumbnailFileName || 'Banner Terpasang') : 'Belum ada gambar terpilih' }}
                      </span>
                      <span class="text-[11px] text-zinc-400 block mt-0.5">JPG, PNG, WEBP (Maksimal 5MB)</span>
                    </div>
                  </div>

                  <div class="flex items-center gap-2 shrink-0">
                    <label class="cursor-pointer">
                      <span class="inline-flex items-center justify-center px-3 py-1.5 rounded-md border border-zinc-200 bg-white text-xs font-medium text-zinc-700 shadow-2xs hover:bg-zinc-50 transition-colors">
                        {{ (thumbnailPreview || editForm.thumbnail_url) && !isThumbnailRemoved ? 'Ganti' : 'Pilih File' }}
                      </span>
                      <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="handleThumbnailChange" />
                    </label>

                    <button
                      v-if="(thumbnailPreview || editForm.thumbnail_url) && !isThumbnailRemoved"
                      type="button"
                      @click="removeThumbnail"
                      class="text-rose-600 hover:text-rose-700 text-xs px-2 py-1 rounded cursor-pointer transition-colors"
                    >
                      Hapus
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Form Footer -->
            <div class="px-6 py-3.5 border-t border-zinc-100 bg-zinc-50/50 flex items-center justify-end gap-2 shrink-0">
              <FButton
                type="button"
                theme="gray"
                variant="outline"
                size="sm"
                @click="cancelForm"
              >
                Batal
              </FButton>
              <FButton
                type="submit"
                theme="gray"
                variant="solid"
                size="sm"
                :loading="isSubmitting"
                loading-text="Menyimpan..."
                class="min-w-[130px]"
              >
                {{ isEditMode ? 'Simpan Perubahan' : 'Terbitkan Lowongan' }}
              </FButton>
            </div>
          </form>
        </div>
      </SheetContent>
    </Sheet>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, onDeactivated, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useRekrutmenStore } from '../stores/rekrutmen';
import { filterJobPostings } from '../lib/jobPostings';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';

// Frappe UI Components
import FButton from '../components/frappe/Button.vue';
import FBadge from 'frappe-ui/src/components/Badge/Badge.vue';
import FTextInput from '../components/frappe/TextInput.vue';
import FTextarea from 'frappe-ui/src/components/Textarea/Textarea.vue';

// Shadcn UI Components
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetFooter } from '../components/ui/sheet';

// Icons
import {
  Search,
  MapPin,
  Building2,
  Users,
  Briefcase,
  FileText,
  Edit3,
  ArrowRight,
  CheckCircle2,
  Clock,
  Image as ImageIcon,
  ChevronDown,
  RotateCw,
  Copy,
  Plus,
  Trash2,
  GitBranch
} from 'lucide-vue-next';

const store = useRekrutmenStore();
const route = useRoute();
const router = useRouter();


const isLoading = ref(true);
const isRefreshing = ref(false);
const statusFilter = ref('all');
const companyFilter = ref('all');
const searchQuery = ref('');

// Sheet Inspector & Form state
const isSheetOpen = ref(false);
const sheetMode = ref('view'); // 'view' | 'edit' | 'create'
const activeJob = ref(null);

const parsedRequirements = computed(() => {
  if (!activeJob.value?.requirements) return [];
  return activeJob.value.requirements
    .split(/\r?\n/)
    .map(line => line.trim())
    .filter(Boolean);
});
const editingJob = ref(null);
const isEditMode = ref(false);
const isSubmitting = ref(false);
const toastMessage = ref(null);
const toastType = ref('success');

const thumbnailFile = ref(null);
const thumbnailPreview = ref(null);
const thumbnailFileName = ref('');
const isThumbnailRemoved = ref(false);

const pipelinesList = computed(() => {
  return store.pipelines?.length ? store.pipelines : (store.configurations?.pipelines || [{ id: 1, name: 'Default Recruitment Pipeline' }]);
});

const editForm = ref({
  title: '',
  company_id: null,
  rekrutmen_pipeline_id: 1,
  location: '',
  description: '',
  requirements: '',
  closing_date: '',
  publication_state: 'active',
  thumbnail_url: null,
});

const goToApplications = (job) => {
  isSheetOpen.value = false;
  router.push({
    path: '/admin/job-applications',
    query: { job_id: job.id }
  });
};

const inspectJob = (job) => {
  activeJob.value = job;
  sheetMode.value = 'view';
  isSheetOpen.value = true;
};

const openCreateSheet = () => {
  editingJob.value = null;
  isEditMode.value = false;
  sheetMode.value = 'create';
  thumbnailFile.value = null;
  thumbnailPreview.value = null;
  thumbnailFileName.value = '';
  isThumbnailRemoved.value = false;
  editForm.value = {
    title: '',
    company_id: companies.value[0]?.id || null,
    rekrutmen_pipeline_id: pipelinesList.value[0]?.id || 1,
    location: '',
    description: '',
    requirements: '',
    closing_date: '',
    publication_state: 'active',
    thumbnail_url: null,
  };
  isSheetOpen.value = true;
};

const openEditSheet = (job) => {
  activeJob.value = job;
  editingJob.value = job;
  isEditMode.value = true;
  sheetMode.value = 'edit';
  thumbnailFile.value = null;
  thumbnailPreview.value = null;
  thumbnailFileName.value = job.thumbnail_path ? job.thumbnail_path.split('/').pop() : '';
  isThumbnailRemoved.value = false;
  editForm.value = {
    title: job.title || '',
    company_id: job.company_id || null,
    rekrutmen_pipeline_id: job.rekrutmen_pipeline_id || 1,
    location: job.location || '',
    description: job.description || '',
    requirements: job.requirements || '',
    closing_date: job.closing_date ? job.closing_date.split('T')[0] : '',
    publication_state: job.is_published ? 'active' : 'draft',
    thumbnail_url: job.thumbnail_url || null,
  };
  isSheetOpen.value = true;
};

const openEditFromInspect = () => {
  if (activeJob.value) {
    openEditSheet(activeJob.value);
  }
};

const handleSheetUpdate = (val) => {
  isSheetOpen.value = val;
  if (!val) {
    if (route.name === 'postings' && (route.query.edit_id || route.query.id)) {
      router.replace({ path: route.path, query: {} });
    }
  }
};

const closeSheet = () => {
  handleSheetUpdate(false);
};

const cancelForm = () => {
  if (isEditMode.value && activeJob.value) {
    sheetMode.value = 'view';
  } else {
    closeSheet();
  }
};

const refreshData = async () => {
  isRefreshing.value = true;
  try {
    await Promise.all([
      store.fetchPostings('', true),
      store.fetchCompanies(),
    ]);
    if (activeJob.value) {
      const updated = postings.value.find(j => j.id === activeJob.value.id);
      if (updated) {
        activeJob.value = updated;
      }
    }
    toastType.value = 'success';
    toastMessage.value = 'Data lowongan berhasil disegarkan.';
    setTimeout(() => { toastMessage.value = null; }, 2500);
  } catch (e) {
    toastType.value = 'error';
    toastMessage.value = 'Gagal memperbarui data lowongan.';
  } finally {
    isRefreshing.value = false;
  }
};

const postings = computed(() => filterJobPostings(store.postings));
const companies = computed(() => store.companies || []);

const publishedCount = computed(() => postings.value.filter(p => p.is_published).length);
const draftCount = computed(() => postings.value.filter(p => !p.is_published).length);

const totalApplicationsCount = computed(() => {
  return postings.value.reduce((acc, job) => acc + (Number(job.applications_count) || 0), 0);
});

const filteredPostings = computed(() => filterJobPostings(postings.value, {
  search: searchQuery.value,
  status: statusFilter.value,
  company: companyFilter.value,
}));

const resetFilters = () => {
  statusFilter.value = 'all';
  companyFilter.value = 'all';
  searchQuery.value = '';
};

const togglePublish = async (job) => {
  try {
    const res = await store.togglePublishPosting(job.id);
    if (res.success) {
      if (activeJob.value && activeJob.value.id === job.id) {
        activeJob.value = postings.value.find(item => String(item.id) === String(job.id)) || {
          ...activeJob.value,
          is_published: res.is_published,
        };
      }
      toastType.value = 'success';
      toastMessage.value = [res.message || 'Status publikasi lowongan berhasil diubah.', res.refresh_warning].filter(Boolean).join(' ');
      setTimeout(() => { toastMessage.value = null; }, res.refresh_warning ? 8000 : 3000);
    }
  } catch (err) {
    toastType.value = 'error';
    toastMessage.value = 'Gagal memperbarui status publikasi.';
  }
};

const copyJobLink = async (job) => {
  const textToCopy = `Lowongan: ${job.title} di ${job.company_name || 'PT Complete Selular Group'} (${job.location || 'Indonesia'}). Batas penutupan: ${job.closing_date_formatted || '-'}`;
  try {
    await navigator.clipboard.writeText(textToCopy);
    toastType.value = 'success';
    toastMessage.value = `Info lowongan "${job.title}" disalin ke clipboard.`;
    setTimeout(() => { toastMessage.value = null; }, 3000);
  } catch (e) {
    toastType.value = 'error';
    toastMessage.value = 'Gagal menyalin link lowongan.';
  }
};

const checkRouteForJob = () => {
  if (route.name !== 'postings' && !route.path.endsWith('/job-postings')) {
    return;
  }

  const targetId = route.query.edit_id || (route.name === 'postings' ? route.query.id : null);
  if (!targetId || !postings.value?.length) return;
  const job = postings.value.find(j => String(j.id) === String(targetId));
  if (job) {
    statusFilter.value = 'all';
    companyFilter.value = 'all';
    if (route.query.edit_id) {
      openEditSheet(job);
    } else {
      inspectJob(job);
    }
  }
};

const handleThumbnailChange = (e) => {
  const file = e.target.files?.[0];
  if (!file) return;
  thumbnailFile.value = file;
  thumbnailFileName.value = file.name;
  isThumbnailRemoved.value = false;
  const reader = new FileReader();
  reader.onload = (event) => {
    thumbnailPreview.value = event.target.result;
  };
  reader.readAsDataURL(file);
};

const removeThumbnail = () => {
  thumbnailFile.value = null;
  thumbnailPreview.value = null;
  thumbnailFileName.value = '';
  isThumbnailRemoved.value = true;
};

const saveEditJob = async () => {
  const title = editForm.value.title?.trim();
  if (!title) {
    Swal.fire({
      target: document.querySelector('[data-job-posting-sheet]') || document.body,
      title: 'Judul Wajib Diisi',
      text: 'Harap masukkan judul posisi lowongan pekerjaan.',
      icon: 'warning',
      confirmButtonColor: '#18181b',
      customClass: {
        popup: 'rounded-xl border border-zinc-200 shadow-xl text-xs',
        confirmButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
      },
    });
    return;
  }

  const actionText = isEditMode.value ? 'Simpan perubahan data lowongan pekerjaan ini?' : 'Terbitkan posisi lowongan pekerjaan baru ini?';
  const result = await Swal.fire({
    target: document.querySelector('[data-job-posting-sheet]') || document.body,
    title: isEditMode.value ? 'Konfirmasi Perubahan' : 'Konfirmasi Terbitkan',
    text: actionText,
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: isEditMode.value ? 'Ya, Simpan' : 'Ya, Terbitkan',
    cancelButtonText: 'Batal',
    confirmButtonColor: '#18181b',
    cancelButtonColor: '#71717a',
    reverseButtons: true,
    customClass: {
      popup: 'rounded-xl border border-zinc-200 shadow-xl text-xs',
      title: 'text-sm font-semibold text-zinc-900',
      htmlContainer: 'text-xs text-zinc-500',
      confirmButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
      cancelButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
    },
  });

  if (!result.isConfirmed) return;

  isSubmitting.value = true;
  try {
    const formData = new FormData();
    formData.append('title', title);
    if (editForm.value.company_id) {
      formData.append('company_id', editForm.value.company_id);
    } else {
      formData.append('company_id', '');
    }
    formData.append('location', editForm.value.location || '');
    formData.append('rekrutmen_pipeline_id', editForm.value.rekrutmen_pipeline_id || 1);
    formData.append('description', editForm.value.description || '');
    formData.append('requirements', editForm.value.requirements || '');
    formData.append('closing_date', editForm.value.closing_date || '');
    formData.append('is_published', editForm.value.publication_state === 'active' ? '1' : '0');

    if (thumbnailFile.value) {
      formData.append('thumbnail', thumbnailFile.value);
    }
    if (isThumbnailRemoved.value) {
      formData.append('remove_thumbnail', '1');
    }

    let res;
    if (isEditMode.value && editingJob.value) {
      res = await store.updateJobPosting(editingJob.value.id, formData);
    } else {
      res = await store.createJobPosting(formData);
    }

    if (res.success) {
      const savedPostingId = res.posting?.id || editingJob.value?.id;
      const updatedOrCreated = postings.value.find(job => String(job.id) === String(savedPostingId));
      if (updatedOrCreated && !res.refresh_warning) {
        activeJob.value = updatedOrCreated;
        sheetMode.value = 'view';
      } else {
        closeSheet();
      }
      toastType.value = 'success';
      toastMessage.value = [res.message || (isEditMode.value ? 'Lowongan pekerjaan berhasil diperbarui.' : 'Lowongan pekerjaan berhasil ditambahkan.'), res.refresh_warning].filter(Boolean).join(' ');
      setTimeout(() => { toastMessage.value = null; }, res.refresh_warning ? 8000 : 3000);
    }
  } catch (err) {
    Swal.fire({
      target: document.querySelector('[data-job-posting-sheet]') || document.body,
      title: 'Gagal Menyimpan',
      text: err.response?.data?.message || 'Terjadi kesalahan saat menyimpan lowongan.',
      icon: 'error',
      confirmButtonText: 'Tutup',
      confirmButtonColor: '#e11d48',
      customClass: {
        popup: 'rounded-xl border border-zinc-200 shadow-xl text-xs',
        confirmButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
      },
    });
  } finally {
    isSubmitting.value = false;
  }
};

const handleDeleteJob = async (job) => {
  const result = await Swal.fire({
    target: document.querySelector('[data-job-posting-sheet]') || document.body,
    title: 'Hapus Lowongan?',
    html: `Apakah Anda yakin ingin menghapus lowongan <strong>${job.title}</strong>?<br><span class="text-xs text-zinc-500">Lowongan yang masih memiliki kandidat pelamar tidak dapat dihapus.</span>`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Ya, Hapus',
    cancelButtonText: 'Batal',
    confirmButtonColor: '#e11d48',
    cancelButtonColor: '#71717a',
    reverseButtons: true,
    customClass: {
      popup: 'rounded-xl border border-zinc-200 shadow-xl text-xs',
      confirmButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
      cancelButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
    },
  });

  if (!result.isConfirmed) return;

  try {
    const res = await store.deleteJobPosting(job.id);
    if (activeJob.value && activeJob.value.id === job.id) {
      closeSheet();
    }
    toastType.value = 'success';
    toastMessage.value = [res.message || `Lowongan "${job.title}" berhasil dihapus.`, res.refresh_warning].filter(Boolean).join(' ');
    setTimeout(() => { toastMessage.value = null; }, res.refresh_warning ? 8000 : 3000);
  } catch (err) {
    Swal.fire({
      target: document.querySelector('[data-job-posting-sheet]') || document.body,
      title: 'Gagal Menghapus',
      text: err.response?.data?.message || 'Terjadi kesalahan saat menghapus lowongan.',
      icon: 'error',
      confirmButtonText: 'Tutup',
      confirmButtonColor: '#e11d48',
      customClass: {
        popup: 'rounded-xl border border-zinc-200 shadow-xl text-xs',
        confirmButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
      },
    });
  }
};

onMounted(async () => {
  isLoading.value = true;
  try {
    await Promise.all([
      store.fetchPostings('', false),
      store.fetchCompanies(),
      store.fetchConfigurations(true),
    ]);
    checkRouteForJob();
  } catch (e) {
    console.error('Error fetching job postings data:', e);
    toastType.value = 'error';
    toastMessage.value = 'Gagal memuat seluruh lowongan. Silakan coba segarkan kembali.';
  } finally {
    isLoading.value = false;
  }
});

onDeactivated(() => {
  isSheetOpen.value = false;
});

watch(
  () => [route.name, route.query.edit_id, route.query.id, postings.value],
  () => {
    checkRouteForJob();
  }
);
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
