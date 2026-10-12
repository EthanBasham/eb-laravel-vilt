<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppShell from '../Components/AppShell.vue';
import PageHeader from '../Components/PageHeader.vue';
import ArticleLink from '../Components/Calendar/ArticleLink.vue';
import DayDialog from '../Components/Calendar/DayDialog.vue';
import EventDateRange from '../Components/Calendar/EventDateRange.vue';
import EventDialog from '../Components/Calendar/EventDialog.vue';
import MonthGrid from '../Components/Calendar/MonthGrid.vue';

const props = defineProps({
    month: { type: String, required: true },
    monthLabel: { type: String, required: true },
    previousMonth: { type: String, required: true },
    nextMonth: { type: String, required: true },
    days: { type: Array, default: () => [] },
    spans: { type: Array, default: () => [] },
    ongoingEvents: { type: Array, default: () => [] },
    upcomingEvents: { type: Array, default: () => [] },
});

/* What each dialog is open for, and null when it is closed. */
const selectedEvent = ref(null);
const selectedDay = ref(null);

/* The long campaigns covering a day, looked up from the ids it carries. (for DayDialog) */
const ongoingEventsOn = (day) => props.ongoingEvents.filter((event) => day.ongoing_ids.includes(event.id));

/* Ignores/unignores an event. */
const toggleIgnore = (event) => {
    const options = { preserveScroll: true };

    event.is_ignored
        ? router.delete(`/wot/calendar/events/${event.id}/ignore`, options)
        : router.post(`/wot/calendar/events/${event.id}/ignore`, {}, options);
};

const monthLink = 'border border-wot-border px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-gold hover:text-wot-gold';
</script>

<template>
    <Head :title="`Calendar · ${monthLabel}`" />

    <AppShell>
        <PageHeader title="Events" subtitle="Dates published in official news articles.">
            <Link href="/wot/news" class="text-sm font-medium text-wot-gold hover:text-wot-gold-bright">
                Back to news &rarr;
            </Link>
        </PageHeader>

        <div class="mt-6 flex items-center justify-between gap-4">
            <Link :href="`/wot/calendar?month=${previousMonth}`" :class="monthLink">← Previous</Link>

            <h2 class="text-xl">{{ monthLabel }}</h2>

            <Link :href="`/wot/calendar?month=${nextMonth}`" :class="monthLink">Next →</Link>
        </div>

        <!-- "Running All Month" List -->
        <section v-if="ongoingEvents.length" class="mt-4" aria-labelledby="ongoing-heading">
            <h3 id="ongoing-heading" class="text-xs font-bold uppercase tracking-wider text-wot-dim">Running all month</h3>
            <ul role="list" class="mt-2 space-y-1">
                <li v-for="event in ongoingEvents" :key="event.id">
                    <button
                        type="button"
                        class="w-full border-l-2 border-wot-blue bg-wot-blue/10 px-3 py-1.5 text-left text-sm text-wot-blue-light transition-opacity hover:opacity-80"
                        @click="selectedEvent = event"
                    >
                        {{ event.title }}
                        <span class="ms-2 text-xs text-wot-dim">
                            <EventDateRange dates-only :event="event" />
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <MonthGrid
            class="mt-4"
            :days="days"
            :spans="spans"
            @select-day="selectedDay = $event"
            @select-event="selectedEvent = $event"
        />

        <p class="mt-3 flex flex-wrap gap-4 text-xs text-wot-dim">
            <span><span class="me-1 inline-block h-2 w-0.5 bg-wot-gold" />Gold edge: exact session times from an article's event calendar</span>
            <span><span class="me-1 inline-block h-2 w-0.5 bg-wot-blue" />Blue edge: overall event window (start and end only)</span>
        </p>

        <!-- "Coming Up" List -->
        <section v-if="upcomingEvents.length" class="mt-12" aria-labelledby="upcoming-heading">
            <h2 id="upcoming-heading" class="text-xl">Coming up</h2>

            <ul role="list" class="mt-4 divide-y divide-wot-border-soft border border-wot-border bg-wot-panel">
                <li v-for="event in upcomingEvents" :key="event.id" class="flex flex-wrap items-baseline justify-between gap-2 p-4">
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
                        @click="toggleIgnore(event)"
                    >
                        {{ event.is_ignored ? 'Reconsider' : 'Ignore' }}
                    </button>
                </li>
            </ul>
        </section>

        <DayDialog 
            :day="selectedDay" 
            :ongoing="selectedDay ? ongoingEventsOn(selectedDay) : []" 
            @close="selectedDay = null" 
        />
        <EventDialog 
            :event="selectedEvent" 
            @close="selectedEvent = null" 
        />
    </AppShell>
</template>
