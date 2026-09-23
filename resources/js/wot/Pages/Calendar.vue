<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppShell from '../Components/AppShell.vue';
import PageHeader from '../Components/PageHeader.vue';
import DayDialog from '../Components/Calendar/DayDialog.vue';
import EventDateRange from '../Components/Calendar/EventDateRange.vue';
import EventDialog from '../Components/Calendar/EventDialog.vue';
import MonthGrid from '../Components/Calendar/MonthGrid.vue';
import UpcomingList from '../Components/Calendar/UpcomingList.vue';

const props = defineProps({
    month: { type: String, required: true },
    monthLabel: { type: String, required: true },
    previousMonth: { type: String, required: true },
    nextMonth: { type: String, required: true },
    days: { type: Array, default: () => [] },
    ongoing: { type: Array, default: () => [] },
    upcoming: { type: Array, default: () => [] },
});

/*
 * What each dialog is open for, and null when it is closed. The dialogs take it
 * as their `open` too, so clearing it is the whole of closing one — see
 * WotDialog for why opening is not just an attribute.
 */
const selectedEvent = ref(null);
const selectedDay = ref(null);

/** The long campaigns covering a day, looked up from the ids it carries. */
const ongoingOn = (day) => props.ongoing.filter((event) => day.ongoing_ids.includes(event.id));

/**
 * A full reload rather than a partial: ignoring an event changes the grid, the
 * long-campaign list and this listing at once, which is every prop the page
 * has. preserveScroll because the control is at the foot of a long page.
 */
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

        <!-- Long campaigns live here rather than in the grid: a three-month
             Battle Pass repeated in every square buries the sessions that
             actually have times. -->
        <section v-if="ongoing.length" class="mt-4" aria-labelledby="ongoing-heading">
            <h3 id="ongoing-heading" class="text-xs font-bold uppercase tracking-wider text-wot-dim">Running all month</h3>
            <ul role="list" class="mt-2 space-y-1">
                <li v-for="event in ongoing" :key="event.id">
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
            @select-day="selectedDay = $event"
            @select-event="selectedEvent = $event"
        />

        <p class="mt-3 flex flex-wrap gap-4 text-xs text-wot-dim">
            <span><span class="me-1 inline-block h-2 w-2 bg-wot-gold" />Exact session times from an article's event calendar</span>
            <span><span class="me-1 inline-block h-2 w-2 bg-wot-blue" />Overall event window (start and end only)</span>
            <span>Campaigns longer than a week are listed above the grid rather than filling every day.</span>
        </p>

        <section v-if="upcoming.length" class="mt-12" aria-labelledby="upcoming-heading">
            <h2 id="upcoming-heading" class="text-xl">Coming up</h2>

            <UpcomingList :events="upcoming" @toggle-ignore="toggleIgnore" />
        </section>

        <DayDialog
            :day="selectedDay"
            :ongoing="selectedDay ? ongoingOn(selectedDay) : []"
            @close="selectedDay = null"
        />

        <EventDialog :event="selectedEvent" @close="selectedEvent = null" />
    </AppShell>
</template>
