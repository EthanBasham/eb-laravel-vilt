<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppShell from '../Components/AppShell.vue';
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
    router.post('/wot/news/seen-all', {}, { preserveScroll: true });
};

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

const asDate = (iso) => new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' });

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
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-wot-border pb-5">
            <div>
                <h1 class="text-3xl">News</h1>
                <p class="mt-1 flex items-center gap-1.5 text-sm text-wot-dim">
                    From the official worldoftanks.com feeds.

                    <a
                        href="https://worldoftanks.com/en/news/"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex text-wot-dim transition-colors hover:text-wot-gold"
                    >
                        <!-- The icon is decorative; the link's accessible name
                             comes from the visually-hidden text beside it. -->
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.25 3h6.75v6.75M21 3l-9 9" />
                        </svg>
                        <span class="sr-only">Open the World of Tanks news site in a new tab</span>
                    </a>
                </p>
            </div>

            <Link href="/wot/calendar" class="text-sm font-medium text-wot-gold hover:text-wot-gold-bright">
                View event calendar &rarr;
            </Link>
        </div>

        <div class="mt-6 flex flex-wrap gap-2">
            <button
                type="button"
                class="border px-3 py-1.5 text-xs font-bold uppercase tracking-wider transition-colors"
                :class="!activeCategory ? 'border-wot-gold text-wot-gold' : 'border-wot-border text-wot-dim hover:text-wot-text'"
                @click="filterBy(null)"
            >
                All
            </button>
            <button
                v-for="category in categories"
                :key="category"
                type="button"
                class="border px-3 py-1.5 text-xs font-bold uppercase tracking-wider transition-colors"
                :class="activeCategory === category ? 'border-wot-gold text-wot-gold' : 'border-wot-border text-wot-dim hover:text-wot-text'"
                @click="filterBy(category)"
            >
                {{ category }}
            </button>

            <button
                v-if="unseenCount"
                type="button"
                class="ms-auto border border-wot-border px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-good hover:text-wot-good"
                @click="markAllSeen"
            >
                Mark {{ unseenCount }} as seen
            </button>

            <!-- Takes the push to the right itself when there is nothing left to
                 mark seen. ms-auto lives on the Mark button, and without that
                 button in the row nothing else carried the pinned filter off the
                 category chips. -->
            <button
                v-if="pinnedCount || pinnedOnly"
                type="button"
                class="border px-3 py-1.5 text-xs font-bold uppercase tracking-wider transition-colors"
                :class="[
                    pinnedOnly ? 'border-wot-gold text-wot-gold' : 'border-wot-border text-wot-dim hover:text-wot-text',
                    unseenCount ? '' : 'ms-auto',
                ]"
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
                <!-- Sits opposite the pin so the two never collide on a card
                     that is both new and pinned. -->
                <span
                    v-if="!article.is_seen"
                    class="absolute left-2 top-2 z-10 flex items-center gap-1 border border-wot-good bg-wot-good/20 px-1.5 py-0.5 text-xs font-bold uppercase tracking-wider text-wot-good backdrop-blur-sm"
                >
                    <!-- Drops out of the flow once the hover has counted the
                         card, so the badge closes up around the word rather
                         than keeping a gap where the square was. -->
                    <span
                        v-if="!isMarked(article.id)"
                        class="h-1.5 w-1.5 shrink-0 bg-wot-good"
                        aria-hidden="true"
                    />

                    New
                </span>

                <!-- The pin control sits outside the anchor rather than inside
                     it: a button nested in a link is invalid markup, and
                     clicking it would follow the link as well. -->
                <button
                    type="button"
                    class="absolute right-2 top-2 z-10 border px-2 py-1 text-xs leading-none transition-colors"
                    :class="article.is_pinned
                        ? 'border-wot-gold bg-wot-gold/45 text-wot-abyss backdrop-blur-sm'
                        : 'border-wot-border bg-wot-abyss/70 text-wot-dim hover:border-wot-gold hover:text-wot-gold'"
                    :aria-pressed="article.is_pinned"
                    :title="article.is_pinned ? 'Unpin from your feed' : 'Pin to the top of your feed'"
                    @click="togglePin(article)"
                >
                    <span aria-hidden="true">📌</span>
                    <span class="sr-only">{{ article.is_pinned ? 'Unpin' : 'Pin' }} {{ article.title }}</span>
                </button>

                <!-- A real external link: these open the article on
                     worldoftanks.com, which is outside this SPA. -->
                <!-- The border stays a hover affordance only. Using it for
                     pinned state too would make a resting card look identical
                     to a hovered one, so the pin button carries that state. -->
                <a
                    :href="article.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="flex h-full flex-col border border-wot-border bg-wot-panel transition-colors hover:border-wot-gold"
                >
                    <img
                        v-if="article.image_url"
                        :src="article.image_url"
                        alt=""
                        loading="lazy"
                        class="aspect-video w-full object-cover"
                    >

                    <div class="flex flex-1 flex-col p-4">
                        <p class="text-xs font-bold uppercase tracking-wider text-wot-dim">
                            <span v-if="article.is_pinned" class="text-wot-gold">Pinned · </span>
                            {{ article.category }} · {{ asDate(article.published_at) }}
                        </p>

                        <h2 class="mt-2 text-base normal-case tracking-normal text-wot-heading">
                            {{ article.title }}
                        </h2>

                        <p v-if="article.description" class="mt-2 flex-1 text-sm leading-relaxed text-wot-muted">
                            {{ article.description }}
                        </p>

                        <p v-if="article.events_count" class="mt-3 text-xs font-medium text-wot-gold">
                            {{ article.events_count }} dated event{{ article.events_count === 1 ? '' : 's' }}
                        </p>
                    </div>
                </a>
            </li>
        </ul>

        <p v-if="!articles.data.length" class="mt-8 border border-dashed border-wot-border p-8 text-center text-sm text-wot-dim">
            No articles yet. Run <code>php artisan wot:sync-news</code>.
        </p>

        <div class="mt-8 flex flex-wrap items-center justify-between gap-4">
            <nav v-if="articles.links.length > 3" class="flex flex-wrap gap-1" aria-label="Pagination">
                <component
                    :is="link.url ? 'a' : 'span'"
                    v-for="link in articles.links"
                    :key="link.label"
                    :href="link.url"
                    class="border px-3 py-1.5 text-sm"
                    :class="link.active
                        ? 'border-wot-gold text-wot-gold'
                        : link.url ? 'border-wot-border text-wot-muted hover:text-wot-gold' : 'border-wot-border-soft text-wot-dim'"
                    v-html="link.label"
                />
            </nav>
            <span v-else />

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
