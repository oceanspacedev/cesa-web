import { createApp } from 'vue';
import PublicManPowerForm from './public-man-power/PublicManPowerForm.vue';

const el = document.getElementById('manpower-public-form');
if (el) {
    const config = window.__MANPOWER_CONFIG__ || {};
    const app = createApp(PublicManPowerForm, { config });
    app.mount(el);
}
