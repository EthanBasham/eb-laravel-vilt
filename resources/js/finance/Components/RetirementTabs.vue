<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * The Retirement Strategizer's tabs: one tool to a tab, each its own page.
 * Real links rather than a toggle, so a tab is a URL that can be kept.
 */
defineProps({
    current: { type: String, required: true },
});

const tabs = [
    { key: 'conversions', label: 'Roth conversions', href: '/finance/retirement' },
    { key: 'more', label: 'More to come', href: '/finance/retirement/more' },
];
</script>

<template>
    <nav class="mb-5 flex gap-1 border-b border-fin-grey-200" aria-label="Retirement tools">
        <Link
            v-for="tab in tabs" :key="tab.key" :href="tab.href"
            class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium"
            :class="tab.key === current ? 'border-fin-green-500 text-fin-black' : 'border-transparent text-fin-grey-500 hover:text-fin-black'"
            :aria-current="tab.key === current ? 'page' : undefined"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
