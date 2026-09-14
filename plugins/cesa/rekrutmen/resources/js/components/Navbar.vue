<template>
  <header class="sticky top-0 z-50 bg-white/95 backdrop-blur-xs border-b border-zinc-200 shadow-2xs select-none font-sans text-zinc-900">
    <div class="w-full px-4 sm:px-6 lg:px-8">
      <div class="relative flex items-center justify-between h-14">
        
        <!-- Left: Brand Logo -->
        <div class="flex items-center shrink-0 z-10">
          <router-link to="/admin/job-postings" class="flex items-center gap-2.5 group">
            <img
              :src="logoUrl"
              alt="CESA Logo"
              class="h-7 w-7 shrink-0 object-contain drop-shadow-2xs transition-transform group-hover:scale-105"
            />
            <div class="flex flex-col leading-none">
              <span class="text-sm font-black tracking-wider text-[#0c2340] uppercase">CESA</span>
              <span class="text-[9px] font-bold tracking-widest text-zinc-400 uppercase mt-0.5">REKRUTMEN</span>
            </div>
          </router-link>
        </div>

        <!-- Center: Mathematically Centered Navigation Menu Links -->
        <nav class="hidden md:flex items-center justify-center gap-6 lg:gap-8 text-xs absolute inset-x-0 mx-auto w-fit z-0 pointer-events-none [&>*]:pointer-events-auto">
          <router-link
            to="/admin/job-postings"
            class="relative py-1 text-xs font-medium transition-all inline-flex flex-col items-center"
            :class="[
              isActive('/admin/job-postings')
                ? 'text-[#0c2340] font-semibold'
                : 'text-zinc-500 hover:text-zinc-900'
            ]"
          >
            <span>Lowongan Kerja</span>
            <span
              v-if="isActive('/admin/job-postings')"
              class="absolute -bottom-2 left-0 right-0 h-[2px] bg-[#0c2340] rounded-full"
            ></span>
          </router-link>

          <router-link
            to="/admin/job-applications"
            class="relative py-1 text-xs font-medium transition-all inline-flex flex-col items-center"
            :class="[
              isActive('/admin/job-applications')
                ? 'text-[#0c2340] font-semibold'
                : 'text-zinc-500 hover:text-zinc-900'
            ]"
          >
            <span>Data Pelamar</span>
            <span
              v-if="isActive('/admin/job-applications')"
              class="absolute -bottom-2 left-0 right-0 h-[2px] bg-[#0c2340] rounded-full"
            ></span>
          </router-link>

          <router-link
            to="/admin/request-man-powers"
            class="relative py-1 text-xs font-medium transition-all inline-flex flex-col items-center"
            :class="[
              isActive('/admin/request-man-powers')
                ? 'text-[#0c2340] font-semibold'
                : 'text-zinc-500 hover:text-zinc-900'
            ]"
          >
            <span>Permintaan FPTK</span>
            <span
              v-if="isActive('/admin/request-man-powers')"
              class="absolute -bottom-2 left-0 right-0 h-[2px] bg-[#0c2340] rounded-full"
            ></span>
          </router-link>

          <router-link
            to="/admin/recruitment-progress"
            class="relative py-1 text-xs font-medium transition-all inline-flex flex-col items-center"
            :class="[
              isActive('/admin/recruitment-progress')
                ? 'text-[#0c2340] font-semibold'
                : 'text-zinc-500 hover:text-zinc-900'
            ]"
          >
            <span>Monitoring & Progress</span>
            <span
              v-if="isActive('/admin/recruitment-progress')"
              class="absolute -bottom-2 left-0 right-0 h-[2px] bg-[#0c2340] rounded-full"
            ></span>
          </router-link>

          <router-link
            to="/admin/configurations"
            class="relative py-1 text-xs font-medium transition-all inline-flex flex-col items-center"
            :class="[
              isActive('/admin/configurations')
                ? 'text-[#0c2340] font-semibold'
                : 'text-zinc-500 hover:text-zinc-900'
            ]"
          >
            <span>Master Data</span>
            <span
              v-if="isActive('/admin/configurations')"
              class="absolute -bottom-2 left-0 right-0 h-[2px] bg-[#0c2340] rounded-full"
            ></span>
          </router-link>
        </nav>

        <!-- Right: Admin Panel Launcher + Profile Dropdown -->
        <div class="flex items-center gap-2.5 shrink-0 z-10">
          <!-- 1. CESA Apps & Plugin Launcher Trigger -->
          <div class="relative" ref="launcherRef">
            <button
              @click="toggleLauncher"
              class="w-8 h-8 rounded-lg flex items-center justify-center bg-zinc-50 hover:bg-zinc-100 text-zinc-600 hover:text-zinc-900 border border-zinc-200 shadow-2xs transition-all cursor-pointer focus:outline-none"
              :class="{ 'ring-1 ring-zinc-950 bg-zinc-100 text-zinc-950': launcherOpen }"
              title="Aplikasi CESA"
              aria-label="Aplikasi CESA"
            >
              <LayoutDashboard class="w-3.5 h-3.5" />
            </button>

            <!-- Apps Launcher Dropdown Panel (Photo 2 design) -->
            <div
              v-if="launcherOpen"
              class="absolute right-0 top-full mt-2.5 w-[330px] sm:w-[360px] bg-white rounded-2xl shadow-xl border border-slate-100 p-3.5 sm:p-4 z-50 text-slate-800 animate-in fade-in zoom-in-95 duration-100"
            >
              <div class="grid grid-cols-3 gap-2 sm:gap-2.5 max-h-[75vh] overflow-y-auto p-1">
                <a
                  v-for="plugin in pluginsList"
                  :key="plugin.key || plugin.label"
                  :href="plugin.url"
                  class="group flex flex-col items-center justify-start p-2 rounded-xl transition-all duration-150 text-center hover:bg-slate-100/70 cursor-pointer text-decoration-none"
                  :class="[
                    isCurrentPlugin(plugin)
                      ? 'bg-slate-100/90 ring-1 ring-slate-200'
                      : ''
                  ]"
                >
                  <!-- 64x64 Tile SVG Icon -->
                  <div
                    v-if="plugin.svg"
                    class="w-14 h-14 sm:w-16 sm:h-16 shrink-0 flex items-center justify-center transition-transform duration-150 group-hover:scale-105"
                    v-html="plugin.svg"
                  ></div>
                  <img
                    v-else
                    :src="'/svg/' + plugin.key + '.svg'"
                    :alt="plugin.label"
                    class="w-14 h-14 sm:w-16 sm:h-16 shrink-0 object-contain transition-transform duration-150 group-hover:scale-105"
                  />

                  <!-- Label -->
                  <span
                    class="mt-1 text-xs font-semibold text-slate-800 tracking-tight leading-tight text-center max-w-[96px] truncate"
                    :class="{ 'text-slate-950 font-bold': isCurrentPlugin(plugin) }"
                  >
                    {{ plugin.label }}
                  </span>
                </a>
              </div>
            </div>
          </div>

          <!-- 2. Profile Icon Trigger -->
          <div class="relative" ref="dropdownRef">
            <button
              @click="toggleUserDropdown"
              class="w-8 h-8 rounded-lg flex items-center justify-center bg-zinc-50 hover:bg-zinc-100 text-zinc-600 hover:text-zinc-900 border border-zinc-200 shadow-2xs transition-all cursor-pointer focus:outline-none"
              :class="{ 'ring-1 ring-zinc-950 bg-zinc-100 text-zinc-950': dropdownOpen }"
              title="Profil Pengguna"
              aria-label="Profil Pengguna"
            >
              <User class="w-3.5 h-3.5" />
            </button>

            <!-- Dropdown Card -->
            <div
              v-if="dropdownOpen"
              class="absolute right-0 top-full mt-2 w-72 bg-white rounded-xl shadow-lg border border-zinc-200 p-4 z-50 text-zinc-800 animate-in fade-in zoom-in-95 duration-100"
            >
              <!-- User Info Header -->
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg bg-zinc-100 border border-zinc-200 shrink-0 flex items-center justify-center text-zinc-800 font-semibold text-xs">
                  {{ userInitials }}
                </div>

                <div class="min-w-0 flex-1">
                  <h4 class="font-semibold text-zinc-900 text-xs tracking-tight truncate">
                    {{ user?.name || 'Admin User' }}
                  </h4>
                  <p class="text-[11px] text-zinc-400 font-normal truncate mt-0.5">
                    {{ user?.email || 'admin@example.com' }}
                  </p>
                </div>
              </div>

              <!-- Sign Out Button -->
              <button
                @click="logout"
                class="w-full mt-3.5 flex items-center justify-center gap-1.5 bg-zinc-100 hover:bg-red-50 text-zinc-700 hover:text-red-700 font-medium text-xs py-1.5 px-3 rounded-md transition-colors cursor-pointer border border-zinc-200 hover:border-red-200"
              >
                <LogOut class="w-3.5 h-3.5" />
                <span>Keluar (Sign Out)</span>
              </button>
            </div>
          </div>

          <!-- Mobile Hamburger Toggle -->
          <button
            @click="mobileMenuOpen = !mobileMenuOpen"
            class="md:hidden p-1.5 rounded-md text-zinc-600 hover:text-zinc-900 hover:bg-zinc-100 transition-colors"
          >
            <Menu v-if="!mobileMenuOpen" class="w-4 h-4" />
            <X v-else class="w-4 h-4" />
          </button>
        </div>

      </div>
    </div>

    <!-- Mobile Drawer Menu (Magenta BUMN Style: Clean White Offcanvas Drawer, No Slop Icons) -->
    <Teleport to="body">
      <!-- Backdrop Overlay -->
      <transition name="drawer-fade">
        <div
          v-if="mobileMenuOpen"
          class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs md:hidden"
          @click="mobileMenuOpen = false"
        ></div>
      </transition>

      <!-- Sidebar Drawer -->
      <transition name="drawer-slide">
        <div
          v-if="mobileMenuOpen"
          class="fixed inset-y-0 left-0 z-50 w-72 sm:w-80 bg-white shadow-2xl flex flex-col md:hidden font-sans text-slate-800"
        >
          <!-- Drawer Header -->
          <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between bg-white">
            <div class="flex items-center gap-2.5">
              <img :src="logoUrl" alt="CESA Logo" class="h-7 w-7 object-contain" />
              <div class="flex flex-col leading-none">
                <span class="text-sm font-black tracking-wider text-slate-900 uppercase">CESA</span>
                <span class="text-[9px] font-bold tracking-widest text-slate-400 uppercase mt-0.5">REKRUTMEN</span>
              </div>
            </div>
            <button
              @click="mobileMenuOpen = false"
              class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors cursor-pointer"
              title="Tutup Menu"
            >
              <X class="w-5 h-5" />
            </button>
          </div>

          <!-- Drawer Navigation (Pure Typography, Staggered Smooth Entrance) -->
          <nav class="p-4 space-y-1 overflow-y-auto flex-1">
            <router-link
              to="/admin/job-postings"
              @click="mobileMenuOpen = false"
              class="menu-item block px-3 py-2 rounded-md text-xs transition-colors"
              :class="isActive('/admin/job-postings') ? 'text-zinc-950 bg-zinc-100 font-semibold' : 'font-medium text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900'"
            >
              Lowongan Kerja
            </router-link>

            <router-link
              to="/admin/job-applications"
              @click="mobileMenuOpen = false"
              class="menu-item block px-3 py-2 rounded-md text-xs transition-colors"
              :class="isActive('/admin/job-applications') ? 'text-zinc-950 bg-zinc-100 font-semibold' : 'font-medium text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900'"
            >
              Data Pelamar
            </router-link>

            <router-link
              to="/admin/request-man-powers"
              @click="mobileMenuOpen = false"
              class="menu-item block px-3 py-2 rounded-md text-xs transition-colors"
              :class="isActive('/admin/request-man-powers') ? 'text-zinc-950 bg-zinc-100 font-semibold' : 'font-medium text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900'"
            >
              Permintaan FPTK
            </router-link>

            <router-link
              to="/admin/recruitment-progress"
              @click="mobileMenuOpen = false"
              class="menu-item block px-3 py-2 rounded-md text-xs transition-colors"
              :class="isActive('/admin/recruitment-progress') ? 'text-zinc-950 bg-zinc-100 font-semibold' : 'font-medium text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900'"
            >
              Monitoring &amp; Progress
            </router-link>

            <div class="my-2 border-t border-zinc-100"></div>

            <router-link
              to="/admin/configurations"
              @click="mobileMenuOpen = false"
              class="menu-item block px-3 py-2 rounded-md text-xs transition-colors"
              :class="isActive('/admin/configurations') ? 'text-zinc-950 bg-zinc-100 font-semibold' : 'font-medium text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900'"
            >
              Master Data
            </router-link>

            <!-- Mobile Drawer: CESA Apps Grid -->
            <div class="pt-3 pb-1">
              <div class="px-3.5 pb-2 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                Aplikasi CESA
              </div>
              <div class="grid grid-cols-3 gap-2 px-2">
                <a
                  v-for="plugin in pluginsList"
                  :key="'mob-' + (plugin.key || plugin.label)"
                  :href="plugin.url"
                  class="flex flex-col items-center justify-center p-2 rounded-xl border border-slate-100 bg-slate-50/70 hover:bg-white text-center transition-all text-decoration-none"
                  :class="{ 'bg-white shadow-xs border-blue-200 ring-1 ring-blue-300 font-bold': isCurrentPlugin(plugin) }"
                >
                  <div
                    v-if="plugin.svg"
                    class="w-10 h-10 shrink-0 flex items-center justify-center"
                    v-html="plugin.svg"
                  ></div>
                  <img
                    v-else
                    :src="'/svg/' + plugin.key + '.svg'"
                    :alt="plugin.label"
                    class="w-10 h-10 shrink-0 object-contain"
                  />
                  <span class="mt-1 text-[10px] font-semibold text-slate-700 truncate max-w-[72px] leading-tight">
                    {{ plugin.label }}
                  </span>
                </a>
              </div>
            </div>
          </nav>

          <!-- Drawer Footer: User Profile & Sign Out -->
          <div class="p-4 border-t border-slate-100 bg-slate-50/70">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 rounded-lg bg-slate-200 text-slate-700 font-bold text-xs flex items-center justify-center shrink-0">
                {{ userInitials }}
              </div>
              <div class="min-w-0 flex-1">
                <div class="text-xs font-bold text-slate-900 truncate">{{ user?.name || 'User' }}</div>
                <div class="text-[11px] text-slate-400 truncate">{{ user?.email || '-' }}</div>
              </div>
            </div>
            <button
              @click="logout"
              class="w-full mt-3 py-2 px-3 bg-white hover:bg-rose-50 text-rose-600 border border-slate-200 hover:border-rose-200 rounded-lg text-xs font-semibold transition-colors cursor-pointer text-center"
            >
              Sign Out
            </button>
          </div>
        </div>
      </transition>
    </Teleport>
  </header>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import axios from 'axios';
import { LayoutDashboard, User, LogOut, Menu, X } from 'lucide-vue-next';

const props = defineProps({
  user: {
    type: Object,
    default: () => ({ name: 'REZA', email: 'reza@completeselular.com' })
  }
});

const logoUrl = '/images/logo.png';
const dropdownOpen = ref(false);
const dropdownRef = ref(null);
const launcherOpen = ref(false);
const launcherRef = ref(null);
const mobileMenuOpen = ref(false);
const route = useRoute();

const defaultPlugins = [
  { key: 'exit-clearance', label: 'Exit Clearance', url: '/admin/requests', icon: 'icon-exit-clearance' },
  { key: 'form-transfer', label: 'Form Transfer', url: '/admin/transfer-requests', icon: 'icon-form-transfer' },
  { key: 'rekrutmen', label: 'Rekrutmen', url: '/admin/request-man-powers', icon: 'icon-rekrutmen' },
  { key: 'padelnis', label: 'Padelnis', url: '/admin/reservations', icon: 'icon-padelnis' },
  { key: 'lead', label: 'Leads', url: '/admin/leads', icon: 'icon-lead' },
  { key: 'document', label: 'Documents', url: '/admin/documents', icon: 'icon-document' },
  { key: 'plugin', label: 'Plugins', url: '/admin/plugins', icon: 'icon-plugin' },
  { key: 'settings', label: 'Settings', url: '/admin/shield/roles', icon: 'icon-settings' },
];

const pluginsList = ref(defaultPlugins);

const isCurrentPlugin = (plugin) => {
  if (!plugin) return false;
  const key = (plugin.key || '').toLowerCase();
  const label = (plugin.label || '').toLowerCase();
  return key.includes('rekrutmen') || label.includes('rekrutmen');
};

const toggleLauncher = () => {
  launcherOpen.value = !launcherOpen.value;
  if (launcherOpen.value) {
    dropdownOpen.value = false;
  }
};

const toggleUserDropdown = () => {
  dropdownOpen.value = !dropdownOpen.value;
  if (dropdownOpen.value) {
    launcherOpen.value = false;
  }
};

const loadPlugins = async () => {
  const mountEl = document.getElementById('rekrutmen-app');
  if (mountEl && mountEl.dataset.plugins) {
    try {
      const parsed = JSON.parse(mountEl.dataset.plugins);
      if (Array.isArray(parsed) && parsed.length > 0) {
        pluginsList.value = parsed;
        return;
      }
    } catch (e) {
      console.warn('Failed to parse plugins dataset', e);
    }
  }

  try {
    const res = await axios.get('/rekrutmen/api/installed-plugins');
    if (Array.isArray(res.data) && res.data.length > 0) {
      pluginsList.value = res.data;
    }
  } catch (err) {
    // Keep default plugins
  }
};

const isActive = (path) => {
  return route.path.startsWith(path);
};

const userInitials = computed(() => {
  if (!props.user?.name) return 'RE';
  const parts = props.user.name.trim().split(/\s+/);
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase();
  }
  return props.user.name.substring(0, 2).toUpperCase();
});

const handleClickOutside = (event) => {
  if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
    dropdownOpen.value = false;
  }
  if (launcherRef.value && !launcherRef.value.contains(event.target)) {
    launcherOpen.value = false;
  }
};

onMounted(() => {
  loadPlugins();
  document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});

const logout = () => {
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  if (token) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/admin/logout';
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = '_token';
    input.value = token;
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
  } else {
    window.location.href = '/admin/login';
  }
};
</script>

<style>
/* Mobile Drawer Backdrop Fade */
.drawer-fade-enter-active {
  transition: opacity 0.35s ease-out;
}
.drawer-fade-leave-active {
  transition: opacity 0.22s ease-in;
}
.drawer-fade-enter-from,
.drawer-fade-leave-to {
  opacity: 0 !important;
}

/* Mobile Drawer Slide - Smooth Spring Physics (iOS/Linear curve) */
.drawer-slide-enter-active {
  transition: transform 0.38s cubic-bezier(0.16, 1, 0.3, 1) !important;
}
.drawer-slide-leave-active {
  transition: transform 0.25s cubic-bezier(0.4, 0, 1, 1) !important;
}
.drawer-slide-enter-from,
.drawer-slide-leave-to {
  transform: translateX(-100%) !important;
}

/* Cascading Staggered Entrance for Drawer Menu Items */
.drawer-slide-enter-active .menu-item {
  animation: drawerItemEnter 0.42s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.drawer-slide-enter-active .menu-item:nth-child(1) { animation-delay: 0.08s; }
.drawer-slide-enter-active .menu-item:nth-child(2) { animation-delay: 0.12s; }
.drawer-slide-enter-active .menu-item:nth-child(3) { animation-delay: 0.16s; }
.drawer-slide-enter-active .menu-item:nth-child(4) { animation-delay: 0.20s; }
.drawer-slide-enter-active .menu-item:nth-child(6) { animation-delay: 0.24s; }
.drawer-slide-enter-active .menu-item:nth-child(7) { animation-delay: 0.28s; }

@keyframes drawerItemEnter {
  0% {
    opacity: 0;
    transform: translateX(-14px);
  }
  100% {
    opacity: 1;
    transform: translateX(0);
  }
}
</style>
