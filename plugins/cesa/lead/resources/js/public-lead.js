import { createApp } from 'vue';
import PublicLeadForm from './PublicLeadForm.vue';

const el = document.getElementById('lead-public-form');
if (el) {
    const config = window.__LEAD_CONFIG__ || {};
    const app = createApp(PublicLeadForm, { config });
    app.mount(el);
}
