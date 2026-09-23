<script setup>
import { asDayLabel } from '../../lib/format';
import { eventBarClass } from '../../lib/events';

/**
 * One square of the month grid.
 *
 * The square-wide button sits *behind* the event bars rather than wrapping
 * them: a square that was itself a button could not contain the per-event
 * buttons, which is invalid markup. The overlay fills the square and everything
 * else sits above it, so each bar keeps its own click.
 */
const props = defineProps({
    day: { type: Object, required: true },
});

defineEmits(['selectDay', 'selectEvent']);

/** A square worth opening: an empty day view would say nothing. */
const hasAnything = (day) => day.events.length > 0 || day.ongoing_ids.length > 0;

const openable = () => hasAnything(props.day);
</script>

<template>
    <div
        class="relative min-h-28 border-b border-r border-wot-border-soft p-1.5"
        :class="[
            day.in_month ? 'bg-wot-panel' : 'bg-wot-abyss/40',
            day.is_today ? 'ring-1 ring-inset ring-wot-gold' : '',
        ]"
    >
        <button
            v-if="openable()"
            type="button"
            class="absolute inset-0 transition-colors hover:bg-wot-gold/5"
            @click="$emit('selectDay', day)"
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
                    :class="eventBarClass(event)"
                    @click="$emit('selectEvent', event)"
                >
                    <span class="block truncate">
                        <span v-if="event.time" class="font-bold tabular-nums">{{ event.time }}</span>
                        {{ event.title }}
                    </span>

                    <!-- Its own line rather than appended to the title, which
                         truncates — a marker that disappears on the longer
                         titles would be worse than none. -->
                    <span v-if="event.is_final_day" class="block font-bold uppercase tracking-wider text-wot-bad">
                        Final day
                    </span>
                </button>
            </li>
        </ul>
    </div>
</template>
