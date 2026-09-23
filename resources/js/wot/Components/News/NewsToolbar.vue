<script setup>
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    categories: { type: Array, required: true },
    activeCategory: { type: String, default: null },
    pinnedOnly: { type: Boolean, default: false },
    pinnedCount: { type: Number, default: 0 },
    unseenCount: { type: Number, default: 0 },
});

const filterByCategory = (category) => {
    router.get('/wot/news', {
        ...(category ? { category } : {}),
        ...(props.pinnedOnly ? { pinned: 1 } : {}),
    }, { preserveScroll: true });
};

const togglePinnedOnly = () => {
    router.get('/wot/news', {
        ...(props.activeCategory ? { category: props.activeCategory } : {}),
        ...(props.pinnedOnly ? {} : { pinned: 1 }),
    }, { preserveScroll: true });
};

const markAllSeen = (pageProps) => ({
    unseenCount: 0,
    articles: {
        ...pageProps.articles,
        data: pageProps.articles.data.map((article) => ({ ...article, is_seen: true })),
    },
});

const chip = 'border px-3 py-1.5 text-xs font-bold uppercase tracking-wider transition-colors';
const lit = 'border-wot-gold text-wot-gold';
const unlit = 'border-wot-border text-wot-dim hover:text-wot-text';
</script>

<template>
    <div class="mt-6 flex flex-wrap gap-2">
        <button type="button" :class="[chip, activeCategory ? unlit : lit]" @click="filterByCategory(null)">
            All
        </button>

        <button
            v-for="category in categories"
            :key="category"
            type="button"
            :class="[chip, activeCategory === category ? lit : unlit]"
            @click="filterByCategory(category)"
        >
            {{ category }}
        </button>

        <span class="ms-auto"></span>

        <Link
            v-if="unseenCount"
            href="/wot/news/mark-all-seen"
            method="post"
            as="button"
            preserve-scroll
            :optimistic="markAllSeen"
            :only="['articles', 'unseenCount']"
            class="border border-wot-border px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-good hover:text-wot-good"
        >
            Mark {{ unseenCount }} as seen
        </Link>

        <button
            v-if="pinnedCount || pinnedOnly"
            type="button"
            :class="[chip, pinnedOnly ? lit : unlit]"
            :aria-pressed="pinnedOnly"
            @click="togglePinnedOnly"
        >
            📌 Pinned ({{ pinnedCount }})
        </button>
    </div>
</template>
