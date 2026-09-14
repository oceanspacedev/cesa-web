import { createApp } from 'vue';
import { createPinia } from 'pinia';
import router from './router';
import App from './App.vue';
import axios from 'axios';

// Configure Axios defaults for Laravel session and CSRF
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
if (csrfToken) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
}

const mountEl = document.getElementById('rekrutmen-app');
if (mountEl) {
    const app = createApp(App);
    const pinia = createPinia();

    app.use(pinia);
    app.use(router);
    app.provide('$axios', axios);

    app.mount('#rekrutmen-app');
}
