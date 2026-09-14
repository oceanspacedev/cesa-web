<template>
  <aside class="w-[250px] bg-[#111827] text-slate-300 flex flex-col h-screen sticky top-0 shrink-0 z-40 transition-all duration-200 font-sans select-none border-r border-slate-800">
    <!-- Brand / Logo Area -->
    <div class="h-16 flex items-center px-5 border-b border-slate-800 gap-3">
      <svg class="h-7 w-7 shrink-0" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M25.4259 6.24256C25.4259 7.4811 24.4218 8.48513 23.1833 8.48513C21.9448 8.48513 20.9408 7.4811 20.9408 6.24256C20.9408 5.00403 21.9448 4 23.1833 4C24.4218 4 25.4259 5.00403 25.4259 6.24256Z" fill="url(#paint0_cesa)"/>
        <path d="M21.115 24.6112L21.125 24.6113L21.1354 24.6112C24.4414 24.6038 26.5072 21.0252 24.8569 18.1577L17.9505 6.15792C16.2946 3.28069 12.1429 3.28069 10.4869 6.15794L3.58058 18.1577C1.93512 21.0167 3.9839 24.5826 7.27266 24.6111L7.30853 24.6113L7.30597 24.6316H11.6003C11.9988 24.6316 12.1399 24.1038 11.7946 23.905L9.16251 22.3894C8.67986 22.1115 8.3824 21.5969 8.3824 21.04V16.0236C8.3824 15.4667 8.67986 14.9521 9.16251 14.6742L13.5258 12.1618C14.0067 11.8849 14.5988 11.8849 15.0798 12.1618L19.443 14.6742C19.9257 14.9521 20.2231 15.4667 20.2231 16.0236V21.04C20.2231 21.5969 19.9257 22.1115 19.443 22.3894L16.811 23.905C16.4656 24.1038 16.6067 24.6316 17.0052 24.6316H20.9408L20.9394 24.6044C20.9802 24.6069 21.0153 24.6085 21.0437 24.6095C21.0778 24.6108 21.1022 24.6111 21.115 24.6112Z" fill="url(#paint1_cesa)"/>
        <path d="M14.3024 20.6847C15.4914 20.6847 16.4553 19.7208 16.4553 18.5318C16.4553 17.3428 15.4914 16.379 14.3024 16.379C13.1134 16.379 12.1496 17.3428 12.1496 18.5318C12.1496 19.7208 13.1134 20.6847 14.3024 20.6847Z" fill="url(#paint2_cesa)"/>
        <defs>
          <linearGradient id="paint0_cesa" x1="31.7338" y1="23.7719" x2="8.30841" y2="13.2412" gradientUnits="userSpaceOnUse">
            <stop stop-color="#CF8EA3"/>
            <stop offset="0.295" stop-color="#A365C8"/>
            <stop offset="0.396805" stop-color="#8660D5"/>
            <stop offset="0.715532" stop-color="#4956F0"/>
            <stop offset="1" stop-color="#3CADE8"/>
          </linearGradient>
          <linearGradient id="paint1_cesa" x1="31.7338" y1="23.7719" x2="8.30841" y2="13.2412" gradientUnits="userSpaceOnUse">
            <stop stop-color="#CF8EA3"/>
            <stop offset="0.295" stop-color="#A365C8"/>
            <stop offset="0.396805" stop-color="#8660D5"/>
            <stop offset="0.715532" stop-color="#4956F0"/>
            <stop offset="1" stop-color="#3CADE8"/>
          </linearGradient>
          <linearGradient id="paint2_cesa" x1="31.7338" y1="23.7719" x2="8.30841" y2="13.2412" gradientUnits="userSpaceOnUse">
            <stop stop-color="#CF8EA3"/>
            <stop offset="0.295" stop-color="#A365C8"/>
            <stop offset="0.396805" stop-color="#8660D5"/>
            <stop offset="0.715532" stop-color="#4956F0"/>
            <stop offset="1" stop-color="#3CADE8"/>
          </linearGradient>
        </defs>
      </svg>
      <div class="min-w-0">
        <h1 class="text-white font-bold text-xs tracking-wider uppercase leading-none">CESA HR</h1>
        <p class="text-[9px] text-blue-400 font-semibold tracking-wider uppercase mt-1 leading-none">Rekrutmen Suite</p>
      </div>
    </div>

    <!-- Navigation Menu Links -->
    <nav class="flex-1 overflow-y-auto py-5 px-3 space-y-1 custom-scrollbar text-xs">
      <div class="px-3 py-1 mb-1">
        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Menu Utama</p>
      </div>

      <router-link
        to="/admin/job-postings"
        class="flex items-center gap-3 px-3 py-2.5 rounded-lg font-medium transition-all group"
        :class="[
          isActive('/admin/job-postings')
            ? 'bg-blue-600 text-white font-semibold shadow-sm shadow-blue-600/30'
            : 'text-slate-400 hover:bg-slate-800/70 hover:text-white'
        ]"
      >
        <Briefcase class="w-4 h-4 shrink-0 transition-transform group-hover:scale-105" />
        <span>Lowongan Kerja</span>
      </router-link>

      <router-link
        to="/admin/job-applications"
        class="flex items-center gap-3 px-3 py-2.5 rounded-lg font-medium transition-all group"
        :class="[
          isActive('/admin/job-applications')
            ? 'bg-blue-600 text-white font-semibold shadow-sm shadow-blue-600/30'
            : 'text-slate-400 hover:bg-slate-800/70 hover:text-white'
        ]"
      >
        <Users class="w-4 h-4 shrink-0 transition-transform group-hover:scale-105" />
        <span>Data Pelamar</span>
      </router-link>

      <router-link
        to="/admin/request-man-powers"
        class="flex items-center gap-3 px-3 py-2.5 rounded-lg font-medium transition-all group"
        :class="[
          isActive('/admin/request-man-powers')
            ? 'bg-blue-600 text-white font-semibold shadow-sm shadow-blue-600/30'
            : 'text-slate-400 hover:bg-slate-800/70 hover:text-white'
        ]"
      >
        <FileText class="w-4 h-4 shrink-0 transition-transform group-hover:scale-105" />
        <span>Permintaan FPTK</span>
      </router-link>

      <router-link
        to="/admin/recruitment-progress"
        class="flex items-center gap-3 px-3 py-2.5 rounded-lg font-medium transition-all group"
        :class="[
          isActive('/admin/recruitment-progress')
            ? 'bg-blue-600 text-white font-semibold shadow-sm shadow-blue-600/30'
            : 'text-slate-400 hover:bg-slate-800/70 hover:text-white'
        ]"
      >
        <BarChart3 class="w-4 h-4 shrink-0 transition-transform group-hover:scale-105" />
        <span>Monitoring & Progress</span>
      </router-link>

      <!-- System Configurations -->
      <div class="px-3 py-1 mb-1 mt-6">
        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Pengaturan</p>
      </div>

      <router-link
        to="/admin/configurations"
        class="flex items-center gap-3 px-3 py-2.5 rounded-lg font-medium transition-all group"
        :class="[
          isActive('/admin/configurations')
            ? 'bg-blue-600 text-white font-semibold shadow-sm shadow-blue-600/30'
            : 'text-slate-400 hover:bg-slate-800/70 hover:text-white'
        ]"
      >
        <Settings class="w-4 h-4 shrink-0 transition-transform group-hover:scale-105" />
        <span>Master Data</span>
      </router-link>

      <!-- Logout Button under Pengaturan -->
      <button
        @click="logout"
        class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg font-medium text-slate-400 hover:bg-rose-950/40 hover:text-rose-400 transition-all group text-left cursor-pointer"
        title="Keluar dari Aplikasi"
      >
        <LogOut class="w-4 h-4 shrink-0 transition-transform group-hover:scale-105 text-slate-400 group-hover:text-rose-400" />
        <span>Logout</span>
      </button>
    </nav>
  </aside>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { 
  FileText, Briefcase, Users, BarChart3, Settings, LogOut
} from 'lucide-vue-next';

const props = defineProps({
  user: {
    type: Object,
    default: () => ({ name: 'Reza', email: '' })
  }
});

const route = useRoute();

const isActive = (path) => {
  return route.path.startsWith(path);
};

const userInitials = computed(() => {
  if (!props.user?.name) return 'HR';
  return props.user.name.substring(0, 2).toUpperCase();
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

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: #1e293b;
  border-radius: 4px;
}
.custom-scrollbar:hover::-webkit-scrollbar-thumb {
  background: #334155;
}
</style>
