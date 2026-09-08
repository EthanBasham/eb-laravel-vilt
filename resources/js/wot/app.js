import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

// Entrypoint for the World of Tanks sub-project only. The base site's jQuery
// bundle (resources/js/app.js) is a separate Vite entry and is never loaded
// alongside this one — resources/views/wot.blade.php pulls in this file, and
// layouts/app.blade.php pulls in the other.
createInertiaApp({
    title: (title) => (title ? `${title} · ${import.meta.env.VITE_APP_NAME}` : import.meta.env.VITE_APP_NAME),

    // eager: true so every page component is in the bundle rather than
    // code-split. There are only a handful of them, and eager resolution keeps
    // navigation instant and the setup easy to reason about while learning.
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });

        return pages[`./Pages/${name}.vue`];
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },

    progress: { color: '#4f46e5' },
});
