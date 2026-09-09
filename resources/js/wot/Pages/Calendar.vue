<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppShell from '../Components/AppShell.vue';

defineProps({
    month: { type: String, required: true },
    monthLabel: { type: String, required: true },
    previousMonth: { type: String, required: true },
    nextMonth: { type: String, required: true },
    days: { type: Array, default: () => [] },
    ongoing: { type: Array, default: () => [] },
    upcoming: { type: Array, default: () => [] },
});

const selected = ref(null);

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
                        class="min-h-28 border-b border-r border-wot-border-soft p-1.5"
                        :class="[
                            day.in_month ? 'bg-wot-panel' : 'bg-wot-abyss/40',
                            day.is_today ? 'ring-1 ring-inset ring-wot-gold' : '',
                        ]"
                    >
                        <p class="text-xs tabular-nums" :class="day.in_month ? 'text-wot-muted' : 'text-wot-dim'">
                            {{ day.day }}
                        </p>

                        <ul role="list" class="mt-1 space-y-1">
                            <li v-for="event in day.events" :key="event.id">
                                <button
                                    type="button"
                                    class="w-full truncate px-1.5 py-0.5 text-left text-xs transition-opacity hover:opacity-80"
                                    :class="eventClass(event)"
                                    @click="selected = event"
                                >
                                    <span v-if="event.time" class="font-bold tabular-nums">{{ event.time }}</span>
                                    {{ event.title }}
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
                    <div>
                        <p class="text-wot-heading">{{ event.title }}</p>
                        <p class="mt-1 text-xs text-wot-dim">
                            {{ event.source === 'calendar' ? asDateTime(event.starts_at) : asDate(event.starts_at) }}
                            <template v-if="event.ends_at">
                                &ndash; {{ event.source === 'calendar' ? asDateTime(event.ends_at) : asDate(event.ends_at) }}
                            </template>
                        </p>
                    </div>

                    <a
                        v-if="event.article?.url"
                        :href="event.article.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-xs text-wot-gold hover:text-wot-gold-bright"
                    >
                        Read article &rarr;
                    </a>
                </li>
            </ul>
        </section>

        <!-- Native <dialog> for the same reason the rest of the app uses one:
             the platform supplies the focus trap, Escape handling and backdrop. -->
        <dialog
            v-if="selected"
            ref="detail"
            class="modal"
            open
            :aria-label="selected.title"
            @click.self="selected = null"
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
                        @click="selected = null"
                    >
                        Close
                    </button>
                </div>
            </div>
        </dialog>
    </AppShell>
</template>
