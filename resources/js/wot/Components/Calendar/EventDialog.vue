<script setup>
import ArticleLink from './ArticleLink.vue';
import EventDateRange from './EventDateRange.vue';
import WotDialog from '../WotDialog.vue';

/**
 * One event, opened from a bar in the grid or from the list above it.
 *
 * The source is named outright here — "(calendar)" or "(window)" — where the
 * grid only draws it as a colour. This is the view you open when the colour is
 * not enough, so the thing the colour stands for is spelled out.
 */
defineProps({
    event: { type: Object, default: null },
});

defineEmits(['close']);
</script>

<template>
    <WotDialog :open="Boolean(event)" :label="event?.title" @close="$emit('close')">
        <template #default="{ close }">
            <h3 class="text-lg normal-case tracking-normal text-wot-heading">{{ event.title }}</h3>

            <p class="mt-2 text-sm text-wot-muted">
                <EventDateRange :event="event" />
                <span class="ms-2 text-xs uppercase tracking-wider text-wot-dim">({{ event.source }})</span>
            </p>

            <p v-if="event.is_final_day" class="mt-2 border-l-2 border-wot-bad ps-2 text-xs font-bold uppercase tracking-wider text-wot-bad">
                Final day
            </p>

            <ul v-if="event.metadata?.rewards?.length" role="list" class="mt-4 space-y-1 text-sm text-wot-muted">
                <li v-for="(reward, index) in event.metadata.rewards" :key="index">• {{ reward }}</li>
            </ul>

            <div class="mt-6 flex flex-wrap justify-end gap-3">
                <ArticleLink
                    v-if="event.article?.url"
                    :url="event.article.url"
                    class="border border-wot-border px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-muted hover:border-wot-gold hover:text-wot-gold"
                >
                    Read article
                </ArticleLink>
                <button
                    type="button"
                    class="border border-wot-gold bg-wot-gold px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-abyss"
                    @click="close"
                >
                    Close
                </button>
            </div>
        </template>
    </WotDialog>
</template>
