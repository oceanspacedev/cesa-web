import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

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
                'plugins/cesa/lead/resources/js/public-lead.js',
                'plugins/cesa/rekrutmen/resources/js/public-man-power.js',
            ],
            refresh: true,
        }),
        vue(),
    ],
    resolve: {
        alias: {
            '@rekrutmen': '/plugins/cesa/rekrutmen/resources/js',
            '@lead': '/plugins/cesa/lead/resources/js',
        },
    },
});


