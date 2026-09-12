<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { nextTick, ref, watch } from 'vue';
import AppShell from '../Components/AppShell.vue';

const props = defineProps({
    month: { type: String, required: true },
    monthLabel: { type: String, required: true },
    previousMonth: { type: String, required: true },
    nextMonth: { type: String, required: true },
    days: { type: Array, default: () => [] },
    ongoing: { type: Array, default: () => [] },
    upcoming: { type: Array, default: () => [] },
});

const selected = ref(null);
const detail = ref(null);

/**
 * showModal() rather than the `open` attribute, the same reason app.js gives
 * for the Blade modals: `open` renders the dialog in normal flow, so it lands
 * at the foot of the page with no backdrop and no focus trap. showModal() puts
 * it in the top layer, where the browser centres it and makes the page behind
 * it inert.
 *
 * nextTick because the element is v-if'd on the same ref this watches — it does
 * not exist yet when the watcher fires.
 */
watch(selected, async (event) => {
    await nextTick();

    if (event) {
        detail.value?.showModal();
    }
});

const selectedDay = ref(null);
const dayDetail = ref(null);

watch(selectedDay, async (day) => {
    await nextTick();

    if (day) {
        dayDetail.value?.showModal();
    }
});

const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// Only 'calendar' events carry real times; 'window' events are a coarse span
// derived from a pair of timestamps, and are drawn differently so the
// difference in confidence is visible rather than implied.
const eventClass = (event) =>
    event.source === 'calendar'
        ? 'border-l-2 border-wot-gold bg-wot-gold/10 text-wot-gold'
        : 'border-l-2 border-wot-blue bg-wot-blue/10 text-wot-blue-light';

const asDateTime = (iso) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
const asDate = (iso) => new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' });

/**
 * Built from the parts rather than `new Date('2026-09-11')`, which the spec
 * parses as UTC midnight — west of Greenwich that renders as the day before.
 */
const asDayLabel = (date) => {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, { dateStyle: 'full' });
};

/** The long campaigns covering a day, looked up from the ids it carries. */
const ongoingOn = (day) => props.ongoing.filter((event) => day.ongoing_ids.includes(event.id));

/** A square worth opening: an empty day view would say nothing. */
const hasAnything = (day) => day.events.length > 0 || day.ongoing_ids.length > 0;

/**
 * A full reload rather than a partial: ignoring an event changes the grid, the
 * long-campaign list and this listing at once, which is every prop the page
 * has. preserveScroll because the control is at the foot of a long page.
 */
const toggleIgnore = (event) => {
    const options = { preserveScroll: true };

    event.is_ignored
        ? router.delete(`/wot/events/${event.id}/ignore`, options)
        : router.post(`/wot/events/${event.id}/ignore`, {}, options);
};
</script>

<template>
    <Head :title="`Calendar · ${monthLabel}`" />

    <AppShell>
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-wot-border pb-5">
            <div>
                <h1 class="text-3xl">Events</h1>
                <p class="mt-1 text-sm text-wot-dim">Dates published in official news articles.</p>
            </div>

            <Link href="/wot/news" class="text-sm font-medium text-wot-gold hover:text-wot-gold-bright">
                Back to news &rarr;
            </Link>
        </div>

        <div class="mt-6 flex items-center justify-between gap-4">
            <Link
                :href="`/wot/calendar?month=${previousMonth}`"
                class="border border-wot-border px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-gold hover:text-wot-gold"
            >
                ← Previous
            </Link>

            <h2 class="text-xl">{{ monthLabel }}</h2>

            <Link
                :href="`/wot/calendar?month=${nextMonth}`"
                class="border border-wot-border px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-gold hover:text-wot-gold"
            >
                Next →
            </Link>
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
                        @click="selected = event"
                    >
                        {{ event.title }}
                        <span class="ms-2 text-xs text-wot-dim">
                            {{ asDate(event.starts_at) }}<template v-if="event.ends_at"> – {{ asDate(event.ends_at) }}</template>
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <div class="mt-4 overflow-x-auto">
            <div class="min-w-3xl">
                <div class="grid grid-cols-7 border-b border-wot-border">
                    <div v-for="day in weekdays" :key="day" class="px-2 py-2 text-center text-xs font-bold uppercase tracking-wider text-wot-dim">
                        {{ day }}
                    </div>
                </div>

                <div class="grid grid-cols-7">
                    <div
                        v-for="day in days"
                        :key="day.date"
                        class="relative min-h-28 border-b border-r border-wot-border-soft p-1.5"
                        :class="[
                            day.in_month ? 'bg-wot-panel' : 'bg-wot-abyss/40',
                            day.is_today ? 'ring-1 ring-inset ring-wot-gold' : '',
                        ]"
                    >
                        <!-- Behind the event bars rather than wrapping them: a
                             square that was itself a button could not contain
                             the per-event buttons, which is invalid markup. The
                             overlay fills the square and everything else sits
                             above it, so each bar keeps its own click. -->
                        <button
                            v-if="hasAnything(day)"
                            type="button"
                            class="absolute inset-0 transition-colors hover:bg-wot-gold/5"
                            @click="selectedDay = day"
                        >
                            <span class="sr-only">All events on {{ asDayLabel(day.date) }}</span>
                        </button>

                        <p class="relative text-xs tabular-nums" :class="day.in_month ? 'text-wot-muted' : 'text-wot-dim'">
                            {{ day.day }}
                        </p>

                        <ul role="list" class="relative mt-1 space-y-1">
                            <li v-for="event in day.events" :key="event.id">
                                <button
                                    type="button"
                                    class="w-full px-1.5 py-0.5 text-left text-xs transition-opacity hover:opacity-80"
                                    :class="eventClass(event)"
                                    @click="selected = event"
                                >
                                    <span class="block truncate">
                                        <span v-if="event.time" class="font-bold tabular-nums">{{ event.time }}</span>
                                        {{ event.title }}
                                    </span>

                                    <!-- Its own line rather than appended to the
                                         title, which truncates — a marker that
                                         disappears on the longer titles would be
                                         worse than none. -->
                                    <span v-if="event.is_final_day" class="block font-bold uppercase tracking-wider text-wot-bad">
                                        Final day
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <p class="mt-3 flex flex-wrap gap-4 text-xs text-wot-dim">
            <span><span class="me-1 inline-block h-2 w-2 bg-wot-gold" />Exact session times from an article's event calendar</span>
            <span><span class="me-1 inline-block h-2 w-2 bg-wot-blue" />Overall event window (start and end only)</span>
            <span>Campaigns longer than a week are listed above the grid rather than filling every day.</span>
        </p>

        <section v-if="upcoming.length" class="mt-12" aria-labelledby="upcoming-heading">
            <h2 id="upcoming-heading" class="text-xl">Coming up</h2>

            <ul role="list" class="mt-4 divide-y divide-wot-border-soft border border-wot-border bg-wot-panel">
                <li v-for="event in upcoming" :key="event.id" class="flex flex-wrap items-baseline justify-between gap-2 p-4">
                    <!-- An ignored event stays listed but reads as struck from
                         the schedule, so the row itself says why it is missing
                         from the grid above. -->
                    <div :class="event.is_ignored ? 'opacity-50' : ''">
                        <p :class="event.is_ignored ? 'text-wot-dim line-through' : 'text-wot-heading'">
                            {{ event.title }}
                        </p>

                        <p class="mt-1 flex flex-wrap items-baseline gap-x-3 text-xs text-wot-dim">
                            <span>
                                {{ event.source === 'calendar' ? asDateTime(event.starts_at) : asDate(event.starts_at) }}
                                <template v-if="event.ends_at">
                                    &ndash; {{ event.source === 'calendar' ? asDateTime(event.ends_at) : asDate(event.ends_at) }}
                                </template>
                            </span>

                            <a
                                v-if="event.article?.url"
                                :href="event.article.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-wot-gold hover:text-wot-gold-bright"
                            >
                                Read article &rarr;
                            </a>
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

        <!-- Everything happening on one square, with the times and rewards the
             square itself has no room for. -->
        <dialog
            v-if="selectedDay"
            ref="dayDetail"
            class="modal modal--dark modal--wide"
            :aria-label="asDayLabel(selectedDay.date)"
            @click.self="dayDetail.close()"
            @close="selectedDay = null"
        >
            <div class="border border-wot-border bg-wot-panel-solid p-6">
                <h3 class="text-lg normal-case tracking-normal text-wot-heading">
                    {{ asDayLabel(selectedDay.date) }}
                </h3>

                <ul v-if="selectedDay.events.length" role="list" class="mt-4 space-y-3">
                    <li
                        v-for="event in selectedDay.events"
                        :key="event.id"
                        class="border-l-2 ps-3"
                        :class="event.source === 'calendar' ? 'border-wot-gold' : 'border-wot-blue'"
                    >
                        <p class="flex flex-wrap items-baseline gap-x-2">
                            <span class="text-wot-heading">{{ event.title }}</span>
                            <span v-if="event.is_final_day" class="text-xs font-bold uppercase tracking-wider text-wot-bad">
                                Final day
                            </span>
                        </p>

                        <p class="mt-1 text-xs text-wot-dim">
                            {{ event.source === 'calendar' ? asDateTime(event.starts_at) : asDate(event.starts_at) }}
                            <template v-if="event.ends_at">
                                &ndash; {{ event.source === 'calendar' ? asDateTime(event.ends_at) : asDate(event.ends_at) }}
                            </template>
                        </p>

                        <ul v-if="event.metadata?.rewards?.length" role="list" class="mt-1 text-sm text-wot-muted">
                            <li v-for="(reward, i) in event.metadata.rewards" :key="i">&bull; {{ reward }}</li>
                        </ul>

                        <a
                            v-if="event.article?.url"
                            :href="event.article.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-1 inline-block text-xs text-wot-gold hover:text-wot-gold-bright"
                        >
                            Read article &rarr;
                        </a>
                    </li>
                </ul>

                <!-- The campaigns the grid deliberately leaves out. They are
                     running on this day too, and a listing that omitted them
                     would be answering a different question than the one the
                     square was clicked to ask. -->
                <template v-if="ongoingOn(selectedDay).length">
                    <h4 class="mt-5 text-xs font-bold uppercase tracking-wider text-wot-dim">Also running</h4>

                    <ul role="list" class="mt-2 space-y-2">
                        <li v-for="event in ongoingOn(selectedDay)" :key="event.id" class="border-l-2 border-wot-blue ps-3">
                            <p class="text-wot-blue-light">{{ event.title }}</p>
                            <p class="mt-0.5 text-xs text-wot-dim">
                                {{ asDate(event.starts_at) }}<template v-if="event.ends_at"> &ndash; {{ asDate(event.ends_at) }}</template>
                            </p>
                        </li>
                    </ul>
                </template>

                <div class="mt-6 flex justify-end">
                    <button
                        type="button"
                        class="border border-wot-gold bg-wot-gold px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-abyss"
                        @click="dayDetail.close()"
                    >
                        Close
                    </button>
                </div>
            </div>
        </dialog>

        <!-- Native <dialog> for the same reason the rest of the app uses one:
             the platform supplies the focus trap, Escape handling and backdrop. -->
        <dialog
            v-if="selected"
            ref="detail"
            class="modal modal--dark"
            :aria-label="selected.title"
            @click.self="detail.close()"
            @close="selected = null"
        >
            <div class="border border-wot-border bg-wot-panel-solid p-6">
                <h3 class="text-lg normal-case tracking-normal text-wot-heading">{{ selected.title }}</h3>

                <p class="mt-2 text-sm text-wot-muted">
                    {{ selected.source === 'calendar' ? asDateTime(selected.starts_at) : asDate(selected.starts_at) }}
                    <template v-if="selected.ends_at">
                        &ndash; {{ selected.source === 'calendar' ? asDateTime(selected.ends_at) : asDate(selected.ends_at) }}
                    </template>
                    <span class="ms-2 text-xs uppercase tracking-wider text-wot-dim">({{ selected.source }})</span>
                </p>

                <p v-if="selected.is_final_day" class="mt-2 border-l-2 border-wot-bad ps-2 text-xs font-bold uppercase tracking-wider text-wot-bad">
                    Final day
                </p>

                <ul v-if="selected.metadata?.rewards?.length" role="list" class="mt-4 space-y-1 text-sm text-wot-muted">
                    <li v-for="(reward, i) in selected.metadata.rewards" :key="i">• {{ reward }}</li>
                </ul>

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <a
                        v-if="selected.article?.url"
                        :href="selected.article.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="border border-wot-border px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-muted hover:border-wot-gold hover:text-wot-gold"
                    >
                        Read article
                    </a>
                    <button
                        type="button"
                        class="border border-wot-gold bg-wot-gold px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-abyss"
                        @click="detail.close()"
                    >
                        Close
                    </button>
                </div>
            </div>
        </dialog>
    </AppShell>
</template>
