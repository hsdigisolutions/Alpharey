import './bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { ZiggyVue } from 'ziggy-js';
import Bilingual from '@/Components/Bilingual.vue';

createInertiaApp({
    title: (title) => (title ? `${title} — Verto5` : 'Verto5'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            // Registered globally: every screen renders bilingual labels.
            .component('Bilingual', Bilingual)
            .mount(el);
    },
    progress: {
        color: '#4f46e5',
    },
});
