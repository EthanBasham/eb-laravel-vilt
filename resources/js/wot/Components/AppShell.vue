<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const auth = computed(() => page.props.auth);
const flash = computed(() => page.props.flash ?? {});
</script>

<template>
    <div class="wot flex min-h-screen flex-col">
        <header class="border-b border-wot-border bg-wot-panel backdrop-blur-sm">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 px-4 py-4 sm:px-6 lg:px-8">
                <Link href="/wot" class="wot-title text-lg text-wot-heading hover:text-wot-gold">
                    World of Tanks
                    <span class="ms-2 text-sm normal-case tracking-normal text-wot-dim">dashboard</span>
                </Link>

                <nav class="flex items-center gap-5 text-sm" aria-label="Account">
                    <span v-if="auth.wot" class="font-medium text-wot-gold">{{ auth.wot.nickname }}</span>
                    <!-- A plain <a>, not an Inertia <Link>: the rest of the site
                         is server-rendered Blade outside this SPA, so leaving it
                         needs a real page load. -->
                    <a href="/" class="text-wot-dim transition-colors hover:text-wot-gold-bright">Leave dashboard</a>
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6 lg:px-8">
            <p
                v-if="flash.success"
                class="mb-6 border-l-2 border-wot-good bg-wot-panel px-4 py-3 text-sm text-wot-good"
                role="status"
            >
                {{ flash.success }}
            </p>
            <p
                v-if="flash.error"
                class="mb-6 border-l-2 border-wot-bad bg-wot-panel px-4 py-3 text-sm text-wot-bad"
                role="alert"
            >
                {{ flash.error }}
            </p>

            <slot />
        </main>

        <footer class="border-t border-wot-border-soft py-6 text-center text-xs text-wot-dim">
            Data from the
            <a href="https://developers.wargaming.net" class="text-wot-blue-light hover:text-wot-gold">Wargaming.net Public API</a>.
            Not affiliated with Wargaming.
        </footer>
    </div>
</template>

<style>
/*
 * Deliberately not `scoped`. These rules style the page shell itself — the
 * document background behind the app root — which a scoped style cannot reach.
 * The file only ships in the World of Tanks Vite entry, so nothing here can
 * leak onto the Blade half of the site.
 */

/*
 * Wargaming's own page puts a full-bleed photograph behind everything, which is
 * what makes rgba(7, 21, 30, 0.9) read as a translucent panel rather than a
 * flat fill. Rather than ship someone else's artwork, this is a pair of very
 * low-contrast radial washes over the base colour: enough depth for the panel
 * transparency to actually do something, and it costs no request.
 */
body:has(.wot) {
    background-color: var(--color-wot-bg);
    background-image:
        radial-gradient(ellipse 80% 50% at 50% -10%, rgba(51, 122, 157, 0.18), transparent 70%),
        radial-gradient(ellipse 60% 40% at 100% 100%, rgba(30, 78, 102, 0.14), transparent 70%);
    background-attachment: fixed;
    color: var(--color-wot-text);
}

/*
 * Wargaming sets every heading uppercase in a condensed face ("WarHelios",
 * falling back to Arial Narrow). WarHelios isn't ours to serve, so this uses the
 * same fallback chain they do — the uppercase-and-narrow silhouette is what
 * carries the look.
 */
.wot-title,
.wot h1,
.wot h2,
.wot h3 {
    font-family: 'Arial Narrow', Arial, sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--color-wot-heading);
}

/*
 * Form controls default to a light-mode palette that is unreadable here, and
 * there are enough of them in the garage filters to be worth setting once
 * rather than repeating utilities on each.
 */
.wot input[type='text'],
.wot input[type='search'],
.wot select {
    background-color: var(--color-wot-sunken);
    border-color: var(--color-wot-border);
    color: var(--color-wot-text);
}

.wot input::placeholder {
    color: var(--color-wot-dim);
}

.wot select option {
    /* Native dropdowns paint their own list; without this the options render
       as dark text on a dark background in most browsers. */
    background-color: var(--color-wot-panel-solid);
    color: var(--color-wot-text);
}

.wot input[type='checkbox'] {
    background-color: var(--color-wot-sunken);
    border-color: var(--color-wot-border);
}

.wot input[type='checkbox']:checked {
    background-color: var(--color-wot-gold);
    border-color: var(--color-wot-gold);
}

/*
 * WN8 colour bands. These are the community-standard colours the whole player
 * base reads ratings by — they are not a design choice and should not be
 * swapped for the Wargaming palette, or the numbers stop meaning what everyone
 * expects them to mean.
 */
.wn8-very-bad { color: #930d0d; }
.wn8-bad { color: #cd3333; }
.wn8-below-average { color: #cc7a00; }
.wn8-average { color: #ccb800; }
.wn8-good { color: #849b24; }
.wn8-very-good { color: #4d7326; }
.wn8-great { color: #4a92b7; }
.wn8-unicum { color: #83579d; }
.wn8-super-unicum { color: #5a3d5c; }
.wn8-unknown { color: var(--color-wot-dim); }

/* `good` and `very-good` are dark greens picked for a light background; on this
   one they need lifting or they read as muddy. */
.wot .wn8-good { color: #a4bf2c; }
.wot .wn8-very-good { color: #6fa337; }
.wot .wn8-very-bad { color: #c53030; }

/* The global focus ring from _base.scss is tuned for the light site. */
.wot :focus-visible {
    outline-color: var(--color-wot-gold);
}
</style>
