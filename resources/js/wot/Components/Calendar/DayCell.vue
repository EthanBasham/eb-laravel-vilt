<script setup>
import { asDayLabel } from '../../lib/format';

/**
 * One square of the month grid: its date, and the click that opens its day.
 *
 * The square only draws the background. Its week row lays the events over it —
 * the bars that run across several squares and the sessions inside this one —
 * so this sits under every row of the week, and the square-wide button sits
 * *behind* the event buttons rather than wrapping them: a square that was
 * itself a button could not contain them, which is invalid markup.
 */
const props = defineProps({
    day: { type: Object, required: true },
});

defineEmits(['selectDay']);

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
    </div>
</template>
