<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { IconExternalLink } from '@tabler/icons-vue';
import { ref } from 'vue';
import AppShell from '../Components/AppShell.vue';
import EmptyState from '../Components/EmptyState.vue';
import PageHeader from '../Components/PageHeader.vue';
import Pagination from '../Components/Pagination.vue';
import ArticleCard from '../Components/News/ArticleCard.vue';
import NewsToolbar from '../Components/News/NewsToolbar.vue';
import { useSeenTracker } from '../composables/useSeenTracker';

const props = defineProps({
    articles: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    activeCategory: { type: String, default: null },
    pinnedOnly: { type: Boolean, default: false },
    pinnedCount: { type: Number, default: 0 },
    unseenCount: { type: Number, default: 0 },
});

// Cards mark themselves seen once the pointer has rested on one for 1.5s.
const { track, isMarked } = useSeenTracker();

const markAllSeen = () => {
    router.post('/wot/news/mark-all-seen', {}, { preserveScroll: true });
};

/*
 * Both filters navigate, since the server pages the list — and each carries the
 * other along, so narrowing to a category does not silently drop the pinned
 * filter you already had on.
 */
const filterBy = (category) => {
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

// preserveScroll so pinning an article halfway down the list doesn't throw the
// page back to the top; the reordering is visible without losing your place.
const togglePin = (article) => {
    const options = { preserveScroll: true, preserveState: false };

    article.is_pinned
        ? router.delete(`/wot/news/${article.id}/pin`, options)
        : router.post(`/wot/news/${article.id}/pin`, {}, options);
};

// The command fetches several article bodies with a deliberate pace between
// requests, so this can take a while — disabled state stops a second click
// from stacking another run on top of one already in flight.
const resyncing = ref(false);
const resync = () => {
    resyncing.value = true;
    router.post('/wot/news/resync', {}, {
        preserveScroll: true,
        onFinish: () => { resyncing.value = false; },
    });
};
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
                        <!-- The icon is decorative; the link's accessible name
                             comes from the visually-hidden text beside it. -->
                        <IconExternalLink :size="16" stroke-width="1.8" aria-hidden="true" />
                        <span class="sr-only">Open the World of Tanks news site in a new tab</span>
                    </a>
                </span>
            </template>

            <Link href="/wot/calendar" class="text-sm font-medium text-wot-gold hover:text-wot-gold-bright">
                View event calendar &rarr;
            </Link>
        </PageHeader>

        <NewsToolbar
            :categories="categories"
            :active-category="activeCategory"
            :pinned-only="pinnedOnly"
            :pinned-count="pinnedCount"
            :unseen-count="unseenCount"
            @filter="filterBy"
            @mark-all-seen="markAllSeen"
            @toggle-pinned-only="togglePinnedOnly"
        />

        <ul role="list" class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <!-- The tracker watches the <li>, so the hover that counts an
                 article covers the whole card and the controls over it. -->
            <li
                v-for="article in articles.data"
                :key="article.id"
                :ref="(el) => track(el, article.id, article.is_seen)"
                class="relative"
            >
                <ArticleCard
                    :article="article"
                    :counted="isMarked(article.id)"
                    @toggle-pin="togglePin(article)"
                />
            </li>
        </ul>

        <EmptyState v-if="!articles.data.length" class="mt-8">
            No articles yet. Run <code>php artisan wot:sync-news</code>.
        </EmptyState>

        <div class="mt-8 flex flex-wrap items-center justify-between gap-4">
            <Pagination :links="articles.links" />
            <!-- Holds the left-hand half of the row when there is no paginator,
                 so Resync stays where it is rather than sliding over. -->
            <span v-if="articles.links.length <= 3" />

            <button
                type="button"
                class="ms-auto border border-wot-border px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-gold hover:text-wot-gold disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="resyncing"
                @click="resync"
            >
                {{ resyncing ? 'Resyncing…' : 'Resync news & calendar' }}
            </button>
        </div>
    </AppShell>
</template>
