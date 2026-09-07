<template>
  <div class="min-h-screen bg-[#EFF6FF] px-4 py-8 font-sans antialiased sm:px-6 lg:px-8">
    <div class="mx-auto max-w-2xl">

      <!-- SUCCESS SUMMARY CARD (if submission completed without redirect) -->
      <div v-if="submissionSummary" class="mb-4 overflow-hidden rounded-xl border border-gray-200/90 bg-white shadow-xs">
        <div class="h-2.5 w-full bg-blue-600"></div>
        <div class="p-6">
          <h2 class="mb-2 text-xl font-semibold text-gray-900">
          {{ i18n.notifications?.success_title || 'Permintaan Berhasil Dikirim' }}
        </h2>
        <p class="text-sm text-gray-600">
          {{ i18n.notifications?.success_body || 'Pengajuan Request Man Power berhasil dikirim.' }}
        </p>

        <dl class="mt-4 grid gap-3 text-sm text-gray-600 border-t border-gray-100 pt-4">
          <div class="flex justify-between gap-4">
            <dt class="font-medium text-gray-700">Posisi Dibutuhkan</dt>
            <dd class="text-right font-medium text-gray-900">{{ submissionSummary.posisi_dibutuhkan }}</dd>
          </div>
          <div class="flex justify-between gap-4">
            <dt class="font-medium text-gray-700">Nama Pengaju</dt>
            <dd class="text-right">{{ submissionSummary.nama_pengaju }}</dd>
          </div>
          <div class="flex justify-between gap-4">
            <dt class="font-medium text-gray-700">Status Kebutuhan</dt>
            <dd class="text-right">{{ submissionSummary.status_kebutuhan }}</dd>
          </div>
          <div v-if="submissionSummary.nama_replacement" class="flex justify-between gap-4">
            <dt class="font-medium text-gray-700">Nama Pengganti</dt>
            <dd class="text-right">{{ submissionSummary.nama_replacement }}</dd>
          </div>
        </dl>

        <div class="mt-6 flex items-center justify-between gap-4 border-t border-gray-100 pt-4">
          <a
            :href="submissionSummary.progress_url"
            class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-blue-700 transition-colors"
          >
            Lihat Status Tracking
          </a>
          <button
            type="button"
            @click="resetForm"
            class="text-xs font-medium text-blue-600 hover:text-blue-500 hover:underline cursor-pointer"
          >
            Kirim Pengajuan Lain
          </button>
        </div>
        </div>
      </div>

      <!-- MAIN FORM CONTENT -->
      <template v-else>
        <!-- HEADER CARD (Google Form Top Card) -->
        <div class="mb-4 overflow-hidden rounded-xl border border-gray-200/90 bg-white shadow-xs">
          <div class="h-2.5 w-full bg-blue-600"></div>
          <div class="px-6 pt-5 pb-5">
            <h1 class="text-3xl sm:text-[32px] font-normal leading-tight text-gray-900 tracking-tight">
              {{ i18n.title || 'MANPOWER REQUEST FORM' }}
            </h1>
            <p class="mt-2 text-sm leading-relaxed text-gray-600">
              {{ i18n.description || 'Complete the following form to submit a manpower request.' }}
            </p>
          </div>
          <div class="border-t border-gray-200 px-6 py-3">
            <p class="text-xs text-[#D93025] font-medium">
              {{ i18n.requiredNote || '* Required' }}
            </p>
          </div>
        </div>

        <!-- ERROR SUMMARY ALERT -->
        <div
          v-if="hasErrors"
          ref="errorSummaryRef"
          tabindex="-1"
          class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 shadow-xs"
        >
          <div class="flex items-start gap-2.5">
            <svg class="h-5 w-5 text-red-500 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>
            <div class="flex-1">
              <h3 class="text-sm font-semibold text-red-800">
                {{ i18n.notifications?.validation_title || 'Validasi gagal' }}
              </h3>
              <p class="mt-1 text-xs text-red-700">
                {{ i18n.notifications?.validation_body || 'Silakan periksa kembali data yang diisi.' }}
              </p>
              <ul v-if="errorList.length > 0" class="mt-2 list-disc pl-5 space-y-0.5 text-xs text-red-700">
                <li v-for="(msg, idx) in errorList" :key="idx">{{ msg }}</li>
              </ul>
            </div>
          </div>
        </div>

        <!-- FORM QUESTIONS CARD -->
        <form @submit.prevent="handleSubmit" novalidate>
          <div class="rounded-xl border border-gray-200/80 bg-white p-6 shadow-xs space-y-6">

            <!-- 1. REQUESTER NAME -->
            <div :ref="el => setFieldRef('nama_pengaju', el)">
              <label for="nama-pengaju" class="block text-sm font-medium text-gray-900 mb-1.5">
                {{ i18n.fields?.nama_pengaju || 'Requester Name' }} <span class="text-[#D93025]">*</span>
              </label>
              <input
                id="nama-pengaju"
                v-model="form.nama_pengaju"
                type="text"
                required
                maxlength="255"
                :placeholder="i18n.placeholders?.nama_pengaju || 'Requester full name'"
                :class="[
                  'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 transition-all shadow-2xs placeholder:text-gray-400 focus:outline-none focus:ring-1',
                  errors.nama_pengaju
                    ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
                ]"
                @input="clearError('nama_pengaju')"
              />
              <p v-if="errors.nama_pengaju" class="mt-1.5 text-xs text-[#D93025] font-medium">
                {{ errors.nama_pengaju }}
              </p>
            </div>

            <!-- 2. REQUESTER EMAIL -->
            <div :ref="el => setFieldRef('email_address', el)">
              <label for="email-address" class="block text-sm font-medium text-gray-900 mb-1.5">
                {{ i18n.fields?.email_address || 'Requester Email' }} <span class="text-[#D93025]">*</span>
              </label>
              <input
                id="email-address"
                v-model="form.email_address"
                type="email"
                required
                maxlength="255"
                :placeholder="i18n.placeholders?.email_address || 'email@company.com'"
                :class="[
                  'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 transition-all shadow-2xs placeholder:text-gray-400 focus:outline-none focus:ring-1',
                  errors.email_address
                    ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
                ]"
                @input="clearError('email_address')"
              />
              <p v-if="errors.email_address" class="mt-1.5 text-xs text-[#D93025] font-medium">
                {{ errors.email_address }}
              </p>
            </div>

            <!-- 3. REQUESTER POSITION / TITLE -->
            <div :ref="el => setFieldRef('posisi_pengaju', el)">
              <label for="posisi-pengaju" class="block text-sm font-medium text-gray-900 mb-1.5">
                {{ i18n.fields?.posisi_pengaju || 'Requester Position / Title' }} <span class="text-[#D93025]">*</span>
              </label>
              <input
                id="posisi-pengaju"
                v-model="form.posisi_pengaju"
                type="text"
                required
                maxlength="255"
                :placeholder="i18n.placeholders?.posisi_pengaju || 'Example: HR Manager'"
                :class="[
                  'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 transition-all shadow-2xs placeholder:text-gray-400 focus:outline-none focus:ring-1',
                  errors.posisi_pengaju
                    ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
                ]"
                @input="clearError('posisi_pengaju')"
              />
              <p v-if="errors.posisi_pengaju" class="mt-1.5 text-xs text-[#D93025] font-medium">
                {{ errors.posisi_pengaju }}
              </p>
            </div>

            <!-- 4. BUSINESS ENTITY (COMPANY - Searchable if > 5) -->
            <div :ref="el => setFieldRef('company_id', el)">
              <label for="company-id" class="block text-sm font-medium text-gray-900 mb-1.5">
                {{ i18n.fields?.company_id || 'Business Entity' }} <span class="text-[#D93025]">*</span>
              </label>
              <SearchableSelect
                id="company-id"
                v-model="form.company_id"
                :options="config.companies || []"
                :placeholder="i18n.placeholders?.company_id || 'Select an option'"
                search-placeholder="Cari badan usaha..."
                :has-error="Boolean(errors.company_id)"
                @change="handleCompanyChange"
              />
              <p v-if="errors.company_id" class="mt-1.5 text-xs text-[#D93025] font-medium">
                {{ errors.company_id }}
              </p>
            </div>

            <!-- 5. DIVISION (Filtered instantly by company, Searchable if > 5) -->
            <div :ref="el => setFieldRef('division_id', el)">
              <label for="division-id" class="block text-sm font-medium text-gray-900 mb-1.5">
                {{ i18n.fields?.division_id || 'Division' }} <span class="text-[#D93025]">*</span>
              </label>
              <SearchableSelect
                id="division-id"
                v-model="form.division_id"
                :options="filteredDivisions"
                :placeholder="!form.company_id ? 'Pilih badan usaha terlebih dahulu' : (i18n.placeholders?.division_id || 'Select an option')"
                search-placeholder="Cari divisi..."
                :disabled="!form.company_id"
                :has-error="Boolean(errors.division_id)"
                @change="clearError('division_id')"
              />
              <p v-if="errors.division_id" class="mt-1.5 text-xs text-[#D93025] font-medium">
                {{ errors.division_id }}
              </p>
            </div>

            <!-- 6. REQUIREMENT STATUS (<= 5 options -> standard clean select) -->
            <div :ref="el => setFieldRef('status_kebutuhan', el)">
              <label for="status-kebutuhan" class="block text-sm font-medium text-gray-900 mb-1.5">
                {{ i18n.fields?.status_kebutuhan || 'Requirement Status' }} <span class="text-[#D93025]">*</span>
              </label>
              <div class="relative">
                <select
                  id="status-kebutuhan"
                  v-model="form.status_kebutuhan"
                  required
                  :class="[
                    'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm transition-all shadow-2xs focus:outline-none focus:ring-1 appearance-none pr-10',
                    !form.status_kebutuhan ? 'text-gray-400' : 'text-gray-900',
                    errors.status_kebutuhan
                      ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                      : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
                  ]"
                  @change="handleStatusKebutuhanChange"
                >
                  <option value="" disabled>{{ i18n.placeholders?.status_kebutuhan || 'Select an option' }}</option>
                  <option
                    v-for="opt in config.statusKebutuhanOptions || []"
                    :key="opt.value"
                    :value="opt.value"
                    class="text-gray-900"
                  >
                    {{ opt.label }}
                  </option>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3.5 text-gray-400">
                  <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                </div>
              </div>
              <p v-if="errors.status_kebutuhan" class="mt-1.5 text-xs text-[#D93025] font-medium">
                {{ errors.status_kebutuhan }}
              </p>
            </div>

            <!-- 7. REPLACEMENT EMPLOYEE NAME (Conditional) -->
            <div
              v-if="isReplacement"
              :ref="el => setFieldRef('nama_karyawan_replacement', el)"
              class="transition-all duration-200"
            >
              <label for="nama-karyawan-replacement" class="block text-sm font-medium text-gray-900 mb-1.5">
                {{ i18n.fields?.nama_karyawan_replacement || 'Replacement Employee Name' }} <span class="text-[#D93025]">*</span>
              </label>
              <input
                id="nama-karyawan-replacement"
                v-model="form.nama_karyawan_replacement"
                type="text"
                required
                maxlength="255"
                :placeholder="i18n.placeholders?.nama_karyawan_replacement || 'Example: Budi Santoso'"
                :class="[
                  'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 transition-all shadow-2xs placeholder:text-gray-400 focus:outline-none focus:ring-1',
                  errors.nama_karyawan_replacement
                    ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
                ]"
                @input="clearError('nama_karyawan_replacement')"
              />
              <p class="mt-1 text-xs text-gray-500">
                {{ i18n.helperTexts?.nama_karyawan_replacement || 'Untuk kebutuhan replacement, isi nama karyawan yang akan digantikan.' }}
              </p>
              <p v-if="errors.nama_karyawan_replacement" class="mt-1.5 text-xs text-[#D93025] font-medium">
                {{ errors.nama_karyawan_replacement }}
              </p>
            </div>

            <!-- 8. REQUESTED POSITION -->
            <div :ref="el => setFieldRef('posisi_dibutuhkan', el)">
              <label for="posisi-dibutuhkan" class="block text-sm font-medium text-gray-900 mb-1.5">
                {{ i18n.fields?.posisi_dibutuhkan || 'Requested Position' }} <span class="text-[#D93025]">*</span>
              </label>
              <input
                id="posisi-dibutuhkan"
                v-model="form.posisi_dibutuhkan"
                type="text"
                required
                maxlength="255"
                :placeholder="i18n.placeholders?.posisi_dibutuhkan || 'Example: Accounting Staff'"
                :class="[
                  'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 transition-all shadow-2xs placeholder:text-gray-400 focus:outline-none focus:ring-1',
                  errors.posisi_dibutuhkan
                    ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
                ]"
                @input="clearError('posisi_dibutuhkan')"
              />
              <p v-if="errors.posisi_dibutuhkan" class="mt-1.5 text-xs text-[#D93025] font-medium">
                {{ errors.posisi_dibutuhkan }}
              </p>
            </div>

            <!-- 9. JOB LEVEL (<= 5 options -> standard clean select) -->
            <div :ref="el => setFieldRef('level_pekerjaan', el)">
              <label for="level-pekerjaan" class="block text-sm font-medium text-gray-900 mb-1.5">
                {{ i18n.fields?.level_pekerjaan || 'Job Level' }} <span class="text-[#D93025]">*</span>
              </label>
              <div class="relative">
                <select
                  id="level-pekerjaan"
                  v-model="form.level_pekerjaan"
                  required
                  :class="[
                    'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm transition-all shadow-2xs focus:outline-none focus:ring-1 appearance-none pr-10',
                    !form.level_pekerjaan ? 'text-gray-400' : 'text-gray-900',
                    errors.level_pekerjaan
                      ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                      : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
                  ]"
                  @change="clearError('level_pekerjaan')"
                >
                  <option value="" disabled>{{ i18n.placeholders?.level_pekerjaan || 'Select an option' }}</option>
                  <option
                    v-for="opt in config.levelPekerjaanOptions || []"
                    :key="opt.value"
                    :value="opt.value"
                    class="text-gray-900"
                  >
                    {{ opt.label }}
                  </option>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3.5 text-gray-400">
                  <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                </div>
              </div>
              <p v-if="errors.level_pekerjaan" class="mt-1.5 text-xs text-[#D93025] font-medium">
                {{ errors.level_pekerjaan }}
              </p>
            </div>

            <!-- 10. NUMBER OF EMPLOYEES NEEDED -->
            <div :ref="el => setFieldRef('jumlah_karyawan_dibutuhkan', el)">
              <label for="jumlah-karyawan" class="block text-sm font-medium text-gray-900 mb-1.5">
                {{ i18n.fields?.jumlah_karyawan_dibutuhkan || 'Number of Employees Needed' }} <span class="text-[#D93025]">*</span>
              </label>
              <input
                id="jumlah-karyawan"
                v-model.number="form.jumlah_karyawan_dibutuhkan"
                type="number"
                min="1"
                required
                :class="[
                  'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 transition-all shadow-2xs focus:outline-none focus:ring-1',
                  errors.jumlah_karyawan_dibutuhkan
                    ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
                ]"
                @input="clearError('jumlah_karyawan_dibutuhkan')"
              />
              <p v-if="errors.jumlah_karyawan_dibutuhkan" class="mt-1.5 text-xs text-[#D93025] font-medium">
                {{ errors.jumlah_karyawan_dibutuhkan }}
              </p>
            </div>

            <!-- 11. PLACEMENT LOCATION -->
            <div :ref="el => setFieldRef('lokasi_penempatan', el)">
              <label for="lokasi-penempatan" class="block text-sm font-medium text-gray-900 mb-1.5">
                {{ i18n.fields?.lokasi_penempatan || 'Placement Location' }} <span class="text-[#D93025]">*</span>
              </label>
              <input
                id="lokasi-penempatan"
                v-model="form.lokasi_penempatan"
                type="text"
                required
                maxlength="255"
                :placeholder="i18n.placeholders?.lokasi_penempatan || 'Example: Central Jakarta'"
                :class="[
                  'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 transition-all shadow-2xs placeholder:text-gray-400 focus:outline-none focus:ring-1',
                  errors.lokasi_penempatan
                    ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
                ]"
                @input="clearError('lokasi_penempatan')"
              />
              <p v-if="errors.lokasi_penempatan" class="mt-1.5 text-xs text-[#D93025] font-medium">
                {{ errors.lokasi_penempatan }}
              </p>
            </div>

            <!-- 12. ESTIMATED JOIN DATE -->
            <div :ref="el => setFieldRef('estimasi_tanggal_join', el)">
              <label for="estimasi-join" class="block text-sm font-medium text-gray-900 mb-1.5">
                {{ i18n.fields?.estimasi_tanggal_join || 'Estimated Join Date' }} <span class="text-[#D93025]">*</span>
              </label>
              <input
                id="estimasi-join"
                v-model="form.estimasi_tanggal_join"
                type="date"
                required
                :class="[
                  'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 transition-all shadow-2xs focus:outline-none focus:ring-1',
                  errors.estimasi_tanggal_join
                    ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
                ]"
                @input="clearError('estimasi_tanggal_join')"
              />
              <p v-if="errors.estimasi_tanggal_join" class="mt-1.5 text-xs text-[#D93025] font-medium">
                {{ errors.estimasi_tanggal_join }}
              </p>
            </div>

            <!-- 13. JOB DESCRIPTION -->
            <div :ref="el => setFieldRef('job_description', el)">
              <label for="job-description" class="block text-sm font-medium text-gray-900 mb-1.5">
                {{ i18n.fields?.job_description || 'Job Description' }} <span class="text-[#D93025]">*</span>
              </label>
              <textarea
                id="job-description"
                v-model="form.job_description"
                rows="5"
                required
                :placeholder="i18n.placeholders?.job_description || 'Job duties and responsibilities for the requested position...'"
                :class="[
                  'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 transition-all shadow-2xs placeholder:text-gray-400 focus:outline-none focus:ring-1 resize-y min-h-[110px]',
                  errors.job_description
                    ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
                ]"
                @input="clearError('job_description')"
              ></textarea>
              <p v-if="errors.job_description" class="mt-1.5 text-xs text-[#D93025] font-medium">
                {{ errors.job_description }}
              </p>
            </div>

            <!-- 14. REQUIRED QUALIFICATIONS -->
            <div :ref="el => setFieldRef('requirements_kualifikasi', el)">
              <label for="requirements-kualifikasi" class="block text-sm font-medium text-gray-900 mb-1.5">
                {{ i18n.fields?.requirements_kualifikasi || 'Required Qualifications' }} <span class="text-[#D93025]">*</span>
              </label>
              <textarea
                id="requirements-kualifikasi"
                v-model="form.requirements_kualifikasi"
                rows="5"
                required
                :placeholder="i18n.placeholders?.requirements_kualifikasi || 'Required education, experience, and skills...'"
                :class="[
                  'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 transition-all shadow-2xs placeholder:text-gray-400 focus:outline-none focus:ring-1 resize-y min-h-[110px]',
                  errors.requirements_kualifikasi
                    ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
                ]"
                @input="clearError('requirements_kualifikasi')"
              ></textarea>
              <p v-if="errors.requirements_kualifikasi" class="mt-1.5 text-xs text-[#D93025] font-medium">
                {{ errors.requirements_kualifikasi }}
              </p>
            </div>

            <!-- 15. ADDITIONAL NOTES -->
            <div>
              <label for="keterangan" class="block text-sm font-medium text-gray-900 mb-1.5">
                {{ i18n.fields?.keterangan || 'Additional Notes' }}
              </label>
              <textarea
                id="keterangan"
                v-model="form.keterangan"
                rows="3"
                :placeholder="i18n.placeholders?.keterangan || 'Additional relevant information (optional)'"
                class="w-full rounded-lg border border-gray-300 hover:border-gray-400 bg-white px-3.5 py-2.5 text-sm text-gray-900 transition-all shadow-2xs placeholder:text-gray-400 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600 resize-y"
              ></textarea>
            </div>

          </div>

          <!-- FOOTER BAR -->
          <div class="mt-4 flex items-center justify-between gap-3 px-1">
            <p class="text-xs text-gray-600 font-medium">
              {{ i18n.pageInfo || 'Page 1 of 1' }}
            </p>

            <button
              type="submit"
              :disabled="isSubmitting"
              class="inline-flex items-center justify-center rounded-lg bg-[#1d4ed8] hover:bg-[#1e40af] px-6 py-2.5 text-sm font-semibold text-white shadow-xs transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:opacity-50 disabled:cursor-not-allowed min-w-[124px]"
            >
              <svg
                v-if="isSubmitting"
                class="animate-spin -ml-1 mr-2 h-4 w-4 text-white"
                fill="none"
                viewBox="0 0 24 24"
              >
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <span>{{ isSubmitting ? (i18n.submitting || 'Submitting...') : (i18n.submit || 'Submit Request') }}</span>
            </button>
          </div>
        </form>
      </template>

    </div>
  </div>
</template>

<script setup>
import { reactive, ref, computed, nextTick } from 'vue';
import SearchableSelect from './SearchableSelect.vue';

const props = defineProps({
  config: {
    type: Object,
    default: () => ({})
  }
});

const i18n = computed(() => props.config.i18n || {});

const form = reactive({
  nama_pengaju: '',
  email_address: '',
  posisi_pengaju: '',
  company_id: '',
  division_id: '',
  status_kebutuhan: 'New Hiring',
  nama_karyawan_replacement: '',
  posisi_dibutuhkan: '',
  level_pekerjaan: '',
  jumlah_karyawan_dibutuhkan: 1,
  lokasi_penempatan: '',
  estimasi_tanggal_join: '',
  job_description: '',
  requirements_kualifikasi: '',
  keterangan: '',
  recaptcha_token: ''
});

const errors = reactive({});
const errorSummaryRef = ref(null);
const isSubmitting = ref(false);
const submissionSummary = ref(null);
const fieldRefs = {};

function setFieldRef(key, el) {
  if (el) {
    fieldRefs[key] = el;
  }
}

const isReplacement = computed(() => {
  return String(form.status_kebutuhan).toLowerCase() === 'replacement';
});

const filteredDivisions = computed(() => {
  if (!form.company_id) return [];
  const cid = Number(form.company_id);
  return (props.config.divisions || []).filter(d => Number(d.company_id) === cid);
});

const hasErrors = computed(() => Object.keys(errors).length > 0);
const errorList = computed(() => Object.values(errors));

function clearError(field) {
  if (errors[field]) {
    delete errors[field];
  }
}

function handleCompanyChange() {
  clearError('company_id');
  const valid = filteredDivisions.value.some(d => d.id === form.division_id);
  if (!valid) {
    form.division_id = '';
  }
}

function handleStatusKebutuhanChange() {
  clearError('status_kebutuhan');
  if (!isReplacement.value) {
    form.nama_karyawan_replacement = '';
    clearError('nama_karyawan_replacement');
  }
}

function validateClientSide() {
  let valid = true;
  Object.keys(errors).forEach(k => delete errors[k]);

  if (!form.nama_pengaju || !form.nama_pengaju.trim()) {
    errors.nama_pengaju = 'Nama pengaju wajib diisi.';
    valid = false;
  }

  if (!form.email_address || !form.email_address.trim()) {
    errors.email_address = 'Email pengaju wajib diisi.';
    valid = false;
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email_address)) {
    errors.email_address = 'Format email tidak valid.';
    valid = false;
  }

  if (!form.posisi_pengaju || !form.posisi_pengaju.trim()) {
    errors.posisi_pengaju = 'Posisi pengaju wajib diisi.';
    valid = false;
  }

  if (!form.company_id) {
    errors.company_id = 'Badan usaha wajib dipilih.';
    valid = false;
  }

  if (!form.division_id) {
    errors.division_id = 'Divisi wajib dipilih.';
    valid = false;
  }

  if (!form.status_kebutuhan) {
    errors.status_kebutuhan = 'Status kebutuhan wajib dipilih.';
    valid = false;
  }

  if (isReplacement.value && (!form.nama_karyawan_replacement || !form.nama_karyawan_replacement.trim())) {
    errors.nama_karyawan_replacement = i18n.value.errors?.replacement_required || 'Nama karyawan yang digantikan wajib diisi.';
    valid = false;
  }

  if (!form.posisi_dibutuhkan || !form.posisi_dibutuhkan.trim()) {
    errors.posisi_dibutuhkan = 'Posisi yang dibutuhkan wajib diisi.';
    valid = false;
  }

  if (!form.level_pekerjaan) {
    errors.level_pekerjaan = 'Level pekerjaan wajib dipilih.';
    valid = false;
  }

  if (!form.jumlah_karyawan_dibutuhkan || Number(form.jumlah_karyawan_dibutuhkan) < 1) {
    errors.jumlah_karyawan_dibutuhkan = 'Jumlah karyawan minimal 1 orang.';
    valid = false;
  }

  if (!form.lokasi_penempatan || !form.lokasi_penempatan.trim()) {
    errors.lokasi_penempatan = 'Lokasi penempatan wajib diisi.';
    valid = false;
  }

  if (!form.estimasi_tanggal_join) {
    errors.estimasi_tanggal_join = 'Estimasi tanggal join wajib diisi.';
    valid = false;
  }

  if (!form.job_description || !form.job_description.trim()) {
    errors.job_description = 'Deskripsi pekerjaan wajib diisi.';
    valid = false;
  }

  if (!form.requirements_kualifikasi || !form.requirements_kualifikasi.trim()) {
    errors.requirements_kualifikasi = 'Kualifikasi yang dibutuhkan wajib diisi.';
    valid = false;
  }

  return valid;
}

function scrollToError() {
  nextTick(() => {
    if (errorSummaryRef.value) {
      errorSummaryRef.value.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      const firstKey = Object.keys(errors)[0];
      if (firstKey && fieldRefs[firstKey]) {
        fieldRefs[firstKey].scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }
  });
}

function resetForm() {
  submissionSummary.value = null;
  form.nama_pengaju = '';
  form.email_address = '';
  form.posisi_pengaju = '';
  form.company_id = '';
  form.division_id = '';
  form.status_kebutuhan = 'New Hiring';
  form.nama_karyawan_replacement = '';
  form.posisi_dibutuhkan = '';
  form.level_pekerjaan = '';
  form.jumlah_karyawan_dibutuhkan = 1;
  form.lokasi_penempatan = '';
  form.estimasi_tanggal_join = '';
  form.job_description = '';
  form.requirements_kualifikasi = '';
  form.keterangan = '';
  form.recaptcha_token = '';
  Object.keys(errors).forEach(k => delete errors[k]);
}

async function handleSubmit() {
  if (isSubmitting.value) return;

  if (!validateClientSide()) {
    scrollToError();
    return;
  }

  isSubmitting.value = true;

  if (props.config.recaptcha?.enabled && window.grecaptcha && props.config.recaptcha?.siteKey) {
    try {
      const token = await new Promise((resolve, reject) => {
        window.grecaptcha.ready(() => {
          window.grecaptcha.execute(props.config.recaptcha.siteKey, {
            action: props.config.recaptcha.action || 'request_man_power'
          }).then(resolve).catch(reject);
        });
      });
      form.recaptcha_token = token;
    } catch (err) {
      console.warn('reCAPTCHA error:', err);
    }
  }

  try {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const res = await fetch(props.config.routes?.submit, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrf || ''
      },
      body: JSON.stringify({
        ...form,
        company_id: Number(form.company_id),
        division_id: Number(form.division_id),
        jumlah_karyawan_dibutuhkan: Number(form.jumlah_karyawan_dibutuhkan)
      })
    });

    const data = await res.json();

    if (!res.ok) {
      if (res.status === 422 && data.errors) {
        Object.entries(data.errors).forEach(([k, msgs]) => {
          errors[k] = Array.isArray(msgs) ? msgs[0] : msgs;
        });
        scrollToError();
      } else {
        errors.general = data.message || i18n.value.errors?.system || 'Terjadi kesalahan pada sistem.';
        scrollToError();
      }
      return;
    }

    if (data.redirect_url) {
      window.location.href = data.redirect_url;
    } else if (data.recent_submission) {
      submissionSummary.value = data.recent_submission;
    }
  } catch (err) {
    errors.general = i18n.value.errors?.system || 'Terjadi kesalahan saat menghubungi server.';
    scrollToError();
  } finally {
    isSubmitting.value = false;
  }
}
</script>
