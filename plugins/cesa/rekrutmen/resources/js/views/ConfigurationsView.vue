<template>
  <div class="space-y-4 pb-12 font-sans">
    <!-- Elevated Asoy Header Card -->
    <div class="p-4 bg-surface-white rounded-lg border border-outline-gray-2 shadow-2xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-surface-gray-2 border border-outline-gray-2 flex items-center justify-center shrink-0">
          <Settings class="w-5 h-5 text-ink-gray-7" />
        </div>
        <div>
          <div class="flex items-center gap-2.5">
            <h1 class="text-base sm:text-lg font-bold text-ink-gray-9 tracking-tight">
              Pengaturan & Master Data
            </h1>
            <FBadge theme="green" variant="subtle" size="sm" class="tabular-nums font-semibold">
              <template #prefix>
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
              </template>
              Sistem Aktif
            </FBadge>
          </div>
          <p class="text-xs text-ink-gray-5 mt-0.5">
            Kelola master data divisi, tahapan seleksi pelamar, konfigurasi approver, integrasi AI, serta gateway email & WhatsApp
          </p>
        </div>
      </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="inline-flex items-center p-1 bg-zinc-100/90 border border-zinc-200/80 rounded-lg text-xs overflow-x-auto no-scrollbar gap-1 max-w-full">
      <button
        type="button"
        @click="activeTab = 'divisions'"
        :class="[
          'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer select-none whitespace-nowrap shrink-0 flex items-center gap-1.5',
          activeTab === 'divisions'
            ? 'bg-white text-[#0c2340] shadow-xs font-semibold'
            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
        ]"
      >
        <span>Divisi</span>
        <span
          :class="[
            'px-1.5 py-0.5 rounded-full text-[10px] font-semibold leading-none',
            activeTab === 'divisions' ? 'bg-blue-50 text-[#0c2340]' : 'bg-zinc-200/70 text-zinc-500'
          ]"
        >
          {{ divisions.length }}
        </span>
      </button>

      <button
        type="button"
        @click="activeTab = 'stages'"
        :class="[
          'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer select-none whitespace-nowrap shrink-0 flex items-center gap-1.5',
          activeTab === 'stages'
            ? 'bg-white text-[#0c2340] shadow-xs font-semibold'
            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
        ]"
      >
        <span>Pipeline Stages</span>
        <span
          :class="[
            'px-1.5 py-0.5 rounded-full text-[10px] font-semibold leading-none',
            activeTab === 'stages' ? 'bg-blue-50 text-[#0c2340]' : 'bg-zinc-200/70 text-zinc-500'
          ]"
        >
          {{ stages.length }}
        </span>
      </button>

      <button
        type="button"
        @click="activeTab = 'approvers'"
        :class="[
          'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer select-none whitespace-nowrap shrink-0 flex items-center gap-1.5',
          activeTab === 'approvers'
            ? 'bg-white text-[#0c2340] shadow-xs font-semibold'
            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
        ]"
      >
        <span>Approvers</span>
        <span
          :class="[
            'px-1.5 py-0.5 rounded-full text-[10px] font-semibold leading-none',
            activeTab === 'approvers' ? 'bg-blue-50 text-[#0c2340]' : 'bg-zinc-200/70 text-zinc-500'
          ]"
        >
          {{ approvers.length }}
        </span>
      </button>

      <button
        type="button"
        @click="activeTab = 'ai'"
        :class="[
          'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer select-none whitespace-nowrap shrink-0',
          activeTab === 'ai'
            ? 'bg-white text-[#0c2340] shadow-xs font-semibold'
            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
        ]"
      >
        Integrasi AI
      </button>

      <button
        type="button"
        @click="activeTab = 'mail_gateway'"
        :class="[
          'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer select-none whitespace-nowrap shrink-0',
          activeTab === 'mail_gateway'
            ? 'bg-white text-[#0c2340] shadow-xs font-semibold'
            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
        ]"
      >
        Gateway Email
      </button>

      <button
        type="button"
        @click="activeTab = 'whatsapp_gateway'"
        :class="[
          'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer select-none whitespace-nowrap shrink-0',
          activeTab === 'whatsapp_gateway'
            ? 'bg-white text-[#0c2340] shadow-xs font-semibold'
            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
        ]"
      >
        Gateway WhatsApp
      </button>

      <button
        type="button"
        @click="activeTab = 'mail_templates'"
        :class="[
          'px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer select-none whitespace-nowrap shrink-0',
          activeTab === 'mail_templates'
            ? 'bg-white text-[#0c2340] shadow-xs font-semibold'
            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
        ]"
      >
        Template Email
      </button>
    </div>

    <!-- Loading State (Only if initial load has no data yet) -->
    <div v-if="store.loading.configurations && !divisions.length && !stages.length" class="bg-white rounded-xl border border-slate-200 shadow-xs">
      <LoadingState
        title="Sedang memuat data..."
        subtitle="Menyiapkan master konfigurasi rekrutmen..."
      />
    </div>

    <template v-else>
      <!-- DIVISIONS TABLE -->
      <div
        v-if="activeTab === 'divisions'"
        class="bg-white rounded-xl border border-zinc-200 shadow-2xs overflow-hidden"
      >
        <div class="p-4 border-b border-zinc-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-zinc-50/50">
          <div class="text-xs font-semibold text-zinc-800 uppercase tracking-wider">
            Daftar Master Divisi per Badan Usaha
          </div>
          <div class="flex items-center gap-2 flex-wrap">
            <div class="relative min-w-[200px]">
              <select
                v-model="divisionCompanyFilter"
                class="w-full h-8 bg-white border border-zinc-200 rounded-md pl-3 pr-8 text-xs text-zinc-800 focus:outline-none focus:ring-1 focus:ring-zinc-950 focus:border-zinc-950 cursor-pointer appearance-none transition-colors"
              >
                <option value="all">Semua Badan Usaha</option>
                <option v-for="company in divisionCompanies" :key="company.id" :value="String(company.id)">
                  {{ company.name }}
                </option>
              </select>
              <ChevronDown class="w-3.5 h-3.5 text-zinc-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
            </div>

            <Button
              size="sm"
              variant="default"
              @click="openDivisionModal()"
              class="h-8 text-xs bg-[#0c2340] hover:bg-[#153459] text-white gap-1.5 shadow-xs"
            >
              <Plus class="w-3.5 h-3.5" />
              <span>Tambah Divisi</span>
            </Button>
          </div>
        </div>

        <Table>
          <TableHeader>
            <TableRow>
              <TableHead class="w-20">ID</TableHead>
              <TableHead>Nama Divisi</TableHead>
              <TableHead>Badan Usaha</TableHead>
              <TableHead>Status Operasional</TableHead>
              <TableHead class="text-right w-28">Aksi</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow
              v-for="div in filteredDivisions"
              :key="div.id"
              class="hover:bg-zinc-50/80 transition-colors"
            >
              <TableCell class="tabular-nums text-zinc-400 font-semibold text-xs">#{{ div.id }}</TableCell>
              <TableCell class="font-semibold text-zinc-900 text-xs">{{ div.name }}</TableCell>
              <TableCell class="text-zinc-700 text-xs font-medium">{{ div.company_name || div.badan_usaha || div.company?.name || '-' }}</TableCell>
              <TableCell>
                <Badge
                  :variant="div.is_active ? 'success' : 'secondary'"
                  class="text-[10px] px-2 py-0.5"
                >
                  {{ div.is_active ? 'Aktif' : 'Nonaktif' }}
                </Badge>
              </TableCell>
              <TableCell class="text-right">
                <div class="flex items-center justify-end gap-1">
                  <Button
                    variant="ghost"
                    size="xs"
                    @click="openDivisionModal(div)"
                    class="h-7 w-7 p-0 text-zinc-500 hover:text-zinc-900"
                    title="Edit Divisi"
                  >
                    <Pencil class="w-3.5 h-3.5" />
                  </Button>
                  <Button
                    variant="ghost"
                    size="xs"
                    @click="confirmDeleteDivision(div)"
                    class="h-7 w-7 p-0 text-zinc-500 hover:text-rose-600 hover:bg-rose-50"
                    title="Hapus Divisi"
                  >
                    <Trash2 class="w-3.5 h-3.5" />
                  </Button>
                </div>
              </TableCell>
            </TableRow>
            <TableRow v-if="!filteredDivisions.length">
              <TableCell colspan="5" class="py-12 text-center text-xs text-zinc-500">
                Belum ada master divisi terdaftar.
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>

      <!-- STAGES TABLE -->
      <div
        v-else-if="activeTab === 'stages'"
        class="bg-white rounded-xl border border-zinc-200 shadow-2xs overflow-hidden"
      >
        <div class="p-4 border-b border-zinc-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-zinc-50/50">
          <div class="flex items-center gap-2">
            <span class="text-xs font-semibold text-zinc-800 uppercase tracking-wider">
              Tahapan Seleksi Pelamar
            </span>
            <span v-if="isReorderingStages" class="text-[11px] text-zinc-500 flex items-center gap-1">
              <RotateCw class="w-3 h-3 animate-spin" />
              Menyimpan...
            </span>
          </div>

          <div class="flex items-center gap-2 flex-wrap">
            <!-- Pipeline Selector Dropdown -->
            <div class="relative min-w-[170px]">
              <select
                v-model="selectedPipelineId"
                class="w-full h-8 bg-white border border-zinc-200 rounded-md pl-2.5 pr-7 text-xs text-zinc-800 focus:outline-none focus:ring-1 focus:ring-zinc-950 appearance-none cursor-pointer"
              >
                <option v-for="p in pipelines" :key="p.id" :value="p.id">
                  {{ p.name }}
                </option>
              </select>
              <ChevronDown class="w-3.5 h-3.5 text-zinc-400 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none" />
            </div>

            <!-- Quick Actions for selected pipeline -->
            <Button
              variant="outline"
              size="sm"
              @click="openEditPipelineModal(currentPipeline)"
              class="h-8 w-8 p-0 text-zinc-600 hover:text-zinc-900 border-zinc-200"
              title="Edit nama pipeline"
            >
              <Pencil class="w-3.5 h-3.5" />
            </Button>

            <Button
              v-if="currentPipeline && currentPipeline.id !== 1"
              variant="outline"
              size="sm"
              @click="handleDeletePipeline(currentPipeline)"
              class="h-8 w-8 p-0 text-rose-600 hover:bg-rose-50 border-zinc-200"
              title="Hapus pipeline"
            >
              <Trash2 class="w-3.5 h-3.5" />
            </Button>

            <!-- Create New Master Pipeline Button -->
            <Button
              size="sm"
              variant="outline"
              @click="openCreatePipelineModal"
              class="h-8 text-xs gap-1 text-zinc-700 border-zinc-200"
            >
              <Plus class="w-3.5 h-3.5" />
              <span>Pipeline Baru</span>
            </Button>

            <!-- Add Stage to current pipeline -->
            <Button
              size="sm"
              variant="default"
              @click="openCreateStageModal"
              class="h-8 text-xs bg-[#0c2340] hover:bg-[#153459] text-white gap-1 shadow-xs"
            >
              <Plus class="w-3.5 h-3.5" />
              <span>Tambah Tahap</span>
            </Button>
          </div>
        </div>

        <Table>
          <TableHeader>
            <TableRow>
              <TableHead class="w-20">Urutan</TableHead>
              <TableHead>Nama Tahap</TableHead>
              <TableHead>Total Kandidat</TableHead>
              <TableHead class="text-right w-24 pr-4">Aksi</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow
              v-for="(stage, idx) in displayedStages"
              :key="stage.id"
              :draggable="!stage.is_locked"
              @dragstart="handleStageDragStart(stage, idx, $event)"
              @dragover.prevent="handleStageDragOver(stage, idx, $event)"
              @dragleave="handleStageDragLeave(stage, idx, $event)"
              @drop.prevent="handleStageDrop(stage, idx, $event)"
              @dragend="handleStageDragEnd"
              :class="[
                'transition-all duration-150 select-none group',
                !stage.is_locked ? 'cursor-grab active:cursor-grabbing' : 'cursor-default',
                draggedStageIndex === idx ? 'opacity-35 bg-zinc-100 scale-[0.99]' : '',
                dragOverStageIndex === idx && draggedStageIndex !== idx
                  ? 'bg-blue-50/80 border-l-4 border-l-[#0c2340]'
                  : 'hover:bg-zinc-50/80'
              ]"
            >
              <TableCell class="font-bold text-zinc-500">
                <div class="flex items-center gap-2">
                  <GripVertical
                    :class="[
                      'w-3.5 h-3.5 transition-colors shrink-0',
                      stage.is_locked
                        ? 'text-zinc-200 cursor-not-allowed'
                        : 'text-zinc-400 group-hover:text-zinc-700 cursor-grab active:cursor-grabbing'
                    ]"
                  />
                  <span class="w-5 h-5 rounded-full bg-zinc-100 flex items-center justify-center text-[11px] tabular-nums font-semibold text-zinc-700">
                    {{ idx + 1 }}
                  </span>
                </div>
              </TableCell>
              <TableCell>
                <div class="flex items-center gap-2">
                  <span class="font-semibold text-zinc-900 text-xs">{{ stage.name }}</span>
                  <span
                    v-if="stage.is_locked"
                    class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-zinc-100 text-zinc-600 border border-zinc-200"
                  >
                    Final
                  </span>
                </div>
              </TableCell>
              <TableCell>
                <Badge variant="secondary" class="text-[10px] px-2 py-0.5 font-medium">
                  {{ stage.applications_count || 0 }} Pelamar
                </Badge>
              </TableCell>
              <TableCell class="text-right whitespace-nowrap pr-4">
                <div class="flex items-center justify-end gap-1" draggable="false" @mousedown.stop>
                  <button
                    type="button"
                    @click.stop="openEditStageModal(stage)"
                    :disabled="stage.is_locked"
                    :class="[
                      'p-1.5 rounded-md transition-colors',
                      stage.is_locked
                        ? 'text-zinc-300 cursor-not-allowed'
                        : 'text-zinc-500 hover:text-zinc-900 hover:bg-zinc-100 cursor-pointer'
                    ]"
                    :title="stage.is_locked ? 'Tahap final tidak dapat diedit' : 'Edit'"
                  >
                    <Pencil class="w-3.5 h-3.5" />
                  </button>
                  <button
                    type="button"
                    @click.stop="handleDeleteStage(stage)"
                    :disabled="stage.is_locked"
                    :class="[
                      'p-1.5 rounded-md transition-colors',
                      stage.is_locked
                        ? 'text-zinc-300 cursor-not-allowed'
                        : 'text-zinc-400 hover:text-rose-600 hover:bg-rose-50 cursor-pointer'
                    ]"
                    :title="stage.is_locked ? 'Tahap final tidak dapat dihapus' : 'Hapus'"
                  >
                    <Trash2 class="w-3.5 h-3.5" />
                  </button>
                </div>
              </TableCell>
            </TableRow>
            <TableRow v-if="!displayedStages.length">
              <TableCell colspan="4" class="py-12 text-center text-xs text-zinc-500">
                Belum ada tahapan pipeline terdaftar.
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>

      <!-- APPROVERS TABLE -->
      <div
        v-else-if="activeTab === 'approvers'"
        class="bg-white rounded-xl border border-zinc-200 shadow-2xs overflow-hidden"
      >
        <div class="p-4 border-b border-zinc-100 flex items-center justify-between bg-zinc-50/50">
          <div class="text-xs font-semibold text-zinc-800 uppercase tracking-wider">
            Daftar Konfigurasi Approver FPTK
          </div>
        </div>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead class="w-24">Level</TableHead>
              <TableHead>Nama Approver</TableHead>
              <TableHead>Jabatan / Role</TableHead>
              <TableHead>Divisi / Badan Usaha</TableHead>
              <TableHead>Email Notifikasi</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow
              v-for="appr in approvers"
              :key="appr.id"
              class="hover:bg-zinc-50/80 transition-colors"
            >
              <TableCell>
                <Badge variant="secondary" class="text-[10px] font-medium">Level {{ appr.level || 1 }}</Badge>
              </TableCell>
              <TableCell class="font-semibold text-zinc-900 text-xs">{{ appr.name }}</TableCell>
              <TableCell class="text-zinc-600 text-xs">{{ appr.role || appr.position || appr.title || 'Head of Dept' }}</TableCell>
              <TableCell>
                <div class="font-medium text-xs text-zinc-800">{{ appr.division?.name || appr.divisi || '-' }}</div>
                <div class="text-[11px] text-zinc-400">{{ appr.company?.name || appr.division?.company?.name || '-' }}</div>
              </TableCell>
              <TableCell class="text-zinc-500 text-xs">{{ appr.email }}</TableCell>
            </TableRow>
            <TableRow v-if="!approvers.length">
              <TableCell colspan="5" class="py-12 text-center text-xs text-zinc-500">
                Belum ada approver terdaftar.
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>

      <!-- AI SETTINGS TAB -->
      <div
        v-else-if="activeTab === 'ai'"
        class="max-w-3xl"
      >
        <Card>
          <CardHeader class="border-b border-zinc-100 pb-4">
            <CardTitle>Konfigurasi OpenAI Compatible API</CardTitle>
            <CardDescription>
              Kunci API ini digunakan untuk analisis dan evaluasi kualifikasi CV kandidat pelamar secara otomatis.
            </CardDescription>
          </CardHeader>
          <CardContent v-if="!aiSettingsDenied" class="p-6 space-y-4">
            <label class="flex items-start gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 cursor-pointer">
              <input v-model="aiForm.automatic" type="checkbox" class="mt-0.5 rounded border-zinc-300 accent-[#0c2340]" />
              <span>
                <span class="block text-xs font-semibold text-zinc-900">Analisis otomatis CV baru</span>
                <span class="mt-1 block text-xs text-zinc-500">CV baru atau yang diperbarui langsung masuk antrean. Analisis tetap berjalan walau halaman ditutup. Keputusan seleksi tetap di tangan HR.</span>
              </span>
            </label>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div class="sm:col-span-2">
                <label for="ai-base-url" class="mb-1.5 block text-xs font-medium text-zinc-800">Endpoint API</label>
                <Input id="ai-base-url" v-model="aiForm.base_url" type="url" placeholder="https://router.rizqis.com/v1" class="font-mono h-9" />
              </div>
              <div>
                <label for="ai-model" class="mb-1.5 block text-xs font-medium text-zinc-800">Model</label>
                <Input id="ai-model" v-model="aiForm.model" placeholder="ID model dari provider" class="font-mono h-9" />
              </div>
              <div class="flex flex-col justify-center gap-1 text-xs">
                <span class="text-zinc-500">Provider</span>
                <span class="font-mono text-zinc-800">{{ aiSettings.provider || 'openai_compatible' }}</span>
              </div>
            </div>

            <div>
              <label for="ai-api-key" class="block font-medium text-xs text-zinc-800 mb-1.5">OpenAI Compatible API Key</label>
              <div class="relative">
                <Input
                  :type="showApiKey ? 'text' : 'password'"
                  id="ai-api-key"
                  v-model="aiFormKey"
                  autocomplete="new-password"
                  :disabled="aiForm.clear_api_key"
                  :placeholder="aiSettings.has_api_key ? 'Kosongkan untuk tetap memakai key tersimpan' : 'sk-...'"
                  class="font-mono pr-24 h-9"
                />
                <button
                  type="button"
                  @click="showApiKey = !showApiKey"
                  class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs font-medium text-zinc-500 hover:text-zinc-900 cursor-pointer"
                >
                  {{ showApiKey ? 'Sembunyikan' : 'Lihat' }}
                </button>
              </div>
            </div>

            <div class="space-y-2 text-xs text-zinc-500">
              <p>{{ aiSettings.has_api_key ? (aiSettings.is_database ? 'API key sudah tersimpan di Master Rekrutmen.' : 'API key tersedia dari konfigurasi server.') : 'API key belum dikonfigurasi.' }} Key tersimpan tidak ditampilkan kembali.</p>
              <label v-if="aiSettings.has_api_key" class="flex items-center gap-2 text-rose-700 cursor-pointer">
                <input v-model="aiForm.clear_api_key" type="checkbox" class="rounded border-zinc-300 accent-rose-700" />
                Hapus API key tersimpan saat menyimpan
              </label>
              <p v-if="aiForm.clear_api_key">API key dihapus; analisis berhenti sampai key baru disimpan.</p>
            </div>

            <div v-if="aiSettings.updated_at" class="text-[11px] text-zinc-400">
              Terakhir diperbarui: {{ aiSettings.updated_at }}
            </div>

            <div class="pt-3 border-t border-zinc-100 flex items-center justify-between">
              <Button
                type="button"
                variant="outline"
                size="sm"
                @click="testAiConnection"
                :disabled="isTestingAi || aiForm.clear_api_key || (!aiFormKey.trim() && !aiSettings.has_api_key) || !aiForm.base_url || !aiForm.model"
                class="gap-1.5"
              >
                <RotateCw v-if="isTestingAi" class="w-3.5 h-3.5 animate-spin" />
                <span>{{ isTestingAi ? 'Menguji Koneksi...' : 'Uji Koneksi API' }}</span>
              </Button>

              <Button
                type="button"
                variant="default"
                size="sm"
                @click="saveAiSettings"
                :disabled="isSavingAi"
                class="bg-[#0c2340] hover:bg-[#153459] text-white gap-1.5 shadow-xs"
              >
                <RotateCw v-if="isSavingAi" class="w-3.5 h-3.5 animate-spin" />
                <span>{{ isSavingAi ? 'Menyimpan...' : 'Simpan Perubahan' }}</span>
              </Button>
            </div>
          </CardContent>
          <CardContent v-else class="p-6">
            <p role="alert" class="text-sm text-zinc-600">Anda tidak memiliki akses untuk mengelola pengaturan AI.</p>
          </CardContent>
        </Card>
      </div>

      <!-- EMAIL GATEWAY TAB -->
      <div
        v-else-if="activeTab === 'mail_gateway'"
        class="max-w-4xl"
      >
        <Card>
          <CardHeader class="border-b border-zinc-100 pb-4">
            <CardTitle>Gateway Email Rekrutmen</CardTitle>
            <CardDescription>
              SMTP ini khusus dipakai untuk notifikasi pelamar rekrutmen. Pengaturan mail modul ERP lain tidak terpengaruh.
            </CardDescription>
          </CardHeader>
          <CardContent class="p-6 space-y-5">
            <label class="flex items-center gap-2.5 cursor-pointer select-none">
              <input
                type="checkbox"
                v-model="mailForm.enabled"
                class="rounded border-zinc-300 text-zinc-900 accent-zinc-900 focus:ring-0 w-4 h-4 cursor-pointer"
              />
              <span class="text-xs font-semibold text-zinc-800">Gunakan SMTP dari konfigurasi ini</span>
            </label>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block font-medium text-xs text-zinc-800 mb-1.5">Host SMTP</label>
                <Input v-model="mailForm.host" type="text" placeholder="smtp.gmail.com" class="h-9" />
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block font-medium text-xs text-zinc-800 mb-1.5">Port</label>
                  <Input v-model="mailForm.port" type="number" placeholder="587" class="h-9" />
                </div>
                <div>
                  <label class="block font-medium text-xs text-zinc-800 mb-1.5">Enkripsi</label>
                  <div class="relative">
                    <select
                      v-model="mailForm.encryption"
                      class="w-full h-9 bg-white border border-zinc-200 rounded-md px-3 text-xs text-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-950 focus:border-zinc-950 appearance-none pr-8 cursor-pointer"
                    >
                      <option value="tls">TLS</option>
                      <option value="ssl">SSL</option>
                      <option value="none">Tanpa enkripsi</option>
                    </select>
                    <ChevronDown class="w-3.5 h-3.5 text-zinc-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                  </div>
                </div>
              </div>
              <div>
                <label class="block font-medium text-xs text-zinc-800 mb-1.5">Username</label>
                <Input v-model="mailForm.username" type="text" autocomplete="off" class="h-9" />
              </div>
              <div>
                <label class="block font-medium text-xs text-zinc-800 mb-1.5">Password</label>
                <Input
                  v-model="mailForm.password"
                  :placeholder="mailSettings.has_password ? 'Kosongkan jika tidak diubah' : 'Password SMTP'"
                  type="password"
                  autocomplete="new-password"
                  class="h-9"
                />
              </div>
              <div>
                <label class="block font-medium text-xs text-zinc-800 mb-1.5">Email Pengirim</label>
                <Input v-model="mailForm.from_address" type="email" placeholder="rekrutmen@perusahaan.com" class="h-9" />
              </div>
              <div>
                <label class="block font-medium text-xs text-zinc-800 mb-1.5">Nama Pengirim</label>
                <Input v-model="mailForm.from_name" type="text" placeholder="Tim Rekrutmen" class="h-9" />
              </div>
              <div>
                <label class="block font-medium text-xs text-zinc-800 mb-1.5">Reply-To</label>
                <Input v-model="mailForm.reply_to_address" type="email" class="h-9" />
              </div>
              <div>
                <label class="block font-medium text-xs text-zinc-800 mb-1.5">Nama Reply-To</label>
                <Input v-model="mailForm.reply_to_name" type="text" class="h-9" />
              </div>
            </div>

            <div class="pt-4 border-t border-zinc-100 flex flex-col sm:flex-row sm:items-end gap-3">
              <div class="flex-1">
                <label class="block font-medium text-xs text-zinc-800 mb-1.5">Email tujuan tes</label>
                <Input v-model="mailTestRecipient" type="email" placeholder="anda@perusahaan.com" class="h-9" />
              </div>
              <div class="flex items-center gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  @click="testMailSettings"
                  :disabled="isTestingMail"
                  class="h-9 gap-1.5"
                >
                  <RotateCw v-if="isTestingMail" class="w-3.5 h-3.5 animate-spin" />
                  <span>{{ isTestingMail ? 'Menguji...' : 'Kirim Email Tes' }}</span>
                </Button>
                <Button
                  type="button"
                  variant="default"
                  size="sm"
                  @click="saveMailSettings"
                  :disabled="isSavingMail"
                  class="h-9 bg-[#0c2340] hover:bg-[#153459] text-white gap-1.5 shadow-xs"
                >
                  <RotateCw v-if="isSavingMail" class="w-3.5 h-3.5 animate-spin" />
                  <span>{{ isSavingMail ? 'Menyimpan...' : 'Simpan SMTP' }}</span>
                </Button>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- WHATSAPP GATEWAY TAB -->
      <div
        v-else-if="activeTab === 'whatsapp_gateway'"
        class="space-y-6 max-w-4xl"
      >
        <p v-if="whatsappAccessError" role="alert" class="text-sm text-rose-700">{{ whatsappAccessError }}</p>
        <template v-else>
        <Card>
          <CardHeader class="border-b border-zinc-100 pb-4 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
            <div>
              <CardTitle>Hubungkan WhatsApp</CardTitle>
              <CardDescription>
                Scan QR dari HP atau gunakan nomor untuk pairing code. Menggunakan Baileys multi-device engine tanpa biaya API pihak ketiga.
              </CardDescription>
            </div>
            <Badge
              :variant="whatsappSettings.engine_ready ? 'success' : 'warning'"
              class="text-[10px] px-2 py-0.5 self-start"
            >
              {{ whatsappSettings.engine_ready ? 'Engine Siap' : 'Engine Belum Jalan' }}
            </Badge>
          </CardHeader>

          <CardContent class="p-6 space-y-5">
            <label class="flex items-center gap-2.5 cursor-pointer select-none">
              <input
                type="checkbox"
                v-model="whatsappForm.enabled"
                class="rounded border-zinc-300 text-zinc-900 accent-zinc-900 focus:ring-0 w-4 h-4 cursor-pointer"
              />
              <span class="text-xs font-semibold text-zinc-800">Aktifkan pengiriman WhatsApp rekrutmen</span>
            </label>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block font-medium text-xs text-zinc-800 mb-1.5">Nama Sesi (opsional)</label>
                <Input v-model="whatsappConnectForm.name" type="text" placeholder="HR Recruitment" class="h-9" />
              </div>
              <div>
                <label class="block font-medium text-xs text-zinc-800 mb-1.5">Nomor HP WhatsApp (wajib untuk kode pairing)</label>
                <Input v-model="whatsappConnectForm.phone_number" type="text" placeholder="0812xxxxxxx" class="h-9" />
              </div>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pt-3 border-t border-zinc-100">
              <Button
                type="button"
                variant="outline"
                size="sm"
                @click="saveWhatsappSettings"
                :disabled="isSavingWhatsapp"
                class="h-9"
              >
                {{ isSavingWhatsapp ? 'Menyimpan...' : 'Simpan Status Aktif' }}
              </Button>
              <div class="flex items-center gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  @click="startWhatsappConnect('qr')"
                  :disabled="isConnectingWhatsapp || isChangingWhatsappAccount"
                  class="h-9"
                >
                  {{ isConnectingWhatsapp ? 'Menyiapkan...' : 'Scan QR' }}
                </Button>
                <Button
                  type="button"
                  variant="default"
                  size="sm"
                  @click="startWhatsappConnect('pairing')"
                  :disabled="isConnectingWhatsapp || isChangingWhatsappAccount"
                  class="h-9 bg-[#0c2340] hover:bg-[#153459] text-white shadow-xs"
                >
                  {{ isConnectingWhatsapp ? 'Membuat kode...' : 'Dapatkan Kode Pairing' }}
                </Button>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- QR / Pairing Code Card -->
        <Card v-if="whatsappConnect.open" class="border-emerald-200 shadow-sm">
          <CardHeader class="border-b border-zinc-100 pb-3 flex flex-row items-center justify-between">
            <div>
              <CardTitle class="text-sm font-semibold text-zinc-900">
                {{ whatsappConnect.mode === 'pairing' ? 'Masukkan Kode Pairing' : 'Scan QR Code untuk Menautkan' }}
              </CardTitle>
              <CardDescription>
                WhatsApp di HP → Perangkat tertaut → Tautkan perangkat{{ whatsappConnect.pairingCode ? ' → tautkan dengan nomor telepon' : '' }}.
              </CardDescription>
            </div>
            <Button variant="ghost" size="xs" @click="closeWhatsappConnect">Tutup</Button>
          </CardHeader>

          <CardContent class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
              <div class="flex justify-center">
                <img
                  v-if="whatsappConnect.qr"
                  :src="whatsappConnect.qr"
                  alt="QR WhatsApp"
                  class="w-52 h-52 rounded-xl border border-zinc-200 bg-white p-2 shadow-2xs"
                />
                <div v-else class="w-52 h-52 rounded-xl border border-dashed border-zinc-300 flex items-center justify-center text-xs text-zinc-400 text-center px-4">
                  {{ whatsappConnect.mode === 'pairing' ? 'Menyiapkan kode pairing...' : 'Menyiapkan QR...' }}
                </div>
              </div>
              <div class="space-y-3">
                <div v-if="whatsappConnect.pairingCode" class="rounded-xl bg-emerald-50/70 border border-emerald-200 p-4 text-center">
                  <div class="text-[11px] uppercase tracking-wider text-emerald-800 font-semibold">Kode Pairing</div>
                  <div class="mt-2 text-2xl font-mono font-bold tracking-[0.35em] text-zinc-900">{{ whatsappConnect.pairingCode }}</div>
                  <p class="text-[11px] text-zinc-600 mt-2">Di HP pilih tautkan dengan nomor telepon, lalu ketik kode ini.</p>
                  <Button type="button" variant="outline" size="xs" @click="copyPairingCode" class="mt-3">
                    Salin Kode
                  </Button>
                </div>
                <div v-else-if="whatsappConnect.status === 'pairing'" class="rounded-xl bg-zinc-50 border border-zinc-200 p-4 text-center text-xs text-zinc-500">
                  Membuat kode pairing...
                </div>
                <div class="text-xs text-zinc-600">
                  Status: <strong>{{ whatsappStatusLabel(whatsappConnect) }}</strong>
                </div>
                <p v-if="whatsappConnect.engine_error" class="text-xs text-rose-600">{{ whatsappConnect.engine_error }}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Connected Accounts Table -->
        <Card>
          <CardHeader class="border-b border-zinc-100 pb-3">
            <CardTitle>Nomor yang Sudah Terhubung</CardTitle>
            <CardDescription>Saat mengirim notifikasi ke pelamar, Anda dapat memilih salah satu nomor ini sebagai pengirim.</CardDescription>
          </CardHeader>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Nama</TableHead>
                <TableHead>Nomor Telepon</TableHead>
                <TableHead>Status</TableHead>
                <TableHead class="text-right">Aksi</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="account in whatsappAccounts" :key="account.id" class="hover:bg-zinc-50/80">
                <TableCell>
                  <div class="font-semibold text-xs text-zinc-900">{{ account.name }}</div>
                  <Badge v-if="account.is_default" variant="navy" class="text-[9px] px-1.5 py-0 mt-0.5">Default</Badge>
                </TableCell>
                <TableCell class="tabular-nums text-xs text-zinc-600">{{ account.phone_number || '-' }}</TableCell>
                <TableCell>
                  <Badge
                    :variant="account.status === 'connected' ? 'success' : (account.status === 'connecting' ? 'warning' : 'secondary')"
                    class="text-[10px] px-2 py-0.5"
                  >
                    {{ whatsappStatusLabel(account) }}
                  </Badge>
                </TableCell>
                <TableCell class="text-right">
                  <div class="flex items-center justify-end gap-1.5">
                    <Button v-if="account.status === 'connected' && account.is_active" :disabled="isTestingWhatsapp || !whatsappSettings.engine_ready || account.delivery_ready === false" variant="outline" size="xs" @click="openWhatsappTest(account)" class="h-7 text-xs">Tes</Button>
                    <Button v-if="account.status !== 'connected'" :disabled="isConnectingWhatsapp || isChangingWhatsappAccount" variant="outline" size="xs" @click="startWhatsappConnect(account, 'qr')" class="h-7 text-xs">Scan</Button>
                    <Button v-if="account.status !== 'connected'" :disabled="isConnectingWhatsapp || isChangingWhatsappAccount" variant="outline" size="xs" @click="startWhatsappConnect(account, 'pairing')" class="h-7 text-xs">Kode</Button>
                    <Button v-else :disabled="isConnectingWhatsapp || isChangingWhatsappAccount" variant="ghost" size="xs" @click="disconnectWhatsappAccount(account)" class="h-7 text-xs text-zinc-600">Putuskan</Button>
                    <Button v-if="!account.is_default" :disabled="isConnectingWhatsapp || isChangingWhatsappAccount" variant="ghost" size="xs" @click="makeDefaultWhatsappAccount(account)" class="h-7 text-xs text-blue-700">Default</Button>
                    <Button :disabled="isConnectingWhatsapp || isChangingWhatsappAccount" variant="ghost" size="xs" @click="deleteWhatsappAccount(account)" class="h-7 text-xs text-rose-600 hover:bg-rose-50">Hapus</Button>
                  </div>
                </TableCell>
              </TableRow>
              <TableRow v-if="!whatsappAccounts.length">
                <TableCell colspan="4" class="py-12 text-center text-xs text-zinc-400">
                  Belum ada nomor terdaftar. Klik Hubungkan WhatsApp lalu scan QR dari HP.
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </Card>

        <!-- Test Send Card -->
        <Card>
          <CardHeader class="border-b border-zinc-100 pb-3">
            <CardTitle>Tes Kirim WhatsApp</CardTitle>
            <CardDescription>
              Kirim pesan uji coba dari nomor yang sudah terhubung untuk memastikan gateway siap dipakai.
            </CardDescription>
          </CardHeader>
          <CardContent class="p-6 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block font-medium text-xs text-zinc-800 mb-1.5">Kirim dari Nomor</label>
                <div class="relative">
                  <select
                    v-model="whatsappTest.accountId"
                    class="w-full h-9 bg-white border border-zinc-200 rounded-md px-3 text-xs text-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-950 focus:border-zinc-950 appearance-none pr-8 cursor-pointer"
                  >
                    <option :value="null" disabled>Pilih nomor terhubung</option>
                    <option v-for="account in connectedWhatsappAccounts" :key="account.id" :value="account.id">
                      {{ account.name }}{{ account.phone_number ? ` • ${account.phone_number}` : '' }}
                    </option>
                  </select>
                  <ChevronDown class="w-3.5 h-3.5 text-zinc-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                </div>
              </div>
              <div>
                <label class="block font-medium text-xs text-zinc-800 mb-1.5">Nomor Tujuan Tes</label>
                <Input
                  v-model="whatsappTest.recipient"
                  type="text"
                  placeholder="0812xxxxxxx"
                  class="h-9"
                />
              </div>
            </div>

            <div class="flex justify-end pt-2">
              <Button
                type="button"
                variant="default"
                size="sm"
                @click="sendWhatsappTest()"
                :disabled="isTestingWhatsapp || !whatsappTest.accountId"
                class="h-9 bg-[#0c2340] hover:bg-[#153459] text-white gap-1.5 shadow-xs"
              >
                <RotateCw v-if="isTestingWhatsapp" class="w-3.5 h-3.5 animate-spin" />
                <span>{{ isTestingWhatsapp ? 'Mengirim tes...' : 'Kirim Pesan Tes' }}</span>
              </Button>
            </div>
          </CardContent>
        </Card>
        </template>
      </div>

      <!-- MAIL TEMPLATES TAB (Master Table View) -->
      <div
        v-else-if="activeTab === 'mail_templates'"
        class="bg-white rounded-xl border border-zinc-200 shadow-2xs overflow-hidden font-sans"
      >
        <div class="p-4 border-b border-zinc-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-zinc-50/50">
          <div class="flex items-center gap-2.5">
            <span class="text-xs font-semibold text-zinc-800 uppercase tracking-wider">
              Daftar Template Email Notifikasi Pelamar
            </span>
            <FBadge theme="blue" variant="subtle" size="sm">
              {{ Object.keys(mailTemplates).length }} Template
            </FBadge>
          </div>
          <p class="text-xs text-zinc-500">
            Klik tombol Edit pada baris untuk menyesuaikan subjek, pesan, dan instruksi email resmi.
          </p>
        </div>

        <Table>
          <TableHeader>
            <TableRow class="bg-zinc-50/30">
              <TableHead class="w-12 text-center">#</TableHead>
              <TableHead class="w-64">Nama Template</TableHead>
              <TableHead class="w-36">Tahap Terkait</TableHead>
              <TableHead>Subjek Email Resmi</TableHead>
              <TableHead class="w-48">Tautan Aksi (CTA)</TableHead>
              <TableHead class="text-right w-24 pr-4">Aksi</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow
              v-for="(tpl, key, idx) in mailTemplates"
              :key="key"
              class="hover:bg-zinc-50/80 transition-colors cursor-pointer group"
              @click="openEditTemplateModal(key, tpl)"
            >
              <TableCell class="text-center tabular-nums text-zinc-400 font-semibold text-xs">
                {{ idx + 1 }}
              </TableCell>
              <TableCell>
                <div class="font-semibold text-zinc-900 text-xs">
                  {{ tpl.name }}
                </div>
                <span v-if="tpl.badge" class="inline-block text-[10px] text-zinc-400 mt-0.5">
                  Badge: {{ tpl.badge }}
                </span>
              </TableCell>
              <TableCell>
                <FBadge theme="blue" variant="subtle" size="sm" class="font-medium">
                  {{ tpl.stage }}
                </FBadge>
              </TableCell>
              <TableCell>
                <div class="text-xs text-zinc-800 font-medium truncate max-w-md" :title="tpl.subject">
                  {{ tpl.subject }}
                </div>
                <p class="text-[11px] text-zinc-400 truncate max-w-md mt-0.5 line-clamp-1">
                  {{ tpl.body }}
                </p>
              </TableCell>
              <TableCell>
                <div v-if="tpl.has_link && (tpl.action_label || tpl.action_url)" class="space-y-1">
                  <span
                    v-if="tpl.action_label"
                    class="inline-flex items-center text-[11px] font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded shadow-2xs"
                  >
                    {{ tpl.action_label }}
                  </span>
                  <div
                    v-if="tpl.action_url"
                    class="text-[10.5px] text-blue-600 hover:underline truncate max-w-[200px]"
                    :title="tpl.action_url"
                  >
                    {{ tpl.action_url }}
                  </div>
                  <div v-else class="text-[10.5px] text-zinc-400 italic">
                    URL belum diatur
                  </div>
                </div>
                <span v-else class="text-xs text-zinc-400">-</span>
              </TableCell>
              <TableCell class="text-right pr-4" @click.stop>
                <Button
                  variant="outline"
                  size="sm"
                  @click="openEditTemplateModal(key, tpl)"
                  class="h-8 text-xs text-zinc-700 hover:text-zinc-900 hover:bg-zinc-100 gap-1.5 shadow-2xs border-zinc-200"
                >
                  <Pencil class="w-3.5 h-3.5 text-zinc-500" />
                  <span>Edit</span>
                </Button>
              </TableCell>
            </TableRow>
            <TableRow v-if="!Object.keys(mailTemplates).length">
              <TableCell colspan="6" class="py-12 text-center text-xs text-zinc-400">
                Memuat template email...
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
    </template>

    <!-- MODAL TAMBAH / EDIT DIVISI (Shadcn Dialog) -->
    <Dialog :open="divisionModal.open" @update:open="(val) => { divisionModal.open = val; }">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{{ divisionModal.isEdit ? 'Edit Master Divisi' : 'Tambah Master Divisi' }}</DialogTitle>
          <DialogDescription>
            Kelola nama divisi dan keterkaitannya dengan badan usaha (PT / CV).
          </DialogDescription>
        </DialogHeader>

        <!-- Form Body -->
        <form @submit.prevent="saveDivision" class="space-y-4 text-xs pt-2">
          <!-- Badan Usaha -->
          <div>
            <label class="block font-medium text-zinc-800 mb-1.5">Badan Usaha / Perusahaan <span class="text-red-500">*</span></label>
            <div class="relative">
              <select
                v-model="divisionModal.form.company_id"
                required
                class="w-full h-9 bg-white border border-zinc-200 rounded-md px-3 text-xs text-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-950 focus:border-zinc-950 appearance-none pr-8 cursor-pointer transition-colors"
              >
                <option :value="null" disabled>-- Pilih Badan Usaha / PT --</option>
                <option v-for="c in divisionCompanies" :key="c.id" :value="c.id">
                  {{ c.name }}
                </option>
              </select>
              <ChevronDown class="w-3.5 h-3.5 text-zinc-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
            </div>
          </div>

          <!-- Nama Divisi -->
          <div>
            <label class="block font-medium text-zinc-800 mb-1.5">Nama Divisi <span class="text-red-500">*</span></label>
            <Input
              v-model="divisionModal.form.name"
              type="text"
              required
              placeholder="Contoh: FINANCE ACCOUNTING, HCM, IT, dll"
              class="h-9"
            />
          </div>

          <!-- Status Aktif -->
          <div class="pt-1">
            <label class="flex items-center gap-2.5 cursor-pointer select-none">
              <input
                type="checkbox"
                v-model="divisionModal.form.is_active"
                class="rounded border-zinc-300 text-zinc-900 accent-zinc-900 focus:ring-0 w-4 h-4 cursor-pointer"
              />
              <div>
                <span class="text-xs font-semibold text-zinc-800">Status Aktif</span>
                <p class="text-[11px] text-zinc-400 font-normal">Divisi dapat dipilih pada saat pengajuan FPTK / MPP.</p>
              </div>
            </label>
          </div>

          <!-- Dialog Footer Actions -->
          <DialogFooter class="pt-4 border-t border-zinc-100">
            <Button
              type="button"
              variant="outline"
              size="sm"
              @click="divisionModal.open = false"
            >
              Batal
            </Button>
            <Button
              type="submit"
              size="sm"
              variant="default"
              :disabled="divisionModal.isSubmitting"
              class="bg-[#0c2340] hover:bg-[#153459] text-white min-w-[110px] shadow-xs"
            >
              <RotateCw v-if="divisionModal.isSubmitting" class="w-3.5 h-3.5 mr-1.5 animate-spin" />
              <span>{{ divisionModal.isSubmitting ? 'Menyimpan...' : (divisionModal.isEdit ? 'Simpan Perubahan' : 'Tambah Divisi') }}</span>
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>

    <!-- MODAL TAMBAH / EDIT TAHAP PIPELINE -->
    <Dialog :open="stageModal.open" @update:open="stageModal.open = $event">
      <DialogContent class="sm:max-w-[440px] p-6 bg-white rounded-2xl">
        <DialogHeader class="space-y-1 pb-2">
          <DialogTitle class="text-base font-bold text-zinc-900">
            {{ stageModal.isEdit ? 'Edit Tahapan Pipeline' : 'Tambah Tahapan Pipeline' }}
          </DialogTitle>
          <DialogDescription class="text-xs text-zinc-500">
            {{ stageModal.isEdit ? 'Perbarui nama tahapan seleksi dalam alur rekrutmen.' : 'Tambahkan tahapan baru ke dalam alur seleksi rekrutmen.' }}
          </DialogDescription>
        </DialogHeader>

        <form @submit.prevent="saveStage" class="space-y-4 pt-2">
          <div class="space-y-1.5">
            <label class="text-xs font-semibold text-zinc-700">Nama Tahap <span class="text-rose-500">*</span></label>
            <Input
              v-model="stageModal.form.name"
              placeholder="Contoh: Technical Test / FGD"
              class="h-9 text-xs"
              required
              autofocus
            />
          </div>

          <div class="p-3 bg-zinc-50 rounded-lg border border-zinc-100 text-[11px] text-zinc-500 space-y-1">
            <p class="font-medium text-zinc-700">Catatan:</p>
            <p>Tahapan baru akan ditambahkan sebelum tahapan final (Hired). Warna badge dan urutan disesuaikan otomatis.</p>
          </div>

          <DialogFooter class="pt-4 border-t border-zinc-100">
            <Button
              type="button"
              variant="outline"
              size="sm"
              @click="stageModal.open = false"
            >
              Batal
            </Button>
            <Button
              type="submit"
              size="sm"
              variant="default"
              :disabled="stageModal.isSubmitting"
              class="bg-[#0c2340] hover:bg-[#153459] text-white min-w-[110px] shadow-xs"
            >
              <RotateCw v-if="stageModal.isSubmitting" class="w-3.5 h-3.5 mr-1.5 animate-spin" />
              <span>{{ stageModal.isSubmitting ? 'Menyimpan...' : (stageModal.isEdit ? 'Simpan Perubahan' : 'Tambah Tahap') }}</span>
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>

    <!-- MODAL TAMBAH / EDIT MASTER PIPELINE -->
    <Dialog :open="pipelineModal.open" @update:open="pipelineModal.open = $event">
      <DialogContent class="sm:max-w-[480px] p-6 bg-white rounded-2xl">
        <DialogHeader class="space-y-1 pb-2">
          <DialogTitle class="text-base font-bold text-zinc-900">
            {{ pipelineModal.isEdit ? 'Ubah Master Pipeline' : 'Tambah Master Pipeline' }}
          </DialogTitle>
          <DialogDescription class="text-xs text-zinc-500">
            {{ pipelineModal.isEdit ? 'Perbarui informasi master pipeline rekrutmen.' : 'Buat master alur seleksi rekrutmen baru.' }}
          </DialogDescription>
        </DialogHeader>

        <form @submit.prevent="savePipeline" class="space-y-4 pt-2">
          <div class="space-y-1.5">
            <label class="text-xs font-semibold text-zinc-700">Nama Pipeline <span class="text-rose-500">*</span></label>
            <Input
              v-model="pipelineModal.form.name"
              placeholder="Contoh: Pipeline Divisi IT"
              class="h-9 text-xs"
              required
              autofocus
            />
          </div>

          <div class="space-y-1.5">
            <label class="text-xs font-semibold text-zinc-700">Deskripsi (Opsional)</label>
            <Input
              v-model="pipelineModal.form.description"
              placeholder="Contoh: Alur seleksi teknis posisi IT"
              class="h-9 text-xs"
            />
          </div>

          <div v-if="!pipelineModal.isEdit" class="space-y-1.5">
            <label class="text-xs font-semibold text-zinc-700">Salin Tahapan Dari</label>
            <div class="relative">
              <select
                v-model="pipelineModal.form.clone_from_pipeline_id"
                class="w-full h-9 bg-white border border-zinc-200 rounded-md px-3 text-xs text-zinc-800 focus:outline-none focus:ring-1 focus:ring-zinc-900 appearance-none pr-8 cursor-pointer"
              >
                <option :value="null">Tahapan Standar (5 Tahap)</option>
                <option v-for="p in pipelines" :key="p.id" :value="p.id">
                  {{ p.name }}
                </option>
              </select>
              <ChevronDown class="w-3.5 h-3.5 text-zinc-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
            </div>
          </div>

          <DialogFooter class="pt-4 border-t border-zinc-100">
            <Button
              type="button"
              variant="outline"
              size="sm"
              @click="pipelineModal.open = false"
            >
              Batal
            </Button>
            <Button
              type="submit"
              size="sm"
              variant="default"
              :disabled="pipelineModal.isSubmitting"
              class="bg-[#0c2340] hover:bg-[#153459] text-white min-w-[120px] shadow-xs"
            >
              <RotateCw v-if="pipelineModal.isSubmitting" class="w-3.5 h-3.5 mr-1.5 animate-spin" />
              <span>{{ pipelineModal.isSubmitting ? 'Menyimpan...' : (pipelineModal.isEdit ? 'Simpan Perubahan' : 'Buat Pipeline') }}</span>
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>

    <!-- MODAL EDIT TEMPLATE EMAIL (Frappe UI inside Shadcn Dialog) -->
    <Dialog :open="templateModal.open" @update:open="(val) => { templateModal.open = val; }">
      <DialogContent class="sm:max-w-2xl p-6 bg-white rounded-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader class="space-y-1.5 pb-3 border-b border-zinc-100">
          <div class="flex items-center justify-between gap-3">
            <DialogTitle class="text-base font-bold text-zinc-900">
              Edit Template: {{ templateModal.form.name }}
            </DialogTitle>
            <FBadge theme="blue" variant="subtle" size="sm" class="mr-6">
              Tahap: {{ templateModal.form.stage }}
            </FBadge>
          </div>
          <DialogDescription class="text-xs text-zinc-500">
            Perbarui format subjek, isi pesan surat, dan catatan instruksi untuk tahap ini.
          </DialogDescription>
        </DialogHeader>

        <form @submit.prevent="saveTemplateModal" class="space-y-4 pt-4">
          <!-- Variable Tags Info Box -->
          <div class="bg-zinc-50 border border-zinc-200/80 rounded-lg p-3 text-xs text-zinc-600 flex items-start gap-2.5">
            <Info class="w-4 h-4 text-sky-600 shrink-0 mt-0.5" />
            <div class="space-y-1 flex-1 min-w-0">
              <span class="font-semibold text-zinc-800 text-xs">Variabel Dinamis:</span>
              <p class="text-[11px] text-zinc-500">
                Gunakan placeholder berikut untuk digantikan otomatis dengan data asli:
              </p>
              <div class="flex flex-wrap items-center gap-1.5 pt-1">
                <code class="bg-white px-2 py-0.5 rounded border border-zinc-200 text-zinc-800 font-mono text-[11px] font-medium shadow-2xs">{nama_pelamar}</code>
                <code class="bg-white px-2 py-0.5 rounded border border-zinc-200 text-zinc-800 font-mono text-[11px] font-medium shadow-2xs">{posisi}</code>
                <code class="bg-white px-2 py-0.5 rounded border border-zinc-200 text-zinc-800 font-mono text-[11px] font-medium shadow-2xs">{perusahaan}</code>
                <code class="bg-white px-2 py-0.5 rounded border border-zinc-200 text-zinc-800 font-mono text-[11px] font-medium shadow-2xs">{lokasi}</code>
              </div>
            </div>
          </div>

          <!-- Subjek & Badge -->
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="sm:col-span-2 space-y-1.5">
              <label class="block text-xs font-semibold text-zinc-700">
                Subjek Email <span class="text-rose-500">*</span>
              </label>
              <FTextInput
                v-model="templateModal.form.subject"
                size="sm"
                variant="outline"
                placeholder="Subjek email resmi..."
                required
              />
            </div>

            <div class="space-y-1.5">
              <label class="block text-xs font-semibold text-zinc-700">
                Label Badge Header
              </label>
              <FTextInput
                v-model="templateModal.form.badge"
                size="sm"
                variant="outline"
                placeholder="Misal: Tes Online"
              />
            </div>
          </div>

          <!-- Isi Pesan (Body) -->
          <div class="space-y-1.5">
            <div class="flex items-center justify-between">
              <label class="block text-xs font-semibold text-zinc-700">
                Isi Pesan Surat (Body) <span class="text-rose-500">*</span>
              </label>
              <span class="text-[11px] text-zinc-400">Mendukung format baris baru</span>
            </div>
            <FTextarea
              v-model="templateModal.form.body"
              size="sm"
              variant="outline"
              :rows="8"
              placeholder="Isi surat resmi..."
              required
            />
          </div>

          <!-- Tombol Aksi & Welcome Link (CTA) -->
          <div class="space-y-3 pt-2 border-t border-zinc-100">
            <div class="flex items-center justify-between">
              <div>
                <label class="block text-xs font-semibold text-zinc-800">Tombol Aksi & Welcome Link (CTA)</label>
                <p class="text-[11px] text-zinc-500">Sematkan tombol dan link tujuan khusus (Welcome Link, link tes, atau link wawancara) pada email pelamar.</p>
              </div>
              <label class="flex items-center gap-1.5 cursor-pointer text-xs text-zinc-700 font-medium select-none shrink-0 ml-3">
                <input
                  type="checkbox"
                  v-model="templateModal.form.has_link"
                  class="rounded border-zinc-300 text-[#0c2340] focus:ring-0 w-4 h-4 cursor-pointer"
                />
                <span>Aktifkan Tombol</span>
              </label>
            </div>

            <div v-if="templateModal.form.has_link" class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-zinc-50/70 p-3.5 rounded-xl border border-zinc-200/80">
              <div class="space-y-1.5">
                <label class="block text-xs font-semibold text-zinc-700">
                  Teks Tombol Aksi (CTA Button)
                </label>
                <FTextInput
                  v-model="templateModal.form.action_label"
                  size="sm"
                  variant="outline"
                  placeholder="Contoh: Buka Panduan Onboarding / Mulai Tes"
                />
              </div>

              <div class="space-y-1.5">
                <label class="block text-xs font-semibold text-zinc-700">
                  Welcome Link / Tautan URL Tombol
                </label>
                <FTextInput
                  v-model="templateModal.form.action_url"
                  size="sm"
                  variant="outline"
                  placeholder="https://contoh.com/welcome atau link meet/asesmen"
                />
              </div>

              <p class="sm:col-span-2 text-[11px] text-zinc-500">
                Tautan ini akan langsung disematkan pada tombol aksi email. Pelamar dapat langsung mengklik tombol untuk membuka link tersebut.
              </p>
            </div>
          </div>

          <!-- Catatan Instruksi Default (Conditional) -->
          <div
            v-if="templateModal.form.has_note"
            class="space-y-1.5 pt-1"
          >
            <label class="block text-xs font-semibold text-zinc-700">
              Catatan Instruksi Default
            </label>
            <FTextarea
              v-model="templateModal.form.default_note"
              size="sm"
              variant="outline"
              :rows="3"
              placeholder="Petunjuk teknis pengerjaan / kehadiran..."
            />
          </div>

          <!-- Modal Footer -->
          <DialogFooter class="pt-4 border-t border-zinc-100 flex items-center justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              @click="templateModal.open = false"
            >
              Batal
            </Button>
            <FButton
              type="submit"
              variant="solid"
              size="sm"
              :loading="isSavingTemplates"
              class="bg-[#0c2340] hover:bg-[#153459] text-white min-w-[130px] shadow-xs"
            >
              <template #prefix>
                <RotateCw v-if="isSavingTemplates" class="w-3.5 h-3.5 animate-spin" />
              </template>
              <span>{{ isSavingTemplates ? 'Menyimpan...' : 'Simpan Perubahan' }}</span>
            </FButton>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, onDeactivated } from 'vue';
import { useRekrutmenStore } from '../stores/rekrutmen';
import { createPoller } from '../lib/polling';
import { createRequestKey } from '../lib/utils';
import LoadingState from '../components/LoadingState.vue';
import axios from 'axios';
import Swal from 'sweetalert2';
// Frappe UI Components
import FBadge from 'frappe-ui/src/components/Badge/Badge.vue';
import FButton from '../components/frappe/Button.vue';
import FTextInput from '../components/frappe/TextInput.vue';
import FTextarea from 'frappe-ui/src/components/Textarea/Textarea.vue';

// Shadcn UI Components
import { Button } from '../components/ui/button';
import { Badge } from '../components/ui/badge';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '../components/ui/card';
import { Table, TableHeader, TableBody, TableHead, TableRow, TableCell } from '../components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '../components/ui/dialog';
import { Input } from '../components/ui/input';

import { Plus, Pencil, Trash2, ChevronDown, ChevronRight, RotateCw, GripVertical, Settings, GitBranch, Info } from 'lucide-vue-next';

const store = useRekrutmenStore();
const activeTab = ref('divisions');
const divisionCompanyFilter = ref('all');

const divisionModal = ref({
  open: false,
  isEdit: false,
  isSubmitting: false,
  divisionId: null,
  form: {
    name: '',
    company_id: null,
    is_active: true,
  },
});

const pipelineModal = ref({
  open: false,
  isEdit: false,
  isSubmitting: false,
  pipelineId: null,
  form: {
    name: '',
    description: '',
    clone_from_pipeline_id: null,
  },
});

const stageModal = ref({
  open: false,
  isEdit: false,
  isSubmitting: false,
  stageId: null,
  form: {
    name: '',
  },
});

const aiSettings = ref({
  has_api_key: false,
  automatic: false,
  provider: '',
  base_url: '',
  model: '',
  is_database: false,
  has_env: false,
  updated_at: null,
});
const aiSettingsDenied = ref(false);
const aiForm = ref({ automatic: false, base_url: '', model: '', clear_api_key: false });
const aiFormKey = ref('');
const showApiKey = ref(false);
const isTestingAi = ref(false);
const isSavingAi = ref(false);

const mailTemplates = ref({});
const selectedTemplateKey = ref('psikotes');
const isSavingTemplates = ref(false);

const mailSettings = ref({ has_password: false });
const mailForm = ref({
  enabled: false,
  transport: 'smtp',
  host: '',
  port: 587,
  encryption: 'tls',
  username: '',
  password: '',
  timeout: 30,
  from_address: '',
  from_name: '',
  reply_to_address: '',
  reply_to_name: '',
});
const mailTestRecipient = ref('');
const isSavingMail = ref(false);
const isTestingMail = ref(false);

const whatsappSettings = ref({ enabled: true, engine_ready: false });
const whatsappAccessError = ref('');
const whatsappForm = ref({
  enabled: true,
});
const whatsappConnectForm = ref({
  name: '',
  phone_number: '',
});
const whatsappAccounts = ref([]);
const whatsappConnect = ref({
  open: false,
  accountId: null,
  qr: null,
  pairingCode: null,
  status: null,
  engine_error: null,
});
const isSavingWhatsapp = ref(false);
const isConnectingWhatsapp = ref(false);
const isChangingWhatsappAccount = ref(false);
const isTestingWhatsapp = ref(false);
const whatsappTest = ref({
  accountId: null,
  recipient: '',
});
const connectedWhatsappAccounts = computed(() =>
  (whatsappAccounts.value || []).filter((account) => whatsappSettings.value.engine_ready && account.delivery_ready !== false && account.status === 'connected' && account.is_active !== false)
);
let whatsappConnectController = null;
let whatsappConnectGeneration = 0;
let failedWhatsappConnect = null;
let whatsappCreateRequest = null;

const currentMailTemplate = computed(() => {
  return mailTemplates.value[selectedTemplateKey.value] || null;
});

const templateModal = ref({
  open: false,
  key: '',
  form: {
    name: '',
    stage: '',
    subject: '',
    badge: '',
    body: '',
    has_link: false,
    action_label: '',
    action_url: '',
    has_note: false,
    default_note: '',
  },
});

const openEditTemplateModal = (key, tpl) => {
  templateModal.value = {
    open: true,
    key: key,
    form: {
      name: tpl.name || '',
      stage: tpl.stage || '',
      subject: tpl.subject || '',
      badge: tpl.badge || '',
      body: tpl.body || '',
      has_link: !!tpl.has_link,
      action_label: tpl.action_label || '',
      action_url: tpl.action_url || '',
      has_note: !!tpl.has_note,
      default_note: tpl.default_note || '',
    },
  };
};

const saveTemplateModal = async () => {
  if (!templateModal.value.key) return;
  const key = templateModal.value.key;

  mailTemplates.value[key] = {
    ...mailTemplates.value[key],
    ...templateModal.value.form,
  };

  isSavingTemplates.value = true;
  try {
    const res = await axios.post('/rekrutmen/api/settings/mail-templates', {
      templates: mailTemplates.value,
    });
    templateModal.value.open = false;
    Swal.fire({
      title: 'Berhasil!',
      text: res.data.message || 'Template email berhasil diperbarui.',
      icon: 'success',
      confirmButtonColor: '#0c2340',
      timer: 1800,
      showConfirmButton: false,
      customClass: {
        popup: 'rounded-2xl',
      }
    });
  } catch (err) {
    Swal.fire({
      title: 'Gagal Menyimpan',
      text: err.response?.data?.message || 'Terjadi kesalahan saat menyimpan template.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
      customClass: {
        popup: 'rounded-2xl',
        confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold',
      }
    });
  } finally {
    isSavingTemplates.value = false;
  }
};

const fetchMailTemplates = async () => {
  try {
    const res = await axios.get('/rekrutmen/api/settings/mail-templates');
    if (res.data?.templates) {
      mailTemplates.value = res.data.templates;
    }
  } catch (err) {
    console.error('Failed fetching mail templates', err);
  }
};

const saveAllMailTemplates = async () => {
  const result = await Swal.fire({
    title: 'Simpan Template Email?',
    text: 'Perubahan template akan disimpan dan digunakan sebagai format pengiriman email ke pelamar.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Ya, Simpan',
    cancelButtonText: 'Batal',
    confirmButtonColor: '#0c2340',
    cancelButtonColor: '#64748b',
    reverseButtons: true,
    customClass: {
      popup: 'rounded-2xl',
      confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold',
      cancelButton: 'rounded-lg px-4 py-2 text-xs font-semibold',
    }
  });

  if (!result.isConfirmed) return;

  isSavingTemplates.value = true;
  try {
    const res = await axios.post('/rekrutmen/api/settings/mail-templates', {
      templates: mailTemplates.value,
    });
    Swal.fire({
      title: 'Berhasil!',
      text: res.data.message || 'Template email berhasil disimpan.',
      icon: 'success',
      confirmButtonColor: '#0c2340',
      timer: 2000,
      customClass: {
        popup: 'rounded-2xl',
        confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold',
      }
    });
  } catch (err) {
    Swal.fire({
      title: 'Gagal Menyimpan',
      text: err.response?.data?.message || 'Terjadi kesalahan saat menyimpan template.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
      customClass: {
        popup: 'rounded-2xl',
        confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold',
      }
    });
  } finally {
    isSavingTemplates.value = false;
  }
};

const fetchAiSettings = async () => {
  try {
    const res = await axios.get('/rekrutmen/api/settings/ai');
    aiSettingsDenied.value = false;
    if (res.data) {
      aiSettings.value = res.data;
      aiForm.value = { automatic: !!res.data.automatic, base_url: res.data.base_url || '', model: res.data.model || '', clear_api_key: false };
      aiFormKey.value = '';
      showApiKey.value = false;
    }
  } catch (err) {
    aiSettingsDenied.value = err.response?.status === 403;
    if (!aiSettingsDenied.value) console.error('Failed fetching AI settings', err);
  }
};

const saveAiSettings = async () => {
  const result = await Swal.fire({
    title: 'Simpan Pengaturan Analisis CV?',
    text: aiForm.value.clear_api_key ? 'API key akan dihapus. Analisis CV berhenti sampai key baru disimpan.' : 'Pengaturan ini berlaku untuk analisis CV berikutnya. API key yang dikosongkan tetap memakai key tersimpan.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Ya, Simpan',
    cancelButtonText: 'Batal',
    confirmButtonColor: '#0c2340',
    cancelButtonColor: '#64748b',
    reverseButtons: true,
    customClass: {
      popup: 'rounded-2xl',
      confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold',
      cancelButton: 'rounded-lg px-4 py-2 text-xs font-semibold',
    }
  });

  if (!result.isConfirmed) return;

  isSavingAi.value = true;
  try {
    const res = await axios.post('/rekrutmen/api/settings/ai', {
      ...aiForm.value,
      api_key: aiFormKey.value,
    });
    await fetchAiSettings();
    Swal.fire({
      title: 'Berhasil!',
      text: res.data.message || 'Pengaturan analisis CV berhasil disimpan.',
      icon: 'success',
      confirmButtonColor: '#0c2340',
      timer: 2000,
      customClass: {
        popup: 'rounded-2xl',
        confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold',
      }
    });
  } catch (err) {
    Swal.fire({
      title: 'Gagal Menyimpan',
      text: err.response?.data?.message || 'Terjadi kesalahan saat menyimpan pengaturan analisis CV.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
      customClass: {
        popup: 'rounded-2xl',
        confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold',
      }
    });
  } finally {
    isSavingAi.value = false;
  }
};

const testAiConnection = async () => {
  isTestingAi.value = true;
  try {
    const res = await axios.post('/rekrutmen/api/settings/ai/test', {
      base_url: aiForm.value.base_url,
      model: aiForm.value.model,
      api_key: aiFormKey.value,
    });
    if (res.data.success) {
      Swal.fire({
        title: 'Koneksi Berhasil!',
        text: res.data.message,
        icon: 'success',
        confirmButtonColor: '#0c2340',
        customClass: {
          popup: 'rounded-2xl',
          confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold',
        }
      });
    }
  } catch (err) {
    Swal.fire({
      title: 'Koneksi Gagal',
      text: err.response?.data?.message || 'Gagal menghubungi OpenAI Compatible API.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
      customClass: {
        popup: 'rounded-2xl',
        confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold',
      }
    });
  } finally {
    isTestingAi.value = false;
  }
};

const applyMailSettings = (data) => {
  mailSettings.value = data || { has_password: false };
  mailForm.value = {
    enabled: !!data?.enabled,
    transport: data?.transport || 'smtp',
    host: data?.host || '',
    port: data?.port || 587,
    encryption: data?.encryption || 'tls',
    username: data?.username || '',
    password: '',
    timeout: data?.timeout || 30,
    from_address: data?.from_address || '',
    from_name: data?.from_name || '',
    reply_to_address: data?.reply_to_address || '',
    reply_to_name: data?.reply_to_name || '',
  };
};

const fetchMailSettings = async () => {
  try {
    const res = await axios.get('/rekrutmen/api/settings/mail');
    applyMailSettings(res.data);
  } catch (err) {
    console.error('Failed fetching mail settings', err);
  }
};

const saveMailSettings = async () => {
  isSavingMail.value = true;
  try {
    const payload = { ...mailForm.value };
    if (!payload.password) {
      delete payload.password;
    }
    const res = await axios.put('/rekrutmen/api/settings/mail', payload);
    applyMailSettings(res.data.data);
    Swal.fire({
      title: 'Berhasil!',
      text: res.data.message,
      icon: 'success',
      confirmButtonColor: '#0c2340',
      timer: 2200,
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } catch (err) {
    Swal.fire({
      title: 'Gagal Menyimpan',
      text: err.response?.data?.message || Object.values(err.response?.data?.errors || {})?.[0]?.[0] || 'Tidak dapat menyimpan SMTP.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } finally {
    isSavingMail.value = false;
  }
};

const testMailSettings = async () => {
  if (!mailTestRecipient.value) {
    Swal.fire({
      title: 'Email Tes Kosong',
      text: 'Isi alamat email tujuan tes terlebih dahulu.',
      icon: 'warning',
      confirmButtonColor: '#0c2340',
    });
    return;
  }

  isTestingMail.value = true;
  try {
    const payload = { ...mailForm.value, recipient: mailTestRecipient.value };
    if (!payload.password) {
      delete payload.password;
    }
    const res = await axios.post('/rekrutmen/api/settings/mail/test', payload);
    Swal.fire({
      title: 'Email Tes Terkirim',
      text: res.data.message,
      icon: 'success',
      confirmButtonColor: '#0c2340',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } catch (err) {
    Swal.fire({
      title: 'Tes Gagal',
      text: err.response?.data?.message || 'Gagal mengirim email tes.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } finally {
    isTestingMail.value = false;
  }
};

const fetchWhatsappSettings = async () => {
  try {
    const res = await axios.get('/rekrutmen/api/settings/whatsapp');
    whatsappAccessError.value = '';
    whatsappSettings.value = res.data.gateway || { enabled: true, engine_ready: false };
    whatsappForm.value.enabled = !!res.data.gateway?.enabled;
    whatsappAccounts.value = res.data.accounts || [];
    const stillValid = connectedWhatsappAccounts.value.some((account) => account.id === whatsappTest.value.accountId);
    if (!stillValid) {
      const fallback = connectedWhatsappAccounts.value.find((account) => account.is_default) || connectedWhatsappAccounts.value[0];
      whatsappTest.value.accountId = fallback ? fallback.id : null;
      if (fallback?.phone_number && !whatsappTest.value.recipient) {
        whatsappTest.value.recipient = fallback.phone_number;
      }
    }
  } catch (err) {
    if (err.response?.status === 403) {
      whatsappAccessError.value = 'Anda tidak memiliki izin untuk mengelola gateway WhatsApp. Hubungi administrator.';
      closeWhatsappConnect();
    } else {
      whatsappAccessError.value = 'Pengaturan WhatsApp belum dapat dimuat. Muat ulang halaman untuk mencoba lagi.';
    }
  }
};

const saveWhatsappSettings = async () => {
  isSavingWhatsapp.value = true;
  try {
    const res = await axios.put('/rekrutmen/api/settings/whatsapp', {
      enabled: !!whatsappForm.value.enabled,
    });
    whatsappSettings.value = res.data.data;
    Swal.fire({
      title: 'Berhasil!',
      text: res.data.message,
      icon: 'success',
      confirmButtonColor: '#0c2340',
      timer: 1800,
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } catch (err) {
    Swal.fire({
      title: 'Gagal Menyimpan',
      text: err.response?.data?.message || 'Tidak dapat menyimpan pengaturan WhatsApp.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
    });
  } finally {
    isSavingWhatsapp.value = false;
  }
};

const applyWhatsappSession = (data, mode = whatsappConnect.value.mode) => {
  if (!data) return;
  whatsappConnect.value = {
    open: true,
    accountId: data.id,
    qr: data.qr || null,
    pairingCode: data.pairing_code || null,
    status: data.status,
    mode,
    engine_ready: data.engine_ready === true,
    engine_error: data.engine_error || null,
  };
};

const whatsappPoller = createPoller({
  request: async (accountId, signal) => (await axios.get(`/rekrutmen/api/settings/whatsapp/accounts/${accountId}/session`, { signal, timeout: 10000 })).data,
  onData: (data) => {
    if (!whatsappConnect.value.open || data.id !== whatsappConnect.value.accountId) return false;
    applyWhatsappSession(data);
    if (data.engine_ready === false) {
      whatsappConnect.value.engine_error = data.engine_error || 'Engine WhatsApp belum tersedia. Coba hubungkan kembali setelah engine siap.';
      return false;
    }
    if (data.status === 'connected' && data.engine_ready === true) {
      fetchWhatsappSettings();
      Swal.fire({ title: 'WhatsApp Terhubung', text: data.phone_number ? `Nomor ${data.phone_number} siap dipakai.` : 'Nomor WhatsApp berhasil ditautkan.', icon: 'success', confirmButtonColor: '#0c2340' });
      return false;
    }
    if (data.status === 'disconnected') {
      whatsappConnect.value.engine_error = data.engine_error || 'Koneksi terputus. Pilih Scan atau Kode untuk mencoba lagi.';
      fetchWhatsappSettings();
      return false;
    }
  },
  onError: (error) => {
    whatsappConnect.value.engine_error = error.response?.status === 403
      ? 'Izin mengelola WhatsApp tidak tersedia.'
      : (error.response?.data?.message || 'Status koneksi tidak dapat dimuat. Pilih Scan atau Kode untuk mencoba lagi.');
    return false;
  },
  onTimeout: () => {
    whatsappConnect.value.engine_error = 'Waktu penautan habis. Pilih Scan atau Kode untuk mendapatkan sesi terbaru.';
  },
});

const startWhatsappPoll = (accountId) => whatsappPoller.start(accountId);
const stopWhatsappPoll = () => whatsappPoller.stop();

const copyPairingCode = async () => {
  const code = whatsappConnect.value.pairingCode;
  if (!code) return;
  try {
    await navigator.clipboard.writeText(code.replace(/-/g, ''));
    Swal.fire({
      toast: true,
      position: 'top-end',
      icon: 'success',
      title: 'Kode pairing disalin',
      showConfirmButton: false,
      timer: 1600,
    });
  } catch (_) {}
};

const startWhatsappConnect = async (modeOrAccount = 'qr', maybeMode = null) => {
  if (isConnectingWhatsapp.value || isChangingWhatsappAccount.value || whatsappAccessError.value) return;
  let account = typeof modeOrAccount === 'object' && modeOrAccount ? modeOrAccount : null;
  const reconnectingExistingAccount = !!account;
  const mode = account ? (maybeMode || 'qr') : (modeOrAccount || 'qr');
  const fingerprint = JSON.stringify(whatsappConnectForm.value);
  if (!account && failedWhatsappConnect?.fingerprint === fingerprint) {
    account = failedWhatsappConnect.account;
  }
  const phone = reconnectingExistingAccount ? account.phone_number : (whatsappConnectForm.value.phone_number || account?.phone_number);

  if (mode === 'pairing' && !phone) {
    Swal.fire({
      title: 'Isi Nomor HP',
      text: 'Kode pairing butuh nomor WhatsApp yang akan ditautkan, misalnya 0812xxxxxxx.',
      icon: 'warning',
      confirmButtonColor: '#0c2340',
    });
    return;
  }

  closeWhatsappConnect();
  const generation = whatsappConnectGeneration;
  whatsappConnectController = new AbortController();
  isConnectingWhatsapp.value = true;
  try {
    const payload = {
      name: account ? account.name : whatsappConnectForm.value.name,
      phone_number: mode === 'pairing' ? phone : undefined,
      mode,
    };
    if (!account) {
      if (!whatsappCreateRequest || whatsappCreateRequest.fingerprint !== fingerprint) {
        whatsappCreateRequest = { fingerprint, key: createRequestKey() };
      }
      payload.request_key = whatsappCreateRequest.key;
    }
    const requestOptions = { signal: whatsappConnectController.signal, timeout: 45000 };
    const res = account
      ? await axios.post(`/rekrutmen/api/settings/whatsapp/accounts/${account.id}/connect`, payload, requestOptions)
      : await axios.post('/rekrutmen/api/settings/whatsapp/accounts/connect', payload, requestOptions);
    if (generation !== whatsappConnectGeneration) return;

    const data = res.data.data || res.data;
    failedWhatsappConnect = null;
    whatsappCreateRequest = null;
    applyWhatsappSession(data, mode);
    startWhatsappPoll(data.id);
    await fetchWhatsappSettings();
  } catch (err) {
    if (generation !== whatsappConnectGeneration) return;
    if (!account && err.response?.status === 409) {
      whatsappCreateRequest = null;
    }
    const savedAccount = err.response?.data?.data;
    if (!account && savedAccount?.id) {
      failedWhatsappConnect = { account: savedAccount, fingerprint };
      whatsappAccounts.value = [...whatsappAccounts.value.filter((item) => item.id !== savedAccount.id), savedAccount];
    }
    if (err.response?.status === 403) {
      whatsappAccessError.value = 'Anda tidak memiliki izin untuk mengelola gateway WhatsApp.';
    }
    Swal.fire({
      title: 'Gagal Menghubungkan',
      text: err.response?.data?.message || 'Respons koneksi belum diterima. Coba lagi dengan formulir yang sama untuk melanjutkan sesi ini.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
    });
  } finally {
    if (generation === whatsappConnectGeneration) {
      isConnectingWhatsapp.value = false;
      whatsappConnectController = null;
    }
  }
};

const closeWhatsappConnect = () => {
  whatsappConnectGeneration += 1;
  whatsappConnectController?.abort();
  whatsappConnectController = null;
  isConnectingWhatsapp.value = false;
  stopWhatsappPoll();
  whatsappConnect.value.open = false;
};

const makeDefaultWhatsappAccount = async (account) => {
  if (isChangingWhatsappAccount.value) return;
  isChangingWhatsappAccount.value = true;
  try {
    await axios.post(`/rekrutmen/api/settings/whatsapp/accounts/${account.id}/default`);
    await fetchWhatsappSettings();
  } catch (err) {
    Swal.fire({
      title: 'Gagal',
      text: err.response?.data?.message || 'Tidak dapat menjadikan default.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
    });
  } finally {
    isChangingWhatsappAccount.value = false;
  }
};

const openWhatsappTest = (account) => {
  whatsappTest.value.accountId = account.id;
  whatsappTest.value.recipient = account.phone_number || whatsappTest.value.recipient;
  sendWhatsappTest(account);
};

const sendWhatsappTest = async (account = null) => {
  if (isTestingWhatsapp.value) return;
  const accountId = account?.id || whatsappTest.value.accountId;
  if (!accountId) {
    Swal.fire({
      title: 'Pilih Nomor Pengirim',
      text: 'Hubungkan WhatsApp dulu, lalu pilih nomor yang akan dipakai tes.',
      icon: 'warning',
      confirmButtonColor: '#0c2340',
    });
    return;
  }

  let recipient = whatsappTest.value.recipient || account?.phone_number || '';
  if (account) {
    const prompt = await Swal.fire({
      title: 'Tes Kirim WhatsApp',
      text: 'Masukkan nomor tujuan. Pesan uji akan dikirim dari nomor yang terhubung.',
      input: 'text',
      inputValue: recipient,
      inputPlaceholder: '0812xxxxxxx',
      showCancelButton: true,
      confirmButtonText: 'Kirim Tes',
      cancelButtonText: 'Batal',
      confirmButtonColor: '#0c2340',
      cancelButtonColor: '#64748b',
      reverseButtons: true,
    });
    if (!prompt.isConfirmed) return;
    recipient = prompt.value || '';
    whatsappTest.value.recipient = recipient;
  }

  if (!recipient) {
    Swal.fire({
      title: 'Nomor Tujuan Kosong',
      text: 'Isi nomor HP tujuan tes, misalnya nomor Anda sendiri.',
      icon: 'warning',
      confirmButtonColor: '#0c2340',
    });
    return;
  }

  isTestingWhatsapp.value = true;
  try {
    const res = await axios.post(`/rekrutmen/api/settings/whatsapp/accounts/${accountId}/test`, {
      recipient,
    });
    Swal.fire({
      title: 'Pesan Tes Terkirim',
      text: res.data.message || 'Cek WhatsApp tujuan tes.',
      icon: 'success',
      confirmButtonColor: '#0c2340',
    });
  } catch (err) {
    Swal.fire({
      title: 'Tes Gagal',
      text: err.response?.data?.message || 'Gagal mengirim pesan tes WhatsApp.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
    });
  } finally {
    isTestingWhatsapp.value = false;
  }
};

const disconnectWhatsappAccount = async (account) => {
  if (isChangingWhatsappAccount.value) return;
  isChangingWhatsappAccount.value = true;
  closeWhatsappConnect();
  try {
    await axios.post(`/rekrutmen/api/settings/whatsapp/accounts/${account.id}/disconnect`);
    await fetchWhatsappSettings();
  } catch (err) {
    await fetchWhatsappSettings();
    Swal.fire({
      title: 'Gagal',
      text: err.response?.data?.message || 'Tidak dapat memutuskan nomor.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
    });
  } finally {
    isChangingWhatsappAccount.value = false;
  }
};

const deleteWhatsappAccount = async (account) => {
  if (isChangingWhatsappAccount.value) return;
  const result = await Swal.fire({
    title: `Hapus ${account.name}?`,
    text: 'Nomor ini tidak bisa dipakai lagi sebagai pengirim WhatsApp rekrutmen.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Ya, Hapus',
    cancelButtonText: 'Batal',
    confirmButtonColor: '#e11d48',
    cancelButtonColor: '#64748b',
    reverseButtons: true,
  });
  if (!result.isConfirmed) return;

  isChangingWhatsappAccount.value = true;
  closeWhatsappConnect();
  try {
    await axios.delete(`/rekrutmen/api/settings/whatsapp/accounts/${account.id}`);
    if (failedWhatsappConnect?.account.id === account.id) failedWhatsappConnect = null;
    await fetchWhatsappSettings();
  } catch (err) {
    await fetchWhatsappSettings();
    Swal.fire({
      title: 'Gagal Menghapus',
      text: err.response?.data?.message || 'Tidak dapat menghapus nomor.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
    });
  } finally {
    isChangingWhatsappAccount.value = false;
  }
};

const whatsappStatusLabel = (account) => {
  if (account.is_active === false) return 'Nonaktif';
  if (account.engine_ready === false) return 'Engine tidak tersedia';
  if (account.status === 'connected') return 'Terhubung';
  if (account.status === 'qr') return 'Menunggu scan';
  if (account.status === 'pairing') return 'Menunggu kode pairing';
  if (account.status === 'connecting') return 'Menghubungkan';
  if (account.status === 'disconnected') return 'Terputus';
  return 'Belum terhubung';
};

const whatsappStatusClass = (account) => {
  if (account.is_active === false) return 'bg-slate-50 text-slate-500 border-slate-200';
  if (account.status === 'connected') return 'bg-emerald-50 text-emerald-700 border-emerald-200';
  if (account.status === 'qr' || account.status === 'pairing' || account.status === 'connecting') return 'bg-amber-50 text-amber-700 border-amber-200';
  if (account.status === 'disconnected') return 'bg-rose-50 text-rose-700 border-rose-200';
  return 'bg-slate-50 text-slate-500 border-slate-200';
};

onMounted(() => {
  store.fetchConfigurations(true).catch(() => {});
  fetchAiSettings();
  fetchMailTemplates();
  fetchMailSettings();
  fetchWhatsappSettings();
});

onUnmounted(() => {
  closeWhatsappConnect();
});
onDeactivated(() => closeWhatsappConnect());
watch(activeTab, (tab) => {
  if (tab !== 'whatsapp_gateway') closeWhatsappConnect();
});

const divisions = computed(() => store.configurations?.divisions || []);
const pipelines = computed(() => store.pipelines?.length ? store.pipelines : (store.configurations?.pipelines || []));
const selectedPipelineId = ref(1);

watch(
  () => pipelines.value,
  (newPipelines) => {
    if (newPipelines && newPipelines.length > 0) {
      if (!selectedPipelineId.value || !newPipelines.some(p => p.id === selectedPipelineId.value)) {
        selectedPipelineId.value = newPipelines[0].id;
      }
    }
  },
  { immediate: true }
);

const currentPipeline = computed(() => {
  return pipelines.value.find(p => p.id === selectedPipelineId.value) || pipelines.value[0] || null;
});

const stages = computed(() => {
  if (currentPipeline.value && currentPipeline.value.stages) {
    return currentPipeline.value.stages;
  }
  return store.stages || store.configurations?.stages || [];
});

const approvers = computed(() => store.configurations?.approvers || []);

// Drag and drop reordering for stages
const localStages = ref([]);
const draggedStage = ref(null);
const draggedStageIndex = ref(null);
const dragOverStageIndex = ref(null);
const isReorderingStages = ref(false);

const displayedStages = computed(() => {
  return localStages.value.length ? localStages.value : stages.value;
});

watch(
  () => [stages.value, selectedPipelineId.value],
  ([newVal]) => {
    if (!draggedStage.value && !isReorderingStages.value) {
      localStages.value = (newVal || []).map(s => ({ ...s }));
    }
  },
  { immediate: true, deep: true }
);

const handleStageDragStart = (stage, idx, event) => {
  if (stage.is_locked) {
    event.preventDefault();
    return;
  }
  draggedStage.value = stage;
  draggedStageIndex.value = idx;
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(stage.id));
  }
};

const handleStageDragOver = (stage, idx, event) => {
  if (draggedStageIndex.value === null || draggedStageIndex.value === idx) {
    return;
  }
  if (stage.is_locked) {
    return;
  }
  dragOverStageIndex.value = idx;
};

const handleStageDragLeave = (stage, idx, event) => {
  if (dragOverStageIndex.value === idx) {
    dragOverStageIndex.value = null;
  }
};

const handleStageDrop = async (stage, targetIdx, event) => {
  const sourceIdx = draggedStageIndex.value;
  dragOverStageIndex.value = null;

  if (sourceIdx === null || sourceIdx === targetIdx) {
    draggedStage.value = null;
    draggedStageIndex.value = null;
    return;
  }

  // If dropped on locked stage, target is the slot right before the locked stage
  let adjustedTargetIdx = targetIdx;
  if (stage.is_locked) {
    adjustedTargetIdx = Math.max(0, targetIdx - 1);
    if (sourceIdx === adjustedTargetIdx) {
      draggedStage.value = null;
      draggedStageIndex.value = null;
      return;
    }
  }

  const list = [...displayedStages.value];
  const [moved] = list.splice(sourceIdx, 1);
  if (!moved) {
    draggedStage.value = null;
    draggedStageIndex.value = null;
    return;
  }

  list.splice(adjustedTargetIdx, 0, moved);

  list.forEach((item, i) => {
    item.order_column = i + 1;
  });

  localStages.value = list;
  draggedStage.value = null;
  draggedStageIndex.value = null;

  isReorderingStages.value = true;
  try {
    const stageIds = list.map(s => s.id);
    const res = await store.reorderStages(stageIds, selectedPipelineId.value);

    Swal.fire({
      title: 'Berhasil!',
      text: res?.message || 'Urutan tahapan pipeline berhasil diperbarui.',
      icon: 'success',
      confirmButtonColor: '#0c2340',
      timer: 2000,
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } catch (err) {
    localStages.value = (stages.value || []).map(s => ({ ...s }));
    Swal.fire({
      title: 'Gagal Memindahkan Tahapan',
      text: err.response?.data?.message || 'Terjadi kesalahan saat memindahkan tahapan pipeline.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } finally {
    isReorderingStages.value = false;
  }
};

const handleStageDragEnd = () => {
  draggedStage.value = null;
  draggedStageIndex.value = null;
  dragOverStageIndex.value = null;
};

// PIPELINE MANAGEMENT METHODS
const openCreatePipelineModal = () => {
  pipelineModal.value = {
    open: true,
    isEdit: false,
    isSubmitting: false,
    pipelineId: null,
    form: {
      name: '',
      description: '',
      clone_from_pipeline_id: selectedPipelineId.value || null,
    },
  };
};

const openEditPipelineModal = (pipeline) => {
  if (!pipeline) return;
  pipelineModal.value = {
    open: true,
    isEdit: true,
    isSubmitting: false,
    pipelineId: pipeline.id,
    form: {
      name: pipeline.name || '',
      description: pipeline.description || '',
      clone_from_pipeline_id: null,
    },
  };
};

const savePipeline = async () => {
  const name = pipelineModal.value.form.name?.trim();
  if (!name) {
    Swal.fire({
      title: 'Nama Pipeline Wajib Diisi',
      text: 'Harap masukkan nama master pipeline.',
      icon: 'warning',
      confirmButtonColor: '#0c2340',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
    return;
  }

  pipelineModal.value.isSubmitting = true;
  try {
    let res;
    if (pipelineModal.value.isEdit) {
      res = await store.updatePipeline(pipelineModal.value.pipelineId, {
        name,
        description: pipelineModal.value.form.description?.trim() || null,
      });
    } else {
      res = await store.createPipeline({
        name,
        description: pipelineModal.value.form.description?.trim() || null,
        clone_from_pipeline_id: pipelineModal.value.form.clone_from_pipeline_id,
      });
      if (res?.pipeline?.id) {
        selectedPipelineId.value = res.pipeline.id;
      }
    }
    pipelineModal.value.open = false;
    Swal.fire({
      title: 'Berhasil!',
      text: res.message || 'Master pipeline berhasil disimpan.',
      icon: 'success',
      confirmButtonColor: '#0c2340',
      timer: 2000,
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } catch (err) {
    Swal.fire({
      title: 'Gagal Menyimpan Pipeline',
      text: err.response?.data?.message || 'Terjadi kesalahan saat menyimpan master pipeline.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } finally {
    pipelineModal.value.isSubmitting = false;
  }
};

const handleDeletePipeline = async (pipeline) => {
  if (!pipeline || pipeline.id === 1) {
    Swal.fire({
      title: 'Tidak Dapat Dihapus',
      text: 'Pipeline standar utama tidak dapat dihapus.',
      icon: 'info',
      confirmButtonColor: '#0c2340',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
    return;
  }

  const result = await Swal.fire({
    title: 'Hapus Master Pipeline?',
    text: `Apakah Anda yakin ingin menghapus pipeline "${pipeline.name}" beserta seluruh tahapan yang ada di dalamnya?`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e11d48',
    cancelButtonColor: '#64748b',
    confirmButtonText: 'Ya, Hapus Pipeline',
    cancelButtonText: 'Batal',
    customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold', cancelButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
  });

  if (!result.isConfirmed) return;

  try {
    const res = await store.deletePipeline(pipeline.id);
    selectedPipelineId.value = 1;
    Swal.fire({
      title: 'Berhasil Dihapus!',
      text: res?.message || 'Master pipeline telah dihapus.',
      icon: 'success',
      confirmButtonColor: '#0c2340',
      timer: 2000,
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } catch (err) {
    Swal.fire({
      title: 'Gagal Menghapus Pipeline',
      text: err.response?.data?.message || 'Terjadi kesalahan saat menghapus master pipeline.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  }
};

const divisionCompanies = computed(() => {
  if (store.configurations?.companies?.length) {
    return store.configurations.companies;
  }
  const seen = new Map();

  for (const division of divisions.value) {
    if (!division?.company_id) {
      continue;
    }

    const name = division.company_name || division.badan_usaha || division.company?.name;
    if (!name || seen.has(division.company_id)) {
      continue;
    }

    seen.set(division.company_id, { id: division.company_id, name });
  }

  return Array.from(seen.values()).sort((a, b) => a.name.localeCompare(b.name, 'id', { sensitivity: 'base' }));
});

const filteredDivisions = computed(() => {
  if (divisionCompanyFilter.value === 'all') {
    return divisions.value;
  }

  return divisions.value.filter((division) => String(division.company_id) === String(divisionCompanyFilter.value));
});

const openDivisionModal = (div = null) => {
  if (div) {
    divisionModal.value = {
      open: true,
      isEdit: true,
      isSubmitting: false,
      divisionId: div.id,
      form: {
        name: div.name || '',
        company_id: div.company_id || null,
        is_active: div.is_active !== false,
      },
    };
  } else {
    const defaultCompanyId = divisionCompanyFilter.value !== 'all' ? Number(divisionCompanyFilter.value) : (divisionCompanies.value[0]?.id || null);
    divisionModal.value = {
      open: true,
      isEdit: false,
      isSubmitting: false,
      divisionId: null,
      form: {
        name: '',
        company_id: defaultCompanyId,
        is_active: true,
      },
    };
  }
};

const saveDivision = async () => {
  if (!divisionModal.value.form.name || !divisionModal.value.form.company_id) {
    Swal.fire({
      title: 'Data Belum Lengkap',
      text: 'Harap pilih Badan Usaha dan masukkan Nama Divisi.',
      icon: 'warning',
      confirmButtonColor: '#0c2340',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
    return;
  }

  divisionModal.value.isSubmitting = true;
  try {
    let res;
    if (divisionModal.value.isEdit) {
      res = await store.updateDivision(divisionModal.value.divisionId, divisionModal.value.form);
    } else {
      res = await store.createDivision(divisionModal.value.form);
    }
    divisionModal.value.open = false;
    Swal.fire({
      title: 'Berhasil!',
      text: res.message || 'Data divisi berhasil disimpan.',
      icon: 'success',
      confirmButtonColor: '#0c2340',
      timer: 2000,
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } catch (err) {
    Swal.fire({
      title: 'Gagal Menyimpan',
      text: err.response?.data?.message || 'Terjadi kesalahan saat menyimpan divisi.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } finally {
    divisionModal.value.isSubmitting = false;
  }
};

const confirmDeleteDivision = async (div) => {
  const companyName = div.company_name || div.badan_usaha || div.company?.name || 'Badan Usaha';
  const result = await Swal.fire({
    title: 'Hapus Divisi Ini?',
    html: `Apakah Anda yakin ingin menghapus divisi <strong>${div.name}</strong> (${companyName})?`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Ya, Hapus',
    cancelButtonText: 'Batal',
    confirmButtonColor: '#e11d48',
    cancelButtonColor: '#64748b',
    reverseButtons: true,
    customClass: {
      popup: 'rounded-2xl',
      confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold',
      cancelButton: 'rounded-lg px-4 py-2 text-xs font-semibold',
    }
  });

  if (!result.isConfirmed) return;

  try {
    const res = await store.deleteDivision(div.id);
    Swal.fire({
      title: 'Berhasil Dihapus!',
      text: res.message || `Divisi ${div.name} berhasil dihapus.`,
      icon: 'success',
      confirmButtonColor: '#0c2340',
      timer: 2000,
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } catch (err) {
    Swal.fire({
      title: 'Gagal Menghapus',
      text: err.response?.data?.message || 'Terjadi kesalahan saat menghapus divisi.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  }
};

const openCreateStageModal = () => {
  stageModal.value = {
    open: true,
    isEdit: false,
    isSubmitting: false,
    stageId: null,
    form: {
      name: '',
    },
  };
};

const openEditStageModal = (stage) => {
  if (stage.is_locked) {
    Swal.fire({
      title: 'Tahap Terkunci',
      text: 'Tahapan final Hired tidak dapat diubah namanya.',
      icon: 'info',
      confirmButtonColor: '#0c2340',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
    return;
  }

  stageModal.value = {
    open: true,
    isEdit: true,
    isSubmitting: false,
    stageId: stage.id,
    form: {
      name: stage.name || '',
    },
  };
};

const saveStage = async () => {
  const name = stageModal.value.form.name?.trim();
  if (!name) {
    Swal.fire({
      title: 'Nama Tahap Wajib Diisi',
      text: 'Harap masukkan nama tahapan seleksi.',
      icon: 'warning',
      confirmButtonColor: '#0c2340',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
    return;
  }

  stageModal.value.isSubmitting = true;
  try {
    let res;
    if (stageModal.value.isEdit) {
      res = await store.updateStage(stageModal.value.stageId, { name });
    } else {
      res = await store.createStage({
        name,
        rekrutmen_pipeline_id: selectedPipelineId.value || 1,
      });
    }
    stageModal.value.open = false;
    Swal.fire({
      title: 'Berhasil!',
      text: res.message || 'Tahapan seleksi berhasil disimpan.',
      icon: 'success',
      confirmButtonColor: '#0c2340',
      timer: 2000,
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } catch (err) {
    Swal.fire({
      title: 'Gagal Menyimpan',
      text: err.response?.data?.message || 'Terjadi kesalahan saat menyimpan tahapan.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } finally {
    stageModal.value.isSubmitting = false;
  }
};

const handleDeleteStage = async (stage) => {
  if (stage.is_locked) {
    Swal.fire({
      title: 'Tahap Terkunci',
      text: 'Tahapan final Hired tidak dapat dihapus.',
      icon: 'info',
      confirmButtonColor: '#0c2340',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
    return;
  }

  const result = await Swal.fire({
    title: 'Hapus Tahap Seleksi?',
    html: `Apakah Anda yakin ingin menghapus tahapan <strong>${stage.name}</strong>?<br><span class="text-xs text-zinc-500">Tahapan hanya dapat dihapus jika tidak ada kandidat aktif di dalamnya.</span>`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Ya, Hapus',
    cancelButtonText: 'Batal',
    confirmButtonColor: '#e11d48',
    cancelButtonColor: '#64748b',
    reverseButtons: true,
    customClass: {
      popup: 'rounded-2xl',
      confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold',
      cancelButton: 'rounded-lg px-4 py-2 text-xs font-semibold',
    }
  });

  if (!result.isConfirmed) return;

  try {
    const res = await store.deleteStage(stage.id);
    Swal.fire({
      title: 'Berhasil Dihapus!',
      text: res.message || `Tahapan ${stage.name} berhasil dihapus.`,
      icon: 'success',
      confirmButtonColor: '#0c2340',
      timer: 2000,
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  } catch (err) {
    Swal.fire({
      title: 'Gagal Menghapus',
      text: err.response?.data?.message || 'Terjadi kesalahan saat menghapus tahapan.',
      icon: 'error',
      confirmButtonColor: '#e11d48',
      customClass: { popup: 'rounded-2xl', confirmButton: 'rounded-lg px-4 py-2 text-xs font-semibold' },
    });
  }
};
</script>
