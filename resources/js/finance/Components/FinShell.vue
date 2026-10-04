<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    IconArrowsExchange, IconBeach, IconBuildingEstate, IconCalculator, IconLayoutDashboard, IconLeaf,
    IconLogout, IconSailboat, IconScale, IconSettings, IconShip, IconTarget, IconTimeline, IconTrendingUp, IconWallet,
} from '@tabler/icons-vue';
import { computed } from 'vue';

/**
 * The frame every Financial Fleet page sits in: an icon rail down the left,
 * a title bar, and the page.
 *
 * The rail is icons only, so each link carries its name twice over for
 * anyone who cannot go by the picture — as `aria-label` for a screen reader
 * and as a label that slides out on hover or keyboard focus.
 */
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const user = computed(() => page.props.auth?.user);
const initials = computed(() => (user.value?.name ?? '?').split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase());

/*
 * Two groups: the fleet and the money moving through it, then the tools that
 * read them. `match` is the path prefix that lights the link up, so a holding's
 * own page (/finance/fleet/12) still shows Fleet as current.
 */
const groups = [
    [
        { href: '/finance', label: 'Overview', icon: IconLayoutDashboard, exact: true },
        { href: '/finance/fleet', label: 'Fleet', icon: IconSailboat },
        { href: '/finance/armadas', label: 'Armadas', icon: IconShip },
        { href: '/finance/cashflow', label: 'Income & expenses', icon: IconArrowsExchange },
        { href: '/finance/scenarios', label: 'Projections & scenarios', icon: IconTimeline },
        { href: '/finance/budget', label: 'Monthly budget', icon: IconWallet },
        { href: '/finance/goals', label: 'Savings goals', icon: IconTarget },
    ],
    [
        { href: '/finance/projector', label: 'Portfolio projector', icon: IconTrendingUp },
        { href: '/finance/real-estate', label: 'Real estate comparator', icon: IconBuildingEstate },
        { href: '/finance/retirement', label: 'Retirement strategizer', icon: IconBeach },
        { href: '/finance/reality', label: 'Projected vs reality', icon: IconScale },
        { href: '/finance/calculators', label: 'Calculators', icon: IconCalculator },
    ],
];

const path = computed(() => page.url.split('?')[0]);
const isCurrent = (item) => (item.exact ? path.value === item.href : path.value.startsWith(item.href));
</script>

<template>
    <Head :title="title" />

    <div class="fin flex min-h-screen">
        <nav class="fin-rail sticky top-0 flex h-screen w-[68px] shrink-0 flex-col items-center gap-1 bg-fin-charcoal py-4" aria-label="Financial Fleet">
            <Link href="/finance" class="mb-4 flex h-10 w-10 items-center justify-center rounded-xl bg-fin-green-500 text-fin-white" aria-label="Financial Fleet home">
                <IconLeaf :size="22" stroke-width="2" />
            </Link>

            <template v-for="(group, index) in groups" :key="index">
                <span v-if="index > 0" class="my-2 h-px w-8 bg-fin-charcoal-soft" aria-hidden="true" />

                <Link
                    v-for="item in group"
                    :key="item.href"
                    :href="item.href"
                    class="fin-rail-link"
                    :class="{ 'is-current': isCurrent(item) }"
                    :aria-label="item.label"
                    :aria-current="isCurrent(item) ? 'page' : undefined"
                >
                    <component :is="item.icon" :size="21" stroke-width="1.7" />
                    <span class="fin-rail-tip" aria-hidden="true">{{ item.label }}</span>
                </Link>
            </template>

            <div class="mt-auto flex flex-col items-center gap-1">
                <Link href="/finance/settings" class="fin-rail-link" :class="{ 'is-current': path.startsWith('/finance/settings') }" aria-label="Profile & settings">
                    <IconSettings :size="21" stroke-width="1.7" />
                    <span class="fin-rail-tip" aria-hidden="true">Profile &amp; settings</span>
                </Link>

                <!-- A real link, not an Inertia visit: the rest of the site is
                     Blade, so leaving the island is a full page load. -->
                <a href="/" class="fin-rail-link" aria-label="Back to the main site">
                    <IconLogout :size="21" stroke-width="1.7" />
                    <span class="fin-rail-tip" aria-hidden="true">Back to the main site</span>
                </a>

                <span class="mt-2 flex h-9 w-9 items-center justify-center rounded-full bg-fin-gold-400 text-xs font-semibold text-fin-black" :title="user?.name">
                    {{ initials }}
                </span>
            </div>
        </nav>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex flex-wrap items-end justify-between gap-4 px-6 pb-2 pt-8 lg:px-10">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-fin-black">{{ title }}</h1>
                    <p v-if="subtitle" class="mt-1 max-w-2xl text-sm text-fin-grey-500">{{ subtitle }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <slot name="actions" />
                </div>
            </header>

            <main class="flex-1 px-6 pb-16 pt-4 lg:px-10">
                <p v-if="flash.success" class="mb-5 rounded-xl border border-fin-green-200 bg-fin-green-50 px-4 py-3 text-sm text-fin-green-800" role="status">
                    {{ flash.success }}
                </p>
                <p v-if="flash.error" class="mb-5 rounded-xl border border-fin-red-600/30 bg-fin-red-100 px-4 py-3 text-sm text-fin-red-600" role="alert">
                    {{ flash.error }}
                </p>

                <slot />
            </main>
        </div>
    </div>
</template>

<style>
/*
 * Deliberately not `scoped`: these style the document behind the app root
 * and the handful of element-level defaults every page shares, neither of
 * which a scoped style can reach. The file only ships in the Financial Fleet
 * Vite entry, and every rule is under `.fin`, so nothing leaks.
 */
body:has(.fin) {
    background-color: var(--color-fin-cream-50);
    color: var(--color-fin-charcoal);
}

.fin-rail-link {
    position: relative;
    display: flex;
    height: 2.5rem;
    width: 2.5rem;
    align-items: center;
    justify-content: center;
    border-radius: 0.75rem;
    color: var(--color-fin-grey-400);
    transition: background-color 120ms, color 120ms;
}

.fin-rail-link:hover,
.fin-rail-link:focus-visible {
    background-color: var(--color-fin-charcoal-soft);
    color: var(--color-fin-white);
}

.fin-rail-link.is-current {
    background-color: var(--color-fin-green-500);
    color: var(--color-fin-white);
}

/* The gold tick at the rail's edge beside the current page. */
.fin-rail-link.is-current::before {
    content: '';
    position: absolute;
    left: -0.875rem;
    height: 1.25rem;
    width: 0.1875rem;
    border-radius: 0 0.1875rem 0.1875rem 0;
    background-color: var(--color-fin-gold-400);
}

.fin-rail-tip {
    pointer-events: none;
    position: absolute;
    left: calc(100% + 0.75rem);
    z-index: 40;
    white-space: nowrap;
    border-radius: 0.5rem;
    background-color: var(--color-fin-black);
    padding: 0.375rem 0.625rem;
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--color-fin-white);
    opacity: 0;
    transform: translateX(-0.25rem);
    transition: opacity 120ms, transform 120ms;
}

.fin-rail-link:hover .fin-rail-tip,
.fin-rail-link:focus-visible .fin-rail-tip {
    opacity: 1;
    transform: translateX(0);
}

.fin :focus-visible {
    outline: 2px solid var(--color-fin-green-500);
    outline-offset: 2px;
}

/*
 * Form controls, set once. In @layer base so a utility on an individual field
 * can still override one — an unlayered rule would beat every Tailwind
 * utility regardless of specificity, because utilities live in a layer.
 */
@layer base {
    .fin input[type='text'],
    .fin input[type='number'],
    .fin input[type='date'],
    .fin input[type='month'],
    .fin select,
    .fin textarea {
        width: 100%;
        border-radius: 0.625rem;
        border-color: var(--color-fin-grey-300);
        background-color: var(--color-fin-white);
        padding: 0.5rem 0.75rem;
        font-size: 0.875rem;
        color: var(--color-fin-black);
    }

    .fin input:focus,
    .fin select:focus,
    .fin textarea:focus {
        border-color: var(--color-fin-green-500);
        --tw-ring-color: var(--color-fin-green-500);
    }

    .fin input[type='checkbox'] {
        border-radius: 0.3125rem;
        border-color: var(--color-fin-grey-400);
        color: var(--color-fin-green-500);
    }

    .fin input[type='radio'] {
        border-color: var(--color-fin-grey-400);
        color: var(--color-fin-green-500);
    }
}

/* Columns of figures line up; headline numbers keep proportional digits. */
.fin table {
    font-variant-numeric: tabular-nums;
}

.fin-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    border-radius: 0.625rem;
    border: 1px solid transparent;
    padding: 0.5rem 0.875rem;
    font-size: 0.875rem;
    font-weight: 500;
    line-height: 1.25rem;
    transition: background-color 120ms, border-color 120ms, color 120ms;
}

.fin-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.fin-btn-primary {
    background-color: var(--color-fin-green-500);
    color: var(--color-fin-white);
}

.fin-btn-primary:hover:not(:disabled) {
    background-color: var(--color-fin-green-600);
}

.fin-btn-quiet {
    border-color: var(--color-fin-grey-300);
    background-color: var(--color-fin-white);
    color: var(--color-fin-charcoal);
}

.fin-btn-quiet:hover:not(:disabled) {
    border-color: var(--color-fin-grey-400);
    background-color: var(--color-fin-cream-100);
}

.fin-btn-danger {
    border-color: color-mix(in srgb, var(--color-fin-red-600) 35%, transparent);
    background-color: var(--color-fin-white);
    color: var(--color-fin-red-600);
}

.fin-btn-danger:hover:not(:disabled) {
    background-color: var(--color-fin-red-100);
}

/* A square button holding one icon — edit and delete, at the end of a row. */
.fin-icon-btn {
    display: inline-flex;
    height: 1.875rem;
    width: 1.875rem;
    align-items: center;
    justify-content: center;
    border-radius: 0.5rem;
    color: var(--color-fin-grey-500);
}

.fin-icon-btn:hover {
    background-color: var(--color-fin-cream-200);
    color: var(--color-fin-black);
}

.fin-dialog {
    width: min(34rem, calc(100vw - 2rem));
    max-height: calc(100vh - 2rem);
    overflow-y: auto;
    border-radius: 1.125rem;
    border: 1px solid var(--color-fin-grey-200);
    background-color: var(--color-fin-white);
    padding: 0;
    color: var(--color-fin-charcoal);
    box-shadow: 0 24px 60px -20px rgba(18, 21, 19, 0.45);
    /* Tailwind's preflight zeroes the margin that centres a modal dialog. */
    margin: auto;
}

.fin-dialog--wide {
    width: min(46rem, calc(100vw - 2rem));
}

.fin-dialog::backdrop {
    background-color: rgba(18, 21, 19, 0.45);
    backdrop-filter: blur(2px);
}
</style>
