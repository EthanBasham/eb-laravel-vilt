<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppShell from '../Components/AppShell.vue';

defineProps({
    articles: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    activeCategory: { type: String, default: null },
});

const filterBy = (category) => {
    router.get('/wot/news', category ? { category } : {}, { preserveScroll: true });
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
        </div>

        <ul role="list" class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <li v-for="article in articles.data" :key="article.id">
                <!-- A real external link: these open the article on
                     worldoftanks.com, which is outside this SPA. -->
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
