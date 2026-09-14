<template>
  <div class="space-y-6 pb-12">
    <!-- Top Header: Title & Quick Stats -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <div class="flex items-center gap-2.5">
          <h1 class="text-xl font-semibold text-zinc-900 tracking-tight">Lowongan Pekerjaan</h1>
          <Badge variant="outline" class="font-mono text-[11px] text-zinc-600 bg-zinc-50 border-zinc-200">
            {{ postings.length }} Posisi
          </Badge>
        </div>
        <p class="text-xs text-zinc-500 mt-1">
          Kelola lowongan aktif, pantau portal karir, dan telusuri kandidat pelamar yang masuk
        </p>
      </div>

      <div class="flex items-center gap-2">
        <Button
          size="sm"
          variant="default"
          @click="openCreateSheet"
          class="text-xs h-8 bg-zinc-900 hover:bg-zinc-800 text-white gap-1.5 cursor-pointer shadow-2xs"
        >
          <Plus class="w-3.5 h-3.5" />
          <span>Tambah Lowongan</span>
        </Button>

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

        <!-- View Mode Segmented Control -->
        <div class="inline-flex items-center bg-zinc-100 p-0.5 rounded-lg border border-zinc-200">
          <button
            type="button"
            @click="viewMode = 'grid'"
            :class="[
              'px-2.5 py-1 rounded-md text-xs font-medium flex items-center gap-1.5 transition-all cursor-pointer select-none',
              viewMode === 'grid'
                ? 'bg-white text-zinc-900 shadow-2xs font-semibold'
                : 'text-zinc-600 hover:text-zinc-900'
            ]"
          >
            <LayoutGrid class="w-3.5 h-3.5" />
            <span>Grid</span>
          </button>
          <button
            type="button"
            @click="viewMode = 'table'"
            :class="[
              'px-2.5 py-1 rounded-md text-xs font-medium flex items-center gap-1.5 transition-all cursor-pointer select-none',
              viewMode === 'table'
                ? 'bg-white text-zinc-900 shadow-2xs font-semibold'
                : 'text-zinc-600 hover:text-zinc-900'
            ]"
          >
            <ListFilter class="w-3.5 h-3.5" />
            <span>Tabel</span>
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
      <Card class="hover:border-blue-200 hover:shadow-xs transition-all duration-200 bg-white">
        <CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0">
          <span class="text-xs font-medium text-zinc-500">Total Lowongan</span>
          <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center shrink-0">
            <Briefcase class="w-4 h-4" />
          </div>
        </CardHeader>
        <CardContent class="p-4 pt-0">
          <div class="text-2xl font-bold tracking-tight text-zinc-900">{{ postings.length }}</div>
          <p class="text-[11px] text-zinc-500 mt-0.5">
            {{ totalNeededPositions }} total kuota posisi dibutuhkan
          </p>
        </CardContent>
      </Card>

      <Card class="hover:border-emerald-200 hover:shadow-xs transition-all duration-200 bg-white">
        <CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0">
          <span class="text-xs font-medium text-zinc-500">Tayang Aktif</span>
          <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-700 flex items-center justify-center shrink-0">
            <CheckCircle2 class="w-4 h-4" />
          </div>
        </CardHeader>
        <CardContent class="p-4 pt-0">
          <div class="text-2xl font-bold tracking-tight text-emerald-700">{{ publishedCount }}</div>
          <p class="text-[11px] text-zinc-500 mt-0.5">
            Tersedia untuk pelamar publik
          </p>
        </CardContent>
      </Card>

      <Card class="hover:border-amber-200 hover:shadow-xs transition-all duration-200 bg-white">
        <CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0">
          <span class="text-xs font-medium text-zinc-500">Draft Lowongan</span>
          <div class="w-8 h-8 rounded-lg bg-amber-50 text-amber-700 flex items-center justify-center shrink-0">
            <Clock class="w-4 h-4" />
          </div>
        </CardHeader>
        <CardContent class="p-4 pt-0">
          <div class="text-2xl font-bold tracking-tight text-amber-700">{{ draftCount }}</div>
          <p class="text-[11px] text-zinc-500 mt-0.5">
            Belum dipublikasikan ke publik
          </p>
        </CardContent>
      </Card>

      <Card class="hover:border-indigo-200 hover:shadow-xs transition-all duration-200 bg-white">
        <CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0">
          <span class="text-xs font-medium text-zinc-500">Total Pelamar</span>
          <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-700 flex items-center justify-center shrink-0">
            <Users class="w-4 h-4" />
          </div>
        </CardHeader>
        <CardContent class="p-4 pt-0">
          <div class="text-2xl font-bold tracking-tight text-zinc-900">{{ totalApplicationsCount }}</div>
          <p class="text-[11px] text-zinc-500 mt-0.5">
            Akumulasi lamaran kandidat
          </p>
        </CardContent>
      </Card>
    </div>

    <!-- Filters & Toolbar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 p-3 bg-white rounded-xl border border-zinc-200 shadow-2xs">
      <!-- Left: Status Tabs Filter -->
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
          <span>Semua</span>
          <span
            :class="[
              'px-1.5 py-0.5 rounded-full text-[10px] font-semibold leading-none',
              statusFilter === 'all' ? 'bg-blue-50 text-[#0c2340]' : 'bg-zinc-200/70 text-zinc-500'
            ]"
          >
            {{ postings.length }}
          </span>
        </button>

        <button
          type="button"
          @click="statusFilter = 'published'"
          :class="[
            'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer shrink-0 select-none flex items-center gap-1.5',
            statusFilter === 'published'
              ? 'bg-white text-emerald-700 shadow-xs font-semibold'
              : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
          ]"
        >
          <span>Tayang Aktif</span>
          <span
            :class="[
              'px-1.5 py-0.5 rounded-full text-[10px] font-semibold leading-none',
              statusFilter === 'published' ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-200/70 text-zinc-500'
            ]"
          >
            {{ publishedCount }}
          </span>
        </button>

        <button
          type="button"
          @click="statusFilter = 'draft'"
          :class="[
            'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer shrink-0 select-none flex items-center gap-1.5',
            statusFilter === 'draft'
              ? 'bg-white text-amber-700 shadow-xs font-semibold'
              : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
          ]"
        >
          <span>Draft</span>
          <span
            :class="[
              'px-1.5 py-0.5 rounded-full text-[10px] font-semibold leading-none',
              statusFilter === 'draft' ? 'bg-amber-50 text-amber-700' : 'bg-zinc-200/70 text-zinc-500'
            ]"
          >
            {{ draftCount }}
          </span>
        </button>
      </div>

      <!-- Right: Search & Company Filter -->
      <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
        <!-- Company Dropdown Filter -->
        <div class="relative min-w-[190px]">
          <select
            v-model="companyFilter"
            class="w-full h-8 bg-zinc-50 border border-zinc-200 rounded-md pl-3 pr-8 text-xs text-zinc-800 focus:outline-none focus:ring-1 focus:ring-zinc-950 focus:border-zinc-950 transition-colors appearance-none cursor-pointer"
          >
            <option value="all">Semua Perusahaan</option>
            <option v-for="comp in companies" :key="comp.id" :value="String(comp.id)">
              {{ comp.name }}
            </option>
          </select>
          <ChevronDown class="w-3.5 h-3.5 text-zinc-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
        </div>

        <!-- Search Input -->
        <div class="relative w-full sm:w-64">
          <Search class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-zinc-400" />
          <Input
            v-model="searchQuery"
            type="text"
            placeholder="Cari posisi atau lokasi..."
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
    </div>

    <!-- SKELETON LOADING STATE -->
    <div v-if="isLoading" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <Card v-for="i in 6" :key="i" class="p-5 space-y-4">
        <div class="flex items-center justify-between">
          <Skeleton class="h-4 w-28" />
          <Skeleton class="h-5 w-16 rounded-full" />
        </div>
        <div class="space-y-2">
          <Skeleton class="h-5 w-4/5" />
          <Skeleton class="h-3 w-1/2" />
        </div>
        <div class="pt-2 flex items-center gap-2">
          <Skeleton class="h-4 w-24" />
          <Skeleton class="h-4 w-20" />
        </div>
        <div class="pt-4 border-t border-zinc-100 flex items-center justify-between">
          <Skeleton class="h-3 w-24" />
          <Skeleton class="h-7 w-24 rounded-md" />
        </div>
      </Card>
    </div>

    <!-- GRID VIEW: Shadcn New York Style Cards -->
    <div
      v-else-if="viewMode === 'grid' && filteredPostings.length"
      class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"
    >
      <Card
        v-for="job in filteredPostings"
        :key="job.id"
        class="flex flex-col justify-between hover:border-blue-200 hover:shadow-md transition-all duration-200 group bg-white"
      >
        <CardHeader class="p-5 pb-3.5 space-y-3">
          <!-- Top Row: Company Badge & Status Badge & Actions -->
          <div class="flex items-start justify-between gap-2">
            <div class="flex flex-col gap-1 min-w-0">
              <span class="text-[11px] font-medium text-blue-900/70 truncate block">
                {{ job.company_name || 'PT Complete Selular Group' }}
              </span>
            </div>

            <div class="flex items-center gap-1.5 shrink-0">
              <!-- Publish Status Badge -->
              <button
                type="button"
                @click="togglePublish(job)"
                :class="[
                  'inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[10px] font-medium border transition-colors cursor-pointer select-none',
                  job.is_published
                    ? 'bg-emerald-50/80 border-emerald-200/80 text-emerald-800 hover:bg-emerald-100'
                    : 'bg-zinc-50 border-zinc-200 text-zinc-500 hover:bg-zinc-100'
                ]"
                :title="job.is_published ? 'Klik untuk jadikan Draft' : 'Klik untuk Tayangkan'"
              >
                <span :class="['w-1.5 h-1.5 rounded-full shrink-0', job.is_published ? 'bg-emerald-500' : 'bg-zinc-400']"></span>
                <span>{{ job.is_published ? 'Tayang' : 'Draft' }}</span>
              </button>

              <!-- Action Dropdown Menu (Direct, instant, 0ms, no animation) -->
              <div class="relative">
                <button
                  type="button"
                  @click.stop="toggleDropdown(job.id)"
                  class="h-7 w-7 flex items-center justify-center rounded-md text-zinc-400 hover:text-zinc-800 hover:bg-zinc-100 transition-colors cursor-pointer"
                  title="Menu Lowongan"
                >
                  <MoreHorizontal class="w-4 h-4" />
                </button>

                <div
                  v-if="activeDropdownJobId === job.id"
                  @click.stop
                  class="absolute right-0 top-full mt-1 z-50 w-44 overflow-hidden rounded-md border border-zinc-200 bg-white p-1 text-zinc-950 shadow-md text-xs select-none"
                >
                  <div
                    @click="openEditSheet(job); closeDropdown()"
                    class="relative flex cursor-pointer select-none items-center gap-2 rounded-sm px-2.5 py-1.5 text-xs text-zinc-700 outline-none hover:bg-zinc-100 hover:text-zinc-900 transition-colors"
                  >
                    <Edit3 class="w-3.5 h-3.5 mr-2 text-zinc-500 shrink-0" />
                    <span>Edit Lowongan</span>
                  </div>
                  <div
                    @click="togglePublish(job); closeDropdown()"
                    class="relative flex cursor-pointer select-none items-center gap-2 rounded-sm px-2.5 py-1.5 text-xs text-zinc-700 outline-none hover:bg-zinc-100 hover:text-zinc-900 transition-colors"
                  >
                    <Eye v-if="!job.is_published" class="w-3.5 h-3.5 mr-2 text-emerald-600 shrink-0" />
                    <EyeOff v-else class="w-3.5 h-3.5 mr-2 text-zinc-500 shrink-0" />
                    <span>{{ job.is_published ? 'Tarik ke Draft' : 'Tayangkan Lowongan' }}</span>
                  </div>
                  <div class="-mx-1 my-1 h-px bg-zinc-100"></div>
                  <div
                    @click="copyJobLink(job); closeDropdown()"
                    class="relative flex cursor-pointer select-none items-center gap-2 rounded-sm px-2.5 py-1.5 text-xs text-zinc-700 outline-none hover:bg-zinc-100 hover:text-zinc-900 transition-colors"
                  >
                    <Copy class="w-3.5 h-3.5 mr-2 text-zinc-500 shrink-0" />
                    <span>Salin Info Lowongan</span>
                  </div>
                  <div class="-mx-1 my-1 h-px bg-zinc-100"></div>
                  <div
                    @click="handleDeleteJob(job); closeDropdown()"
                    class="relative flex cursor-pointer select-none items-center gap-2 rounded-sm px-2.5 py-1.5 text-xs text-rose-600 outline-none hover:bg-rose-50 hover:text-rose-700 transition-colors"
                  >
                    <Trash2 class="w-3.5 h-3.5 mr-2 shrink-0" />
                    <span>Hapus Lowongan</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Job Title -->
          <div>
            <h2
              @click="openEditSheet(job)"
              class="text-sm font-semibold text-zinc-900 group-hover:text-[#0c2340] transition-colors cursor-pointer leading-snug tracking-tight"
            >
              {{ job.title }}
            </h2>
            <div class="flex items-center gap-1.5 text-[11px] text-zinc-500 mt-1.5">
              <MapPin class="w-3.5 h-3.5 text-zinc-400 shrink-0" />
              <span class="truncate">{{ job.location || 'Indonesia' }}</span>
            </div>
          </div>

          <!-- Metadata Chips -->
          <div class="flex flex-wrap items-center gap-2 pt-1 text-[11px]">
            <div class="inline-flex items-center gap-1.5 bg-blue-50/70 border border-blue-200/60 px-2 py-0.5 rounded-md text-blue-900 font-medium">
              <Briefcase class="w-3 h-3 text-blue-600" />
              <span>{{ job.needed_count || 1 }} Kuota Posisi</span>
            </div>
            <div
              @click.stop="goToApplications(job)"
              class="inline-flex items-center gap-1.5 bg-indigo-50/70 hover:bg-indigo-100/90 border border-indigo-200/60 px-2 py-0.5 rounded-md text-indigo-900 font-medium cursor-pointer transition-colors"
              title="Lihat kandidat pelamar lowongan ini"
            >
              <Users class="w-3 h-3 text-indigo-600" />
              <span>{{ job.applications_count || 0 }} Pelamar</span>
            </div>
          </div>
        </CardHeader>

        <!-- Card Footer -->
        <CardFooter class="p-5 pt-3 border-t border-zinc-100/80 flex items-center justify-between text-xs text-zinc-500 mt-1">
          <div class="flex items-center gap-1.5 text-[11px] text-zinc-500">
            <Calendar class="w-3.5 h-3.5 text-zinc-400 shrink-0" />
            <span>Tutup: <strong class="text-zinc-700 font-medium">{{ job.closing_date_formatted || '-' }}</strong></span>
          </div>

          <Button
            type="button"
            variant="outline"
            size="xs"
            @click.stop="goToApplications(job)"
            class="font-medium text-blue-950 border-blue-200/80 bg-blue-50/40 hover:bg-blue-100/70 hover:border-blue-300 transition-colors cursor-pointer"
          >
            <span>Pelamar</span>
            <ArrowRight class="w-3 h-3 ml-0.5 text-blue-700" />
          </Button>
        </CardFooter>
      </Card>
    </div>

    <!-- TABLE VIEW: Shadcn Table Component -->
    <div
      v-else-if="viewMode === 'table' && filteredPostings.length"
      class="bg-white rounded-xl border border-zinc-200 shadow-2xs overflow-hidden"
    >
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead class="w-[30%]">Posisi Lowongan</TableHead>
            <TableHead class="w-[25%]">Perusahaan & Lokasi</TableHead>
            <TableHead class="w-[12%]">Status</TableHead>
            <TableHead class="w-[13%]">Batas Waktu</TableHead>
            <TableHead class="w-[10%]">Pelamar</TableHead>
            <TableHead class="w-[10%] text-right">Aksi</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="job in filteredPostings" :key="job.id" class="group hover:bg-blue-50/30 transition-colors">
            <TableCell class="py-3.5">
              <div
                @click="openEditSheet(job)"
                class="font-semibold text-zinc-900 hover:text-[#0c2340] cursor-pointer text-xs transition-colors"
              >
                {{ job.title }}
              </div>
              <div class="text-[11px] text-zinc-400 font-mono mt-0.5">
                #{{ job.id }} &bull; {{ job.needed_count || 1 }} kuota
              </div>
            </TableCell>

            <TableCell class="py-3.5">
              <div class="font-medium text-zinc-800 text-xs truncate max-w-[240px]">
                {{ job.company_name || 'PT Complete Selular Group' }}
              </div>
              <div class="text-[11px] text-zinc-400 flex items-center gap-1 mt-0.5">
                <MapPin class="w-3 h-3 text-zinc-400 shrink-0" />
                <span>{{ job.location || 'Indonesia' }}</span>
              </div>
            </TableCell>

            <TableCell class="py-3.5">
              <button
                type="button"
                @click="togglePublish(job)"
                :class="[
                  'inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[10px] font-medium border transition-colors cursor-pointer select-none',
                  job.is_published
                    ? 'bg-emerald-50/80 border-emerald-200/80 text-emerald-800 hover:bg-emerald-100'
                    : 'bg-zinc-50 border-zinc-200 text-zinc-500 hover:bg-zinc-100'
                ]"
                :title="job.is_published ? 'Klik untuk jadikan Draft' : 'Klik untuk Tayangkan'"
              >
                <span :class="['w-1.5 h-1.5 rounded-full shrink-0', job.is_published ? 'bg-emerald-500' : 'bg-zinc-400']"></span>
                <span>{{ job.is_published ? 'Tayang' : 'Draft' }}</span>
              </button>
            </TableCell>

            <TableCell class="py-3.5 text-zinc-600 text-xs">
              {{ job.closing_date_formatted || '-' }}
            </TableCell>

            <TableCell
              class="py-3.5 font-semibold text-blue-900 hover:text-blue-950 text-xs cursor-pointer"
              @click.stop="goToApplications(job)"
              title="Lihat kandidat pelamar lowongan ini"
            >
              {{ job.applications_count || 0 }}
            </TableCell>

            <TableCell class="py-3.5 text-right whitespace-nowrap">
              <div class="inline-flex items-center gap-1.5">
                <Button
                  type="button"
                  variant="ghost"
                  size="xs"
                  @click.stop="goToApplications(job)"
                  class="h-7 text-blue-900 hover:text-blue-950 hover:bg-blue-50/60 cursor-pointer"
                >
                  Pelamar
                </Button>

                <Button
                  variant="outline"
                  size="xs"
                  @click="openEditSheet(job)"
                  class="h-7 text-zinc-700 hover:text-zinc-950"
                >
                  Edit
                </Button>

                <Button
                  variant="ghost"
                  size="xs"
                  @click="handleDeleteJob(job)"
                  class="h-7 w-7 p-0 text-zinc-400 hover:text-rose-600 hover:bg-rose-50"
                  title="Hapus Lowongan"
                >
                  <Trash2 class="w-3.5 h-3.5" />
                </Button>
              </div>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <!-- EMPTY STATE -->
    <Card v-else class="p-12 text-center border-dashed border-zinc-300">
      <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 mb-3">
        <Briefcase class="h-5 w-5" />
      </div>
      <h3 class="text-sm font-semibold text-zinc-900">Tidak ada lowongan ditemukan</h3>
      <p class="text-xs text-zinc-500 mt-1 max-w-sm mx-auto">
        Tidak ada data lowongan yang sesuai dengan kriteria filter atau pencarian Anda.
      </p>
      <div class="mt-4 flex items-center justify-center gap-2">
        <Button
          variant="outline"
          size="sm"
          @click="resetFilters"
        >
          Reset Filter
        </Button>
      </div>
    </Card>

    <!-- SLIDE-OVER SHEET: Linear/Stripe Style Drawer -->
    <Sheet :open="isSheetOpen" @update:open="handleSheetUpdate">
      <SheetContent class="sm:max-w-xl">
        <SheetHeader>
          <div class="flex items-center gap-2">
            <SheetTitle>{{ isEditMode ? 'Detail Lowongan Pekerjaan' : 'Tambah Lowongan Baru' }}</SheetTitle>
            <Badge v-if="selectedCompanyName" variant="secondary" class="text-[10px] font-normal truncate max-w-[200px]">
              {{ selectedCompanyName }}
            </Badge>
          </div>
          <SheetDescription>
            {{ isEditMode ? 'Informasi posisi, kualifikasi, kuota, dan status tayang portal karir.' : 'Buat dan publikasikan posisi lowongan pekerjaan baru ke portal karir.' }}
          </SheetDescription>
        </SheetHeader>

        <!-- Form Body with Comfortable Vertical Scroll -->
        <form @submit.prevent="saveEditJob" class="flex flex-col flex-1 overflow-hidden">
          <div class="p-6 space-y-5 overflow-y-auto flex-1 text-xs text-zinc-900">
            <!-- Section 1: General Info -->
            <div class="space-y-4">
              <div class="text-[11px] font-semibold text-zinc-500 uppercase tracking-wider">
                Informasi Utama
              </div>

              <div>
                <label class="block font-medium text-zinc-800 mb-1.5">Judul Posisi <span class="text-red-500">*</span></label>
                <Input
                  v-model="editForm.title"
                  type="text"
                  required
                  placeholder="Contoh: Store Leader, Admin GA, dsb."
                  class="h-9"
                />
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                  <label class="block font-medium text-zinc-800 mb-1.5">Perusahaan / PT</label>
                  <div class="relative">
                    <select
                      v-model="editForm.company_id"
                      class="w-full h-9 bg-white border border-zinc-200 rounded-md px-3 text-xs text-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-950 focus:border-zinc-950 appearance-none pr-8 cursor-pointer transition-colors"
                    >
                      <option :value="null">-- Gunakan Default (PT Complete Selular Group) --</option>
                      <option v-for="c in companies" :key="c.id" :value="c.id">
                        {{ c.name }}
                      </option>
                    </select>
                    <ChevronDown class="w-3.5 h-3.5 text-zinc-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                  </div>
                </div>

                <div>
                  <label class="block font-medium text-zinc-800 mb-1.5">Lokasi Kerja</label>
                  <Input
                    v-model="editForm.location"
                    type="text"
                    placeholder="Kota Cirebon, Jakarta, dsb."
                    class="h-9"
                  />
                </div>
              </div>
            </div>

            <!-- Section 2: Quota & Dates -->
            <div class="space-y-4 pt-3 border-t border-zinc-100">
              <div class="text-[11px] font-semibold text-zinc-500 uppercase tracking-wider">
                Batas Waktu & Publikasi
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                  <label class="block font-medium text-zinc-800 mb-1.5">Batas Waktu Lamaran</label>
                  <Input
                    v-model="editForm.closing_date"
                    type="date"
                    class="h-9"
                  />
                </div>

                <div>
                  <label class="block font-medium text-zinc-800 mb-1.5">Status Publikasi</label>
                  <div class="flex items-center gap-4 h-9">
                    <label class="inline-flex items-center gap-1.5 cursor-pointer text-xs">
                      <input
                        type="radio"
                        name="publication_state"
                        value="active"
                        v-model="editForm.publication_state"
                        class="accent-zinc-900 cursor-pointer"
                      />
                      <span>Tayang Aktif</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer text-xs">
                      <input
                        type="radio"
                        name="publication_state"
                        value="draft"
                        v-model="editForm.publication_state"
                        class="accent-zinc-900 cursor-pointer"
                      />
                      <span>Draft</span>
                    </label>
                  </div>
                </div>
              </div>
            </div>

            <!-- Section 3: Job Description & Qualifications -->
            <div class="space-y-4 pt-3 border-t border-zinc-100">
              <div class="text-[11px] font-semibold text-zinc-500 uppercase tracking-wider">
                Deskripsi & Persyaratan
              </div>

              <div>
                <label class="block font-medium text-zinc-800 mb-1.5">Deskripsi Tugas & Tanggung Jawab</label>
                <textarea
                  v-model="editForm.description"
                  rows="4"
                  placeholder="• Tugas dan tanggung jawab harian..."
                  class="w-full bg-white border border-zinc-200 rounded-md p-3 text-xs text-zinc-900 placeholder:text-zinc-400 focus:outline-none focus:ring-1 focus:ring-zinc-950 focus:border-zinc-950 leading-relaxed transition-colors resize-none"
                ></textarea>
              </div>

              <div>
                <label class="block font-medium text-zinc-800 mb-1.5">Kualifikasi & Persyaratan</label>
                <textarea
                  v-model="editForm.requirements"
                  rows="4"
                  placeholder="• Minimal pendidikan...&#10;• Pengalaman kerja...&#10;• Kemampuan teknis..."
                  class="w-full bg-white border border-zinc-200 rounded-md p-3 text-xs text-zinc-900 placeholder:text-zinc-400 focus:outline-none focus:ring-1 focus:ring-zinc-950 focus:border-zinc-950 leading-relaxed transition-colors resize-none"
                ></textarea>
              </div>
            </div>

            <!-- Section 4: Banner / Poster Thumbnail -->
            <div class="space-y-3 pt-3 border-t border-zinc-100">
              <div class="text-[11px] font-semibold text-zinc-500 uppercase tracking-wider">
                Media Banner Lowongan
              </div>

              <div class="border border-zinc-200 rounded-lg p-3 flex items-center justify-between gap-3 bg-zinc-50/60">
                <div class="flex items-center gap-3 min-w-0">
                  <div class="w-12 h-12 rounded-md bg-white border border-zinc-200 text-zinc-400 flex items-center justify-center shrink-0 overflow-hidden shadow-2xs">
                    <img
                      v-if="(thumbnailPreview || editForm.thumbnail_url) && !isThumbnailRemoved"
                      :src="thumbnailPreview || editForm.thumbnail_url"
                      alt="Banner Lowongan"
                      class="w-full h-full object-cover"
                      @error="editForm.thumbnail_url = null"
                    />
                    <ImageIcon v-else class="w-5 h-5 text-zinc-400 stroke-[1.5]" />
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

                  <Button
                    v-if="(thumbnailPreview || editForm.thumbnail_url) && !isThumbnailRemoved"
                    type="button"
                    variant="ghost"
                    size="xs"
                    @click="removeThumbnail"
                    class="text-rose-600 hover:text-rose-700 hover:bg-rose-50"
                  >
                    Hapus
                  </Button>
                </div>
              </div>
            </div>
          </div>

          <!-- Sheet Action Footer -->
          <SheetFooter>
            <Button
              type="button"
              variant="outline"
              size="sm"
              @click="closeSheet"
            >
              Batal
            </Button>
            <Button
              type="submit"
              size="sm"
              variant="default"
              :disabled="isSubmitting"
              class="bg-[#0c2340] hover:bg-[#153459] text-white shadow-xs min-w-[130px]"
            >
              <RotateCw v-if="isSubmitting" class="w-3.5 h-3.5 mr-1.5 animate-spin" />
              <span>{{ isSubmitting ? 'Menyimpan...' : (isEditMode ? 'Simpan Perubahan' : 'Terbitkan Lowongan') }}</span>
            </Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, onDeactivated, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useRekrutmenStore } from '../stores/rekrutmen';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';

// Shadcn-Vue UI Components
import { Button } from '../components/ui/button';
import { Badge } from '../components/ui/badge';
import { Card, CardHeader, CardContent, CardFooter } from '../components/ui/card';
import { Table, TableHeader, TableBody, TableHead, TableRow, TableCell } from '../components/ui/table';
import { DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator } from '../components/ui/dropdown-menu';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetFooter } from '../components/ui/sheet';
import { Skeleton } from '../components/ui/skeleton';
import { Input } from '../components/ui/input';

// Icons
import {
  Search,
  MapPin,
  Users,
  Briefcase,
  FileText,
  Edit3,
  ArrowRight,
  LayoutGrid,
  ListFilter,
  CheckCircle2,
  AlertCircle,
  Calendar,
  Clock,
  Image as ImageIcon,
  ChevronDown,
  RotateCw,
  MoreHorizontal,
  Eye,
  EyeOff,
  Copy,
  Plus,
  Trash2
} from 'lucide-vue-next';

const store = useRekrutmenStore();
const route = useRoute();
const router = useRouter();

const isLoading = ref(true);
const isRefreshing = ref(false);
const viewMode = ref('grid');
const statusFilter = ref('all');
const companyFilter = ref('all');
const searchQuery = ref('');
const activeDropdownJobId = ref(null);
const editingJob = ref(null);
const isSheetOpen = ref(false);
const isEditMode = ref(false);
const isSubmitting = ref(false);
const toastMessage = ref(null);
const toastType = ref('success');

const thumbnailFile = ref(null);
const thumbnailPreview = ref(null);
const thumbnailFileName = ref('');
const isThumbnailRemoved = ref(false);

const editForm = ref({
  title: '',
  company_id: null,
  location: '',
  description: '',
  requirements: '',
  closing_date: '',
  publication_state: 'active',
  thumbnail_url: null,
});

const toggleDropdown = (jobId) => {
  activeDropdownJobId.value = activeDropdownJobId.value === jobId ? null : jobId;
};

const closeDropdown = () => {
  activeDropdownJobId.value = null;
};

const goToApplications = (job) => {
  closeDropdown();
  isSheetOpen.value = false;
  editingJob.value = null;
  router.push({
    path: '/admin/job-applications',
    query: { job_id: job.id }
  });
};

onMounted(async () => {
  window.addEventListener('click', closeDropdown);
  isLoading.value = true;
  try {
    await Promise.all([
      store.fetchPostings('', false),
      store.fetchCompanies(),
    ]);
    checkRouteForJob();
  } catch (e) {
    console.error('Error fetching job postings data:', e);
  } finally {
    isLoading.value = false;
  }
});

onUnmounted(() => {
  window.removeEventListener('click', closeDropdown);
});

onDeactivated(() => {
  isSheetOpen.value = false;
  editingJob.value = null;
  closeDropdown();
});

watch(
  () => [route.name, route.query.edit_id, route.query.id, postings.value],
  () => {
    checkRouteForJob();
  }
);

const refreshData = async () => {
  isRefreshing.value = true;
  try {
    await Promise.all([
      store.fetchPostings('', false),
      store.fetchCompanies(),
    ]);
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

const postings = computed(() => store.postings || []);
const companies = computed(() => store.companies || []);

const selectedCompanyName = computed(() => {
  if (editForm.value.company_id) {
    const found = companies.value.find(c => String(c.id) === String(editForm.value.company_id));
    if (found) return found.name;
  }
  if (isEditMode.value) {
    return editingJob.value?.company_name || 'PT Complete Selular Group';
  }
  return companies.value[0]?.name || 'PT Complete Selular Group';
});

const publishedCount = computed(() => postings.value.filter(p => p.is_published).length);
const draftCount = computed(() => postings.value.filter(p => !p.is_published).length);

const totalApplicationsCount = computed(() => {
  return postings.value.reduce((acc, job) => acc + (Number(job.applications_count) || 0), 0);
});

const totalNeededPositions = computed(() => {
  return postings.value.reduce((acc, job) => acc + (Number(job.needed_count) || 1), 0);
});

const filteredPostings = computed(() => {
  let list = postings.value;

  if (statusFilter.value === 'published') {
    list = list.filter(p => p.is_published);
  } else if (statusFilter.value === 'draft') {
    list = list.filter(p => !p.is_published);
  }

  if (companyFilter.value !== 'all') {
    list = list.filter(p => String(p.company_id) === String(companyFilter.value));
  }

  if (!searchQuery.value) return list;
  const q = searchQuery.value.toLowerCase();
  return list.filter(p =>
    (p.title && p.title.toLowerCase().includes(q)) ||
    (p.company_name && p.company_name.toLowerCase().includes(q)) ||
    (p.location && p.location.toLowerCase().includes(q)) ||
    (p.description && p.description.toLowerCase().includes(q)) ||
    (p.requirements && p.requirements.toLowerCase().includes(q))
  );
});

const resetFilters = () => {
  statusFilter.value = 'all';
  companyFilter.value = 'all';
  searchQuery.value = '';
};

const togglePublish = async (job) => {
  try {
    const res = await store.togglePublishPosting(job.id);
    if (res.success) {
      toastType.value = 'success';
      toastMessage.value = res.message || 'Status publikasi lowongan berhasil diubah.';
      setTimeout(() => { toastMessage.value = null; }, 3000);
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

const openCreateSheet = () => {
  editingJob.value = null;
  isEditMode.value = false;
  thumbnailFile.value = null;
  thumbnailPreview.value = null;
  thumbnailFileName.value = '';
  isThumbnailRemoved.value = false;
  editForm.value = {
    title: '',
    company_id: companies.value[0]?.id || null,
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
  editingJob.value = job;
  isEditMode.value = true;
  thumbnailFile.value = null;
  thumbnailPreview.value = null;
  thumbnailFileName.value = job.thumbnail_path ? job.thumbnail_path.split('/').pop() : '';
  isThumbnailRemoved.value = false;
  editForm.value = {
    title: job.title || '',
    company_id: job.company_id || null,
    location: job.location || '',
    description: job.description || '',
    requirements: job.requirements || '',
    closing_date: job.closing_date ? job.closing_date.split('T')[0] : '',
    publication_state: job.is_published ? 'active' : 'draft',
    thumbnail_url: job.thumbnail_url || null,
  };
  isSheetOpen.value = true;
};

const checkRouteForJob = () => {
  // Hanya proses jika user memang sedang berada di halaman lowongan kerja
  if (route.name !== 'postings' && !route.path.endsWith('/job-postings')) {
    return;
  }

  // Hanya buka sheet edit jika ada parameter edit_id atau id spesifik di route postings
  const targetId = route.query.edit_id || (route.name === 'postings' ? route.query.id : null);
  if (!targetId || !postings.value?.length) return;
  const job = postings.value.find(j => String(j.id) === String(targetId));
  if (job) {
    statusFilter.value = 'all';
    companyFilter.value = 'all';
    if (!editingJob.value || editingJob.value.id !== job.id) {
      openEditSheet(job);
    }
  }
};

const handleSheetUpdate = (val) => {
  isSheetOpen.value = val;
  if (!val) {
    editingJob.value = null;
    if (route.name === 'postings' && (route.query.edit_id || route.query.id)) {
      router.replace({ path: route.path, query: {} });
    }
  }
};

const closeSheet = () => {
  handleSheetUpdate(false);
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
      title: 'Judul Wajib Diisi',
      text: 'Harap masukkan judul posisi lowongan pekerjaan.',
      icon: 'warning',
      confirmButtonColor: '#18181b',
      customClass: { popup: 'rounded-xl border border-zinc-200 shadow-lg text-xs' },
    });
    return;
  }

  const actionText = isEditMode.value ? 'Simpan perubahan data lowongan pekerjaan ini?' : 'Terbitkan posisi lowongan pekerjaan baru ini?';
  const result = await Swal.fire({
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
      popup: 'rounded-xl border border-zinc-200 shadow-lg text-xs',
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
      closeSheet();
      toastType.value = 'success';
      toastMessage.value = res.message || (isEditMode.value ? 'Lowongan pekerjaan berhasil diperbarui.' : 'Lowongan pekerjaan berhasil ditambahkan.');
      setTimeout(() => { toastMessage.value = null; }, 3000);
    }
  } catch (err) {
    Swal.fire({
      title: 'Gagal Menyimpan',
      text: err.response?.data?.message || 'Terjadi kesalahan saat menyimpan lowongan.',
      icon: 'error',
      confirmButtonText: 'Tutup',
      confirmButtonColor: '#e11d48',
      customClass: {
        popup: 'rounded-xl border border-zinc-200 shadow-lg text-xs',
        confirmButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
      },
    });
  } finally {
    isSubmitting.value = false;
  }
};

const handleDeleteJob = async (job) => {
  const result = await Swal.fire({
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
      popup: 'rounded-xl border border-zinc-200 shadow-lg text-xs',
      confirmButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
      cancelButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
    },
  });

  if (!result.isConfirmed) return;

  try {
    const res = await store.deleteJobPosting(job.id);
    toastType.value = 'success';
    toastMessage.value = res.message || `Lowongan "${job.title}" berhasil dihapus.`;
    setTimeout(() => { toastMessage.value = null; }, 3000);
  } catch (err) {
    Swal.fire({
      title: 'Gagal Menghapus',
      text: err.response?.data?.message || 'Terjadi kesalahan saat menghapus lowongan.',
      icon: 'error',
      confirmButtonText: 'Tutup',
      confirmButtonColor: '#e11d48',
      customClass: {
        popup: 'rounded-xl border border-zinc-200 shadow-lg text-xs',
        confirmButton: 'rounded-md px-3.5 py-1.5 text-xs font-medium',
      },
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
