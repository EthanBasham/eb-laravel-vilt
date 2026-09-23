<script setup>
import ArticleLink from './ArticleLink.vue';
import EventDateRange from './EventDateRange.vue';
import WotDialog from '../WotDialog.vue';
import { asDayLabel } from '../../lib/format';
import { eventBorderClass } from '../../lib/events';

/**
 * Everything happening on one square, with the times and rewards the square
 * itself has no room for.
 *
 * The long campaigns the grid deliberately leaves out are listed at the foot.
 * They are running on this day too, and a listing that omitted them would be
 * answering a different question than the one the square was clicked to ask.
 */
defineProps({
    day: { type: Object, default: null },
    // The campaigns covering this day, resolved by the page from the ids the
    // square carries.
    ongoing: { type: Array, default: () => [] },
});

defineEmits(['close']);
</script>

<template>
    <WotDialog wide :open="Boolean(day)" :label="day ? asDayLabel(day.date) : ''" @close="$emit('close')">
        <template #default="{ close }">
            <h3 class="text-lg normal-case tracking-normal text-wot-heading">
                {{ asDayLabel(day.date) }}
            </h3>

            <ul v-if="day.events.length" role="list" class="mt-4 space-y-3">
                <li
                    v-for="event in day.events"
                    :key="event.id"
                    class="border-l-2 ps-3"
                    :class="eventBorderClass(event)"
                >
                    <p class="flex flex-wrap items-baseline gap-x-2">
                        <span class="text-wot-heading">{{ event.title }}</span>
                        <span v-if="event.is_final_day" class="text-xs font-bold uppercase tracking-wider text-wot-bad">
                            Final day
                        </span>
                    </p>

                    <p class="mt-1 text-xs text-wot-dim">
                        <EventDateRange :event="event" />
                    </p>

                    <ul v-if="event.metadata?.rewards?.length" role="list" class="mt-1 text-sm text-wot-muted">
                        <li v-for="(reward, index) in event.metadata.rewards" :key="index">&bull; {{ reward }}</li>
                    </ul>

                    <ArticleLink
                        v-if="event.article?.url"
                        :url="event.article.url"
                        class="mt-1 inline-block text-xs text-wot-gold hover:text-wot-gold-bright"
                    />
                </li>
            </ul>

            <template v-if="ongoing.length">
                <h4 class="mt-5 text-xs font-bold uppercase tracking-wider text-wot-dim">Also running</h4>

                <ul role="list" class="mt-2 space-y-2">
                    <li v-for="event in ongoing" :key="event.id" class="border-l-2 border-wot-blue ps-3">
                        <p class="text-wot-blue-light">{{ event.title }}</p>
                        <p class="mt-0.5 text-xs text-wot-dim">
                            <EventDateRange dates-only :event="event" />
                        </p>
                    </li>
                </ul>
            </template>

            <div class="mt-6 flex justify-end">
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
