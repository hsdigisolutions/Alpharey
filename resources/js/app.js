import './bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import Bilingual from '@/Components/Bilingual.vue';
import { initPwa } from '@/pwa';
import { t, tPair } from '@/translate';

createInertiaApp({
    title: (title) => (title ? `${title} — AlphaRey` : 'AlphaRey'),
    // Lazy per-page resolver: `import.meta.glob` WITHOUT `eager` gives each
    // page its own chunk, loaded on demand, instead of bundling all 48 pages
    // into one file. resolvePageComponent awaits the dynamic import.
    resolve: (name) => resolvePageComponent(
        `./Pages/${name}.vue`,
        import.meta.glob('./Pages/**/*.vue'),
    ),
    setup({ el, App, props, plugin }) {
        // window.Ziggy is normally set by the @routes Blade directive (inline
        // script), which is blocked by the production CSP (script-src 'self').
        // Set it from the Inertia shared prop instead so that components using
        // `import { route } from 'ziggy-js'` directly can find the config.
        if (props.initialPage.props.ziggy) {
            window.Ziggy = props.initialPage.props.ziggy;
        }

        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue, props.initialPage.props.ziggy)
            // Registered globally: every screen renders bilingual labels.
            .component('Bilingual', Bilingual);

        // $t / $tPair for the slots that cannot hold markup — tab titles,
        // aria-labels, placeholders, <option> text. Both follow the active
        // locale, so everything flips together on the ES/EN toggle.
        app.config.globalProperties.$t = t;
        app.config.globalProperties.$tPair = tPair;

        app.mount(el);
    },
    progress: {
        color: '#d4956a',
    },
});

// Registers the service worker on /worker pages only (see pwa.js).
initPwa();
