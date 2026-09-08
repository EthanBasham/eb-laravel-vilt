<?php

/*
 * Only the keys this project overrides. Laravel merges package config shallowly
 * at the top level, so any block declared here replaces the package's version of
 * that block entirely — which is why `pages` restates every key it needs rather
 * than only `paths`.
 */

return [

    'pages' => [

        /*
         * assertInertia() checks the named component actually exists on disk,
         * which catches typos and components renamed on only one side.
         */
        'ensure_pages_exist' => true,

        /*
         * Not the default resource_path('js/pages'): Inertia is scoped to the
         * World of Tanks sub-project rather than the whole application. Add a
         * path here when another sub-project brings its own Inertia pages.
         */
        'paths' => [
            resource_path('js/wot/Pages'),
        ],

        'extensions' => [
            'vue',
        ],

    ],

    /*
     * No server-side rendering. It needs a separate Node rendering service, and
     * this sub-project is a personal dashboard behind auth — nothing to
     * pre-render for crawlers, and no first-paint budget worth the moving parts.
     */
    'ssr' => [
        'enabled' => false,
    ],

];
