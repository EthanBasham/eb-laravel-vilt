<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useSeenTracker } from '../composables/useSeenTracker';

const props = defineProps({
    news: { type: Object, required: true },
});

// Rows mark themselves seen once the pointer has rested on one for 1.5s, the
// same as the cards on /wot/news.
const { track, isMarked } = useSeenTracker();

const tab = ref('latest');

// Both lists arrive with the page, so switching is instant and costs no request.
const articles = computed(() => props.news[tab.value] ?? []);

const tabs = [
    { key: 'latest', label: 'Latest' },
    { key: 'pinned', label: 'Pinned' },
];

const asDate = (iso) => new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });

// only: ['news'] so a pin doesn't resend the garage table — several hundred
// vehicles of JSON that hasn't changed. Both tabs come back together, so the
// Pinned list stays correct without a second request.
const togglePin = (article) => {
    const options = { preserveScroll: true, preserveState: true, only: ['news'] };

    article.is_pinned
        ? router.delete(`/wot/news/${article.id}/pin`, options)
        : router.post(`/wot/news/${article.id}/pin`, {}, options);
};
</script>

<template>
    <section class="flex flex-col border border-wot-border bg-wot-panel" aria-labelledby="news-panel-heading">
        <div class="flex items-center justify-between gap-3 border-b border-wot-border px-4 py-3">
            <h2 id="news-panel-heading" class="text-base">News</h2>

            <div class="flex gap-1" role="tablist" aria-label="News filter">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    role="tab"
                    :aria-selected="tab === t.key"
                    class="border px-2.5 py-1 text-xs font-bold uppercase tracking-wider transition-colors"
                    :class="tab === t.key
                        ? 'border-wot-gold text-wot-gold'
                        : 'border-wot-border text-wot-dim hover:text-wot-text'"
                    @click="tab = t.key"
                >
                    {{ t.label }}
                </button>
            </div>
        </div>

        <ul v-if="articles.length" role="list" class="flex-1 divide-y divide-wot-border-soft">
            <li
                v-for="article in articles"
                :key="article.id"
                :ref="(el) => track(el, article.id, article.is_seen)"
                class="flex items-stretch"
            >
                <a
                    :href="article.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="flex min-w-0 flex-1 gap-3 p-3 transition-colors hover:bg-wot-sunken"
                >
                    <!-- Fixed box so a missing or oddly-sized image can't make
                         one row taller than the rest. -->
                    <span class="h-12 w-20 shrink-0 overflow-hidden bg-wot-sunken">
                        <img
                            v-if="article.image_url"
                            :src="article.image_url"
                            alt=""
                            loading="lazy"
                            class="h-full w-full object-cover"
                        >
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span
                                v-if="!article.is_seen && !isMarked(article.id)"
                                class="h-1.5 w-1.5 shrink-0 bg-wot-good"
                                aria-hidden="true"
                            />
                            <span class="truncate text-xs uppercase tracking-wider text-wot-dim">
                                {{ article.category }} · {{ asDate(article.published_at) }}
                                <span v-if="!article.is_seen" class="sr-only">(unread)</span>
                            </span>
                        </span>

                        <!-- Two lines then ellipsis: headlines vary a lot in
                             length and a ragged panel is harder to scan. -->
                        <span class="mt-0.5 line-clamp-2 text-sm leading-snug text-wot-heading">
                            {{ article.title }}
                        </span>
                    </span>
                </a>

                <button
                    type="button"
                    class="shrink-0 border-s px-2.5 text-xs leading-none transition-colors"
                    :class="article.is_pinned
                        ? 'border-wot-border-soft bg-wot-gold/45 text-wot-abyss'
                        : 'border-wot-border-soft text-wot-dim hover:bg-wot-sunken hover:text-wot-gold'"
                    :aria-pressed="article.is_pinned"
                    :title="article.is_pinned ? 'Unpin from your feed' : 'Pin to the top of your feed'"
                    @click="togglePin(article)"
                >
                    <span aria-hidden="true">📌</span>
                    <span class="sr-only">{{ article.is_pinned ? 'Unpin' : 'Pin' }} {{ article.title }}</span>
                </button>
            </li>
        </ul>

        <p v-else class="flex-1 px-4 py-8 text-center text-sm text-wot-dim">
            {{ tab === 'pinned' ? 'Nothing pinned yet.' : 'No articles yet.' }}
        </p>

        <div class="border-t border-wot-border px-4 py-2 text-right">
            <Link href="/wot/news" class="text-xs font-medium text-wot-gold hover:text-wot-gold-bright">
                All news &rarr;
            </Link>
        </div>
    </section>
</template>
