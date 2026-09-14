<template>
  <div class="min-h-screen bg-zinc-50/50 font-sans text-zinc-950 antialiased flex flex-col">
    <!-- Top Navbar Navigation -->
    <Navbar :user="user" />

    <!-- Main Content Area -->
    <main class="flex-1 w-full px-4 sm:px-6 lg:px-8 py-6">
      <router-view v-slot="{ Component, route }">
        <keep-alive>
          <component :is="Component" :key="route.name" />
        </keep-alive>
      </router-view>
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import Navbar from './components/Navbar.vue';
import { useRekrutmenStore } from './stores/rekrutmen';

const store = useRekrutmenStore();
const user = ref({ name: 'Reza', email: '' });

onMounted(() => {
  const rootEl = document.getElementById('rekrutmen-app');
  if (rootEl && rootEl.dataset.user) {
    try {
      user.value = JSON.parse(rootEl.dataset.user);
    } catch (e) {
      console.warn('Failed to parse user dataset', e);
    }
  }

  // Preload all module data once into Pinia store
  store.fetchRequests().catch(() => {});
  store.fetchPostings().catch(() => {});
  store.fetchApplications().catch(() => {});
  store.fetchProgressReport().catch(() => {});
  store.fetchConfigurations().catch(() => {});
});
</script>
