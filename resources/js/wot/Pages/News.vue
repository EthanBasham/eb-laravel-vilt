<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppShell from '../Components/AppShell.vue';

const props = defineProps({
    articles: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    activeCategory: { type: String, default: null },
    pinnedOnly: { type: Boolean, default: false },
    pinnedCount: { type: Number, default: 0 },
});

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
</script>

<template>
    <Head title="News" />

    <AppShell>
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-wot-border pb-5">
            <div>
                <h1 class="text-3xl">News</h1>
                <p class="mt-1 text-sm text-wot-dim">
                    From the official worldoftanks.com feeds.
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
                v-if="pinnedCount || pinnedOnly"
                type="button"
                class="ms-auto border px-3 py-1.5 text-xs font-bold uppercase tracking-wider transition-colors"
                :class="pinnedOnly ? 'border-wot-gold text-wot-gold' : 'border-wot-border text-wot-dim hover:text-wot-text'"
                :aria-pressed="pinnedOnly"
                @click="togglePinnedOnly"
            >
                📌 Pinned ({{ pinnedCount }})
            </button>
        </div>

        <ul role="list" class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <li v-for="article in articles.data" :key="article.id" class="relative">
                <!-- The pin control sits outside the anchor rather than inside
                     it: a button nested in a link is invalid markup, and
                     clicking it would follow the link as well. -->
                <button
                    type="button"
                    class="absolute right-2 top-2 z-10 border px-2 py-1 text-xs leading-none transition-colors"
                    :class="article.is_pinned
                        ? 'border-wot-gold bg-wot-abyss/80 text-wot-gold'
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
                <a
                    :href="article.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="flex h-full flex-col border bg-wot-panel transition-colors hover:border-wot-gold"
                    :class="article.is_pinned ? 'border-wot-gold' : 'border-wot-border'"
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

        <nav v-if="articles.links.length > 3" class="mt-8 flex flex-wrap gap-1" aria-label="Pagination">
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
    </AppShell>
</template>
