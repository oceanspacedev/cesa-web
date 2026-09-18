<template>
  <div ref="containerRef" class="relative w-full">
    <!-- Trigger Button -->
    <button
      :id="id"
      type="button"
      :disabled="disabled"
      @click="toggleDropdown"
      :class="[
        'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-left flex items-center justify-between gap-2 transition-all shadow-2xs focus:outline-none',
        disabled ? 'bg-gray-50 text-gray-400 cursor-not-allowed border-gray-200' : 'cursor-pointer hover:border-gray-400',
        isOpen ? 'border-blue-600 ring-1 ring-blue-600' : (hasError ? 'border-red-500 focus:border-red-500 focus:ring-1 focus:ring-red-500' : 'border-gray-300')
      ]"
      :aria-expanded="isOpen"
      aria-haspopup="listbox"
    >
      <span :class="selectedLabel ? 'text-gray-900 truncate' : 'text-gray-400 truncate'">
        {{ selectedLabel || placeholder }}
      </span>

      <svg
        :class="[
          'h-4 w-4 shrink-0 text-gray-400 transition-transform duration-200',
          isOpen ? 'rotate-180 text-blue-600' : ''
        ]"
        fill="none"
        stroke="currentColor"
        viewBox="0 0 24 24"
      >
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
      </svg>
    </button>

    <!-- Dropdown Menu -->
    <div
      v-if="isOpen"
      class="absolute z-50 mt-1.5 w-full rounded-xl border border-gray-200/90 bg-white shadow-lg overflow-hidden animate-in fade-in-50 zoom-in-95 duration-100"
      style="max-height: 280px;"
    >
      <!-- Search Input (Only shown if options count > 5) -->
      <div v-if="shouldShowSearch" class="p-2 border-b border-gray-100 bg-gray-50/80 sticky top-0 z-10">
        <div class="relative flex items-center">
          <svg class="absolute left-2.5 h-3.5 w-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <input
            ref="searchInputRef"
            v-model="searchQuery"
            type="text"
            :placeholder="searchPlaceholder"
            class="w-full rounded-lg border border-gray-200 bg-white pl-8 pr-7 py-1.5 text-xs text-gray-900 placeholder:text-gray-400 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600"
            @keydown.esc="closeDropdown"
            @keydown.enter.prevent="selectFirstMatching"
          />
          <button
            v-if="searchQuery"
            type="button"
            @click="searchQuery = ''"
            class="absolute right-2 text-gray-400 hover:text-gray-600 text-xs p-0.5"
          >
            ✕
          </button>
        </div>
      </div>

      <!-- Options List -->
      <ul
        role="listbox"
        class="max-h-52 overflow-y-auto p-1 space-y-0.5"
      >
        <li
          v-for="item in filteredOptions"
          :key="item.value"
          role="option"
          :aria-selected="item.value === modelValue"
          @click="selectOption(item)"
          :class="[
            'px-3 py-2 rounded-lg text-sm cursor-pointer flex items-center justify-between transition-colors select-none',
            item.value === modelValue
              ? 'bg-blue-50 text-blue-700 font-medium'
              : 'text-gray-700 hover:bg-gray-100/80 hover:text-gray-900'
          ]"
        >
          <span class="truncate">{{ item.label }}</span>
          <svg
            v-if="item.value === modelValue"
            class="h-4 w-4 shrink-0 text-blue-600 ml-2"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
          </svg>
        </li>

        <li
          v-if="filteredOptions.length === 0"
          class="py-4 px-3 text-center text-xs text-gray-500"
        >
          Tidak ada data ditemukan
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
  modelValue: {
    type: [String, Number],
    default: ''
  },
  options: {
    type: Array,
    default: () => []
  },
  placeholder: {
    type: String,
    default: 'Pilih opsi...'
  },
  searchPlaceholder: {
    type: String,
    default: 'Cari...'
  },
  disabled: {
    type: Boolean,
    default: false
  },
  hasError: {
    type: Boolean,
    default: false
  },
  id: {
    type: String,
    default: ''
  }
});

const emit = defineEmits(['update:modelValue', 'change']);

const isOpen = ref(false);
const searchQuery = ref('');
const containerRef = ref(null);
const searchInputRef = ref(null);

const normalizedOptions = computed(() => {
  return (props.options || []).map(opt => {
    if (typeof opt === 'object' && opt !== null) {
      return {
        value: opt.value !== undefined ? opt.value : opt.id,
        label: opt.label !== undefined ? opt.label : opt.name
      };
    }
    return { value: opt, label: String(opt) };
  });
});

// "jika select lebih dari 5 bisa search"
const shouldShowSearch = computed(() => {
  return normalizedOptions.value.length > 5;
});

const selectedLabel = computed(() => {
  const found = normalizedOptions.value.find(opt => String(opt.value) === String(props.modelValue));
  return found ? found.label : '';
});

const filteredOptions = computed(() => {
  if (!shouldShowSearch.value || !searchQuery.value.trim()) {
    return normalizedOptions.value;
  }
  const q = searchQuery.value.toLowerCase().trim();
  return normalizedOptions.value.filter(opt => {
    return String(opt.label).toLowerCase().includes(q);
  });
});

function toggleDropdown() {
  if (props.disabled) return;
  if (isOpen.value) {
    closeDropdown();
  } else {
    openDropdown();
  }
}

function openDropdown() {
  isOpen.value = true;
  searchQuery.value = '';
  if (shouldShowSearch.value) {
    nextTick(() => {
      searchInputRef.value?.focus();
    });
  }
}

function closeDropdown() {
  isOpen.value = false;
  searchQuery.value = '';
}

function selectOption(item) {
  emit('update:modelValue', item.value);
  emit('change', item.value);
  closeDropdown();
}

function selectFirstMatching() {
  if (filteredOptions.value.length > 0) {
    selectOption(filteredOptions.value[0]);
  }
}

function handleClickOutside(event) {
  if (containerRef.value && !containerRef.value.contains(event.target)) {
    closeDropdown();
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
});

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>
