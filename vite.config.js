import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import path from 'path';
import { lucideIcons } from './node_modules/frappe-ui/vite/lucideIcons.js';

export default defineConfig({
    build: {
        emptyOutDir: false,
    },
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/filament/admin/theme.css',
                'resources/css/filament/public/theme.css',
                'plugins/cesa/rekrutmen/resources/js/app.js',
                'plugins/cesa/waste/resources/js/public-waste.js',
            ],
            refresh: true,
        }),
        vue(),
        lucideIcons().filter((plugin) => plugin.name === 'frappe-ui-lucide-icons'),
    ],
    resolve: {
        alias: {
            '@rekrutmen': '/plugins/cesa/rekrutmen/resources/js',
            '@lead': '/plugins/cesa/lead/resources/js',
            'frappe-ui/src': path.resolve(__dirname, 'node_modules/frappe-ui/src'),
        },
    },
});
