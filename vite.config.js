import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    build: {
        // The @vite Blade directive emits <link rel="modulepreload"> from the
        // manifest, so Vite's inline preload-polyfill script is unnecessary —
        // and the production CSP (script-src 'self') would block it. Off.
        modulePreload: { polyfill: false },
        rollupOptions: {
            output: {
                // Keep the boot-time third-party code (Vue, Inertia, Ziggy,
                // axios) in ONE stable `vendor` chunk cached across deploys.
                // Chart.js goes to its own `charts` chunk so it loads ONLY on
                // the pages that render a chart, never on the initial load.
                manualChunks(id) {
                    if (id.includes('chart.js') || id.includes('vue-chartjs')) {
                        return 'charts';
                    }
                    if (id.includes('/node_modules/') || id.includes('vendor/tightenco/ziggy')) {
                        return 'vendor';
                    }
                },
            },
        },
    },
    resolve: {
        alias: {
            '@': '/resources/js',
            // Ziggy's JS lives inside the Composer package
            'ziggy-js': fileURLToPath(new URL('./vendor/tightenco/ziggy', import.meta.url)),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
