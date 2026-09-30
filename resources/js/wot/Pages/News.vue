<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { IconExternalLink } from '@tabler/icons-vue';
import { ref } from 'vue';
import AppShell from '../Components/AppShell.vue';
import EmptyState from '../Components/EmptyState.vue';
import PageHeader from '../Components/PageHeader.vue';
import Pagination from '../Components/Pagination.vue';
import ArticleCard from '../Components/News/ArticleCard.vue';
import { useArticlePin } from '../composables/useArticlePin';
import { useArticleSeenTracker } from '../composables/useArticleSeenTracker';

const props = defineProps({
    articles: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    activeCategory: { type: String, default: null },
    pinnedOnly: { type: Boolean, default: false },
    pinnedCount: { type: Number, default: 0 },
    unseenCount: { type: Number, default: 0 },
});

const { track, isMarked } = useArticleSeenTracker();

const { togglePin } = useArticlePin({
    only: ['articles', 'pinnedCount'],
    optimistic: (pageProps, article, pinning) => ({
        articles: {
            ...pageProps.articles,
            data: pageProps.articles.data.map((row) => (
                row.id === article.id ? { ...row, is_pinned: pinning } : row
            )),
        },
        pinnedCount: pageProps.pinnedCount + (pinning ? 1 : -1),
    }),
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

const resyncing = ref(false);

const chip = 'border px-3 py-1.5 text-xs font-bold uppercase tracking-wider transition-colors';
const lit = 'border-wot-gold text-wot-gold';
const unlit = 'border-wot-border text-wot-dim hover:text-wot-text';
</script>

<template>
    <Head title="News" />

    <AppShell>
        <PageHeader title="News">
            <template #subtitle>
                <span class="flex items-center gap-1.5">
                    From the official worldoftanks.com feeds.

                    <a
                        href="https://worldoftanks.com/en/news/"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex text-wot-dim transition-colors hover:text-wot-gold"
                    >
                        <IconExternalLink :size="16" stroke-width="1.8" aria-hidden="true" />
                        <span class="sr-only">Open the World of Tanks news site in a new tab</span>
                    </a>
                </span>
            </template>

            <Link href="/wot/calendar" class="text-sm font-medium text-wot-gold hover:text-wot-gold-bright">
                View event calendar &rarr;
            </Link>
        </PageHeader>

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

        <ul role="list" class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <li
                v-for="article in articles.data"
                :key="article.id"
                :ref="(el) => track(el, article.id, article.is_seen)"
                class="relative"
            >
                <ArticleCard
                    :article="article"
                    :is-new="!article.is_seen && !isMarked(article.id)"
                    @toggle-pin="togglePin(article)"
                />
            </li>
        </ul>

        <EmptyState v-if="!articles.data.length" class="mt-8">
            No articles yet.
        </EmptyState>

        <div class="mt-8 flex flex-wrap items-center justify-between gap-4">
            <Pagination :links="articles.links" />

            <div class="ms-auto flex flex-wrap gap-2">
                <Link
                    href="/wot/news/mark-all-unseen"
                    method="post"
                    as="button"
                    preserve-scroll
                    :preserve-state="false"
                    :only="['articles', 'unseenCount']"
                    class="border border-wot-border px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-gold hover:text-wot-gold"
                >
                    Mark all unseen
                </Link>

                <Link
                    href="/wot/news/resync"
                    method="post"
                    as="button"
                    preserve-scroll
                    :disabled="resyncing"
                    class="border border-wot-border px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-gold hover:text-wot-gold disabled:cursor-not-allowed disabled:opacity-50"
                    @start="resyncing = true"
                    @finish="resyncing = false"
                >
                    {{ resyncing ? 'Resyncing…' : 'Resync news & calendar' }}
                </Link>
            </div>
        </div>
    </AppShell>
</template>
