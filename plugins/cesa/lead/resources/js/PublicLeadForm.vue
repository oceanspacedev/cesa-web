<template>
  <div class="min-h-screen bg-[#EFF6FF] px-4 py-8 font-sans antialiased sm:px-6 lg:px-8">
    <div class="mx-auto max-w-2xl">
      <!-- HEADER CARD (Google Form Top Card) -->
      <div class="mb-4 overflow-hidden rounded-xl border border-gray-200/90 bg-white shadow-xs">
        <div class="h-2.5 w-full bg-blue-600"></div>
        <div class="px-6 pt-5 pb-5">
          <h1 class="text-3xl sm:text-[32px] font-normal leading-tight text-gray-900 tracking-tight">
            {{ i18n.title || 'Lead Form' }}
          </h1>
          <p class="mt-2 text-sm leading-relaxed text-gray-600">
            {{ i18n.description || 'Please complete the lead details below.' }}
          </p>
        </div>
        <div class="border-t border-gray-200 px-6 py-3">
          <p class="text-xs text-[#D93025] font-medium">
            {{ i18n.requiredNote || '* Required question' }}
          </p>
        </div>
      </div>

      <!-- GENERAL ERROR ALERT (if any) -->
      <div
        v-if="generalError"
        class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 flex items-start gap-2.5 shadow-xs"
      >
        <svg class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
        </svg>
        <span>{{ generalError }}</span>
      </div>

      <!-- MAIN FORM -->
      <form @submit.prevent="handleSubmit" novalidate>
        <div class="rounded-xl border border-gray-200/80 bg-white p-6 shadow-xs space-y-6">

          <!-- 1. FULL NAME -->
          <div :ref="el => setFieldRef('name', el)">
            <label for="lead-name" class="block text-sm font-medium text-gray-900 mb-1.5">
              {{ i18n.fields?.name || 'Full Name' }} <span class="text-[#D93025]">*</span>
            </label>
            <input
              id="lead-name"
              v-model="form.name"
              type="text"
              required
              maxlength="255"
              :placeholder="i18n.placeholders?.name || 'Enter the lead full name'"
              :class="[
                'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 uppercase transition-all shadow-2xs placeholder:text-gray-400 placeholder:normal-case focus:outline-none focus:ring-1',
                errors.name
                  ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                  : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
              ]"
              @input="clearError('name')"
            />
            <p v-if="errors.name" class="mt-1.5 text-xs text-[#D93025] flex items-center gap-1 font-medium">
              {{ errors.name }}
            </p>
          </div>

          <!-- 2. PHONE NUMBER -->
          <div :ref="el => setFieldRef('phone', el)">
            <label for="lead-phone" class="block text-sm font-medium text-gray-900 mb-1.5">
              {{ i18n.fields?.phone || 'Phone Number' }} <span class="text-[#D93025]">*</span>
            </label>
            <div class="relative">
              <input
                id="lead-phone"
                v-model="form.phone"
                type="tel"
                required
                maxlength="16"
                :placeholder="i18n.placeholders?.phone || 'Example: 08123456789 or 628123456789'"
                :class="[
                  'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 transition-all shadow-2xs placeholder:text-gray-400 focus:outline-none focus:ring-1',
                  config.whatsapp?.enabled ? 'pr-28' : '',
                  errors.phone
                    ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
                ]"
                @blur="normalizePhoneInput"
                @input="clearError('phone')"
              />
              <button
                v-if="config.whatsapp?.enabled"
                type="button"
                :disabled="isCheckingWhatsApp || !form.phone"
                @click="checkWhatsApp"
                class="absolute right-1.5 top-1.5 bottom-1.5 px-3 rounded-md bg-blue-50 text-blue-700 text-xs font-semibold hover:bg-blue-100 disabled:opacity-50 transition-colors flex items-center gap-1.5"
              >
                <svg v-if="isCheckingWhatsApp" class="animate-spin h-3.5 w-3.5 text-blue-600" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>{{ isCheckingWhatsApp ? 'Memeriksa...' : (i18n.whatsapp?.action || 'Cek WhatsApp') }}</span>
              </button>
            </div>
            <!-- WhatsApp validation status feedback -->
            <div v-if="whatsappFeedback" class="mt-1.5 flex items-center gap-1.5 text-xs">
              <span
                :class="[
                  'font-medium inline-flex items-center gap-1',
                  whatsappFeedback.type === 'success' ? 'text-emerald-600' : 'text-amber-600'
                ]"
              >
                <svg v-if="whatsappFeedback.type === 'success'" class="w-4 h-4 text-emerald-600" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                <svg v-else class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
                {{ whatsappFeedback.text }}
              </span>
            </div>
            <p v-if="errors.phone" class="mt-1.5 text-xs text-[#D93025] font-medium">
              {{ errors.phone }}
            </p>
          </div>

          <!-- 3. ADDRESS -->
          <div :ref="el => setFieldRef('address', el)">
            <label for="lead-address" class="block text-sm font-medium text-gray-900 mb-1.5">
              {{ i18n.fields?.address || 'Address' }} <span class="text-[#D93025]">*</span>
            </label>
            <textarea
              id="lead-address"
              v-model="form.address"
              rows="3"
              required
              :placeholder="i18n.placeholders?.address || 'Enter the complete address'"
              :class="[
                'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 transition-all shadow-2xs placeholder:text-gray-400 focus:outline-none focus:ring-1 resize-y min-h-[90px]',
                errors.address
                  ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                  : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
              ]"
              @input="clearError('address')"
            ></textarea>
            <p v-if="errors.address" class="mt-1.5 text-xs text-[#D93025] font-medium">
              {{ errors.address }}
            </p>
          </div>

          <!-- 4. SALES PERSON -->
          <div :ref="el => setFieldRef('sales_person', el)">
            <label for="lead-sales-person" class="block text-sm font-medium text-gray-900 mb-1.5">
              {{ i18n.fields?.sales_person || 'Sales Person' }} <span class="text-[#D93025]">*</span>
            </label>
            <input
              id="lead-sales-person"
              v-model="form.sales_person"
              type="text"
              required
              maxlength="255"
              :placeholder="i18n.placeholders?.sales_person || 'Enter the sales person name'"
              :class="[
                'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 transition-all shadow-2xs placeholder:text-gray-400 focus:outline-none focus:ring-1',
                errors.sales_person
                  ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                  : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
              ]"
              @input="clearError('sales_person')"
            />
            <p v-if="errors.sales_person" class="mt-1.5 text-xs text-[#D93025] font-medium">
              {{ errors.sales_person }}
            </p>
          </div>

          <!-- 5. STORE TEAM POSITION (<= 5 options -> standard clean select) -->
          <div :ref="el => setFieldRef('store_team_position', el)">
            <label for="lead-position" class="block text-sm font-medium text-gray-900 mb-1.5">
              {{ i18n.fields?.store_team_position || 'Store Team Position' }} <span class="text-[#D93025]">*</span>
            </label>
            <div class="relative">
              <select
                id="lead-position"
                v-model="form.store_team_position"
                required
                :class="[
                  'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm transition-all shadow-2xs focus:outline-none focus:ring-1 appearance-none pr-10',
                  !form.store_team_position ? 'text-gray-400' : 'text-gray-900',
                  errors.store_team_position
                    ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600'
                ]"
                @change="clearError('store_team_position')"
              >
                <option value="" disabled>{{ i18n.placeholders?.store_team_position || 'Select an option' }}</option>
                <option
                  v-for="item in config.storeTeamPositions || []"
                  :key="item.value"
                  :value="item.value"
                  class="text-gray-900"
                >
                  {{ item.label }}
                </option>
              </select>
              <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3.5 text-gray-400">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
              </div>
            </div>
            <p v-if="errors.store_team_position" class="mt-1.5 text-xs text-[#D93025] font-medium">
              {{ errors.store_team_position }}
            </p>
          </div>

          <!-- 6. STORE BRANCH (> 5 options -> SearchableSelect!) -->
          <div :ref="el => setFieldRef('store_branch', el)">
            <label for="lead-store-branch" class="block text-sm font-medium text-gray-900 mb-1.5">
              {{ i18n.fields?.store_branch || 'Store Branch' }} <span class="text-[#D93025]">*</span>
            </label>
            <SearchableSelect
              id="lead-store-branch"
              v-model="form.store_branch"
              :options="config.storeBranches || []"
              :placeholder="i18n.placeholders?.store_branch || 'Select a store branch'"
              search-placeholder="Cari cabang toko..."
              :has-error="Boolean(errors.store_branch)"
              @change="clearError('store_branch')"
            />
            <p v-if="errors.store_branch" class="mt-1.5 text-xs text-[#D93025] font-medium">
              {{ errors.store_branch }}
            </p>
          </div>

          <!-- 7. PHONE TRANSACTION RANGE (<= 5 options -> standard select) -->
          <div>
            <label for="lead-price-range" class="block text-sm font-medium text-gray-900 mb-1.5">
              {{ i18n.fields?.phone_transaction_range || 'Phone Transaction Range' }}
            </label>
            <div class="relative">
              <select
                id="lead-price-range"
                v-model="form.phone_transaction_range"
                :class="[
                  'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm transition-all shadow-2xs focus:outline-none focus:ring-1 appearance-none pr-10 border-gray-300 hover:border-gray-400 focus:border-blue-600 focus:ring-blue-600',
                  !form.phone_transaction_range ? 'text-gray-400' : 'text-gray-900'
                ]"
              >
                <option value="">{{ i18n.placeholders?.phone_transaction_range || 'Select a price range' }}</option>
                <option
                  v-for="range in config.phoneTransactionRanges || []"
                  :key="range.value"
                  :value="range.value"
                  class="text-gray-900"
                >
                  {{ range.label }}
                </option>
              </select>
              <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3.5 text-gray-400">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
              </div>
            </div>
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
            class="inline-flex items-center justify-center rounded-lg bg-[#1d4ed8] hover:bg-[#1e40af] px-6 py-2.5 text-sm font-semibold text-white shadow-xs transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:opacity-50 disabled:cursor-not-allowed min-w-[96px]"
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
            <span>{{ isSubmitting ? (i18n.submitting || 'Submitting...') : (i18n.submit || 'Submit') }}</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref, computed } from 'vue';
import SearchableSelect from './components/SearchableSelect.vue';

const props = defineProps({
  config: {
    type: Object,
    default: () => ({})
  }
});

const i18n = computed(() => props.config.i18n || {});

const form = reactive({
  name: '',
  phone: '',
  address: '',
  sales_person: '',
  store_team_position: '',
  store_branch: '',
  phone_transaction_range: '',
  recaptcha_token: ''
});

const errors = reactive({});
const generalError = ref('');
const isSubmitting = ref(false);
const isCheckingWhatsApp = ref(false);
const whatsappFeedback = ref(null);
const fieldRefs = {};

function setFieldRef(key, el) {
  if (el) {
    fieldRefs[key] = el;
  }
}

function clearError(field) {
  if (errors[field]) {
    delete errors[field];
  }
  generalError.value = '';
}

function normalizePhone(val) {
  if (!val) return '';
  let digits = String(val).replace(/\D+/g, '');
  if (!digits) return '';

  if (digits.startsWith('0062')) {
    digits = digits.substring(4);
    digits = digits.startsWith('0') ? '62' + digits.substring(1) : '62' + digits;
  } else if (digits.startsWith('620')) {
    digits = '62' + digits.substring(3);
  } else if (digits.startsWith('00')) {
    digits = '62' + digits.substring(2);
  } else if (digits.startsWith('0')) {
    digits = '62' + digits.substring(1);
  } else if (!digits.startsWith('62')) {
    digits = '62' + digits;
  }
  return digits;
}

function normalizePhoneInput() {
  if (form.phone) {
    form.phone = normalizePhone(form.phone);
  }
}

async function checkWhatsApp() {
  normalizePhoneInput();
  if (!form.phone) {
    errors.phone = i18n.value.validation?.required || 'Nomor telepon wajib diisi.';
    return;
  }

  isCheckingWhatsApp.value = true;
  whatsappFeedback.value = null;
  clearError('phone');

  try {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const res = await fetch(props.config.routes?.checkWhatsapp, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrf || ''
      },
      body: JSON.stringify({ phone: form.phone })
    });

    const data = await res.json();
    if (!res.ok) {
      whatsappFeedback.value = {
        type: 'error',
        text: data.message || i18n.value.whatsapp?.failed || 'Validasi gagal'
      };
      errors.phone = data.message;
      return;
    }

    if (data.status === 'success') {
      whatsappFeedback.value = {
        type: 'success',
        text: data.message || i18n.value.whatsapp?.success || 'Nomor terdaftar di WhatsApp.'
      };
    } else {
      whatsappFeedback.value = {
        type: 'error',
        text: data.message || i18n.value.whatsapp?.not_registered || 'Nomor tidak terdaftar.'
      };
    }
  } catch (err) {
    whatsappFeedback.value = {
      type: 'error',
      text: i18n.value.whatsapp?.failed || 'Validasi WhatsApp gagal.'
    };
  } finally {
    isCheckingWhatsApp.value = false;
  }
}

function validateClientSide() {
  let valid = true;
  Object.keys(errors).forEach(k => delete errors[k]);

  if (!form.name || !form.name.trim()) {
    errors.name = i18n.value.validation?.required || 'Pertanyaan ini wajib diisi.';
    valid = false;
  }

  normalizePhoneInput();
  if (!form.phone || !form.phone.trim()) {
    errors.phone = i18n.value.validation?.required || 'Pertanyaan ini wajib diisi.';
    valid = false;
  } else if (!/^62[0-9]{8,}$/.test(form.phone)) {
    errors.phone = i18n.value.validation?.phone_format || 'Format nomor harus diawali 62 dan minimal 10 digit.';
    valid = false;
  }

  if (!form.address || !form.address.trim()) {
    errors.address = i18n.value.validation?.required || 'Pertanyaan ini wajib diisi.';
    valid = false;
  }

  if (!form.sales_person || !form.sales_person.trim()) {
    errors.sales_person = i18n.value.validation?.required || 'Pertanyaan ini wajib diisi.';
    valid = false;
  }

  if (!form.store_team_position) {
    errors.store_team_position = i18n.value.validation?.required || 'Pertanyaan ini wajib diisi.';
    valid = false;
  }

  if (!form.store_branch) {
    errors.store_branch = i18n.value.validation?.required || 'Pertanyaan ini wajib diisi.';
    valid = false;
  }

  return valid;
}

function scrollToFirstError() {
  const firstErrorKey = Object.keys(errors)[0];
  if (firstErrorKey && fieldRefs[firstErrorKey]) {
    fieldRefs[firstErrorKey].scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

async function handleSubmit() {
  if (isSubmitting.value) return;

  if (!validateClientSide()) {
    scrollToFirstError();
    return;
  }

  isSubmitting.value = true;
  generalError.value = '';

  // Execute reCAPTCHA if enabled
  if (props.config.recaptcha?.enabled && window.grecaptcha && props.config.recaptcha?.siteKey) {
    try {
      const token = await new Promise((resolve, reject) => {
        window.grecaptcha.ready(() => {
          window.grecaptcha.execute(props.config.recaptcha.siteKey, {
            action: props.config.recaptcha.action || 'lead_request'
          }).then(resolve).catch(reject);
        });
      });
      form.recaptcha_token = token;
    } catch (err) {
      console.warn('reCAPTCHA execution error:', err);
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
        name: form.name.toUpperCase(),
        phone: normalizePhone(form.phone)
      })
    });

    const data = await res.json();

    if (!res.ok) {
      if (res.status === 422 && data.errors) {
        Object.entries(data.errors).forEach(([field, msgs]) => {
          errors[field] = Array.isArray(msgs) ? msgs[0] : msgs;
        });
        scrollToFirstError();
      } else {
        generalError.value = data.message || i18n.value.messages?.generic || 'Terjadi kesalahan sistem.';
      }
      return;
    }

    if (data.redirect_url) {
      window.location.href = data.redirect_url;
    }
  } catch (err) {
    generalError.value = i18n.value.messages?.generic || 'Terjadi kesalahan saat menghubungi server.';
  } finally {
    isSubmitting.value = false;
  }
}
</script>
