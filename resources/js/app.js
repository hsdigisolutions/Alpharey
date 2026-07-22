import './bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { ZiggyVue } from 'ziggy-js';
import Bilingual from '@/Components/Bilingual.vue';
import { initPwa } from '@/pwa';
import { t, tPair } from '@/translate';

createInertiaApp({
    title: (title) => (title ? `${title} — AlphaRey` : 'AlphaRey'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
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
