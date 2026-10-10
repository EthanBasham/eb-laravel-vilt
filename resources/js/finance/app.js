import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

// Entrypoint for the Financial Fleet sub-project only (/finance). A sibling of
// resources/js/wot/app.js and wired the same way: its own Vite entry, loaded by
// its own root view (resources/views/finance.blade.php), so neither island's
// bundle ships on the other's pages.
createInertiaApp({
    // The area's own name rather than APP_NAME, as in the World of Tanks
    // entrypoint. resources/views/finance.blade.php carries the same name for
    // the moment before this script runs.
    title: (title) => (title ? `${title} | Financial Fleet` : 'Financial Fleet'),

    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });

        return pages[`./Pages/${name}.vue`];
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },

    progress: { color: '#3f893e' },
});
