<script setup>
import { asDate } from '../../lib/format';

defineProps({
    article: { type: Object, required: true },
    isNew: { type: Boolean, default: false },
});

defineEmits(['togglePin']);
</script>

<template>
    <!-- Sits opposite the pin so the two never collide on a card that is both
         new and pinned. -->
    <span
        v-if="isNew"
        class="absolute left-2 top-2 z-10 flex items-center gap-1 border border-wot-good bg-wot-good/20 px-1.5 py-0.5 text-xs font-bold uppercase tracking-wider text-wot-good backdrop-blur-sm"
    >
        <span class="h-1.5 w-1.5 shrink-0 bg-wot-good" aria-hidden="true" />

        New
    </span>

    <button
        type="button"
        class="absolute right-2 top-2 z-10 border px-2 py-1 text-xs leading-none transition-colors"
        :class="article.is_pinned
            ? 'border-wot-gold bg-wot-gold/45 text-wot-abyss backdrop-blur-sm'
            : 'border-wot-border bg-wot-abyss/70 text-wot-dim hover:border-wot-gold hover:text-wot-gold'"
        :aria-pressed="article.is_pinned"
        :title="article.is_pinned ? 'Unpin from your feed' : 'Pin to the top of your feed'"
        @click="$emit('togglePin')"
    >
        <span aria-hidden="true">📌</span>
        <span class="sr-only">{{ article.is_pinned ? 'Unpin' : 'Pin' }} {{ article.title }}</span>
    </button>

    <!-- A real external link: these open the article on worldoftanks.com, which
         is outside this SPA.

         The border stays a hover affordance only. Using it for pinned state too
         would make a resting card look identical to a hovered one, so the pin
         button carries that state. -->
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
</template>
