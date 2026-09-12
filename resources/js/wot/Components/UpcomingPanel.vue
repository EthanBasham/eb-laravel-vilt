<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    upcoming: { type: Object, required: true },
});

const asDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : null);
</script>

<template>
    <section class="flex flex-col border border-wot-border bg-wot-panel" aria-labelledby="upcoming-panel-heading">
        <div class="flex items-center justify-between gap-3 border-b border-wot-border px-4 py-3">
            <h2 id="upcoming-panel-heading" class="text-base">Next 5 days</h2>
            <Link href="/wot/calendar" class="text-xs font-medium text-wot-gold hover:text-wot-gold-bright">
                Calendar &rarr;
            </Link>
        </div>

        <ul role="list" class="flex-1 divide-y divide-wot-border-soft">
            <li
                v-for="day in upcoming.days"
                :key="day.date"
                class="flex gap-3 px-4 py-2.5"
                :class="day.is_today ? 'bg-wot-sunken' : ''"
            >
                <span
                    class="w-20 shrink-0 text-xs font-bold uppercase tracking-wider"
                    :class="day.is_today ? 'text-wot-gold' : 'text-wot-dim'"
                >
                    {{ day.label }}
                </span>

                <span class="min-w-0 flex-1">
                    <span v-if="!day.events.length" class="text-sm text-wot-dim">—</span>

                    <span v-else class="flex flex-col gap-1">
                        <a
                            v-for="event in day.events"
                            :key="event.id"
                            :href="event.url ?? '/wot/calendar'"
                            :target="event.url ? '_blank' : undefined"
                            :rel="event.url ? 'noopener noreferrer' : undefined"
                            class="block border-l-2 ps-2 text-sm transition-opacity hover:opacity-80"
                            :class="event.source === 'calendar'
                                ? 'border-wot-gold text-wot-gold'
                                : 'border-wot-blue text-wot-blue-light'"
                        >
                            <span class="flex items-start gap-1">
                                <span class="truncate">
                                    <span v-if="event.time" class="font-bold tabular-nums">{{ event.time }}</span>
                                    {{ event.title }}
                                </span>

                                <!-- shrink-0 so the title truncates before the
                                     marker does — one that disappears on the
                                     longer titles would be worse than none.
                                     <sup> comes with 75% and top: -0.5em from
                                     preflight; both are overridden here. -->
                                <sup
                                    v-if="event.is_final_day"
                                    class="shrink-0 top-[0.05em] text-[length:60%] font-bold uppercase tracking-wider text-wot-bad"
                                >
                                    Final
                                </sup>
                            </span>
                        </a>
                    </span>
                </span>
            </li>
        </ul>

        <!--
            Campaigns running for weeks are summarised rather than repeated in
            every day above, where they would bury the handful of things
            actually scheduled.
        -->
        <div v-if="upcoming.ongoing.length" class="border-t border-wot-border px-4 py-2.5">
            <p class="text-xs font-bold uppercase tracking-wider text-wot-dim">Also running</p>
            <ul role="list" class="mt-1 space-y-0.5">
                <li v-for="event in upcoming.ongoing" :key="event.id" class="truncate text-xs text-wot-muted">
                    {{ event.title }}
                    <span v-if="event.ends_at" class="text-wot-dim">· until {{ asDate(event.ends_at) }}</span>
                </li>
            </ul>
        </div>
    </section>
</template>
