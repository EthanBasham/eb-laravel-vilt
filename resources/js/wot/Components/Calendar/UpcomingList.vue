<script setup>
import ArticleLink from './ArticleLink.vue';
import EventDateRange from './EventDateRange.vue';

/**
 * What is coming, as a list rather than a grid — including the events that have
 * been ignored.
 *
 * An ignored event stays listed but reads as struck from the schedule, so the
 * row itself says why it is missing from the grid above. Ignoring is
 * reversible and the only place to reverse it is here, which is the whole
 * reason the row is still drawn.
 */
defineProps({
    events: { type: Array, required: true },
});

defineEmits(['toggleIgnore']);
</script>

<template>
    <ul role="list" class="mt-4 divide-y divide-wot-border-soft border border-wot-border bg-wot-panel">
        <li v-for="event in events" :key="event.id" class="flex flex-wrap items-baseline justify-between gap-2 p-4">
            <div :class="event.is_ignored ? 'opacity-50' : ''">
                <p :class="event.is_ignored ? 'text-wot-dim line-through' : 'text-wot-heading'">
                    {{ event.title }}
                </p>

                <p class="mt-1 flex flex-wrap items-baseline gap-x-3 text-xs text-wot-dim">
                    <EventDateRange :event="event" />

                    <ArticleLink
                        v-if="event.article?.url"
                        :url="event.article.url"
                        class="text-wot-gold hover:text-wot-gold-bright"
                    />
                </p>
            </div>

            <button
                type="button"
                class="text-xs transition-colors"
                :class="event.is_ignored
                    ? 'text-wot-gold hover:text-wot-gold-bright'
                    : 'text-wot-dim hover:text-wot-bad'"
                @click="$emit('toggleIgnore', event)"
            >
                {{ event.is_ignored ? 'Reconsider' : 'Ignore' }}
            </button>
        </li>
    </ul>
</template>
