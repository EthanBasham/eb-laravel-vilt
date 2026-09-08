import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // Three entrypoints, matching the three halves of the styling story:
            // app.css is Tailwind (utilities + @theme tokens), app.scss is the
            // custom SASS layer, app.js is jQuery. All three are loaded together
            // by the @vite([...]) call in layouts/app.blade.php.
            input: [
                'resources/css/app.css',
                'resources/sass/app.scss',
                'resources/js/app.js',
            ],
            refresh: true,
            // Downloads the font files at build time and serves them from this
            // origin, rather than hitting fonts.bunny.net on every page load.
            // Emitted into the page by the @fonts Blade directive.
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        // Tailwind v4's own Vite plugin. There is no PostCSS pipeline and no
        // tailwind.config.js — v4 is configured from CSS (see the @theme block
        // in resources/css/app.css).
        tailwindcss(),
    ],
    server: {
        // Must match APP_URL's hostname exactly. Vite's default bind is
        // 127.0.0.1 while APP_URL is http://localhost:8000, and to a browser
        // those are different origins even though both are loopback — module
        // scripts (unlike classic scripts) are CORS-checked, so the dev-server
        // asset tags would be blocked.
        host: 'localhost',
        watch: {
            // Compiled Blade templates change on every request; watching them
            // puts the dev server into a reload loop.
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
