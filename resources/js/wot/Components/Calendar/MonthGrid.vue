<script setup>
import { computed } from 'vue';
import WeekRow from './WeekRow.vue';

/**
 * The month, as seven columns of squares.
 *
 * `days` arrives already padded out to whole weeks by the server, each square
 * carrying whether it falls in the month being shown — so the grid never has to
 * work out where a month starts, and the days either side of it are squares
 * rather than gaps.
 *
 * Sunday first, and the labels are fixed English rather than localised. The
 * server decides where the week starts; these labels must match it.
 */
const props = defineProps({
    days: { type: Array, required: true },
    // The multi-day bars, each tagged with the week row it is drawn in.
    spans: { type: Array, default: () => [] },
});

defineEmits(['selectDay', 'selectEvent']);

const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** The days in rows of seven, each with the bars drawn across it. */
const weeks = computed(() => Array.from({ length: Math.ceil(props.days.length / 7) }, (_, week) => ({
    days: props.days.slice(week * 7, week * 7 + 7),
    spans: props.spans.filter((span) => span.week === week),
})));
</script>

<template>
    <div class="overflow-x-auto">
        <div class="min-w-3xl">
            <div class="grid grid-cols-7 border-b border-wot-border">
                <div
                    v-for="day in weekdays"
                    :key="day"
                    class="px-2 py-2 text-center text-xs font-bold uppercase tracking-wider text-wot-dim"
                >
                    {{ day }}
                </div>
            </div>

            <WeekRow
                v-for="week in weeks"
                :key="week.days[0].date"
                :days="week.days"
                :spans="week.spans"
                @select-day="$emit('selectDay', $event)"
                @select-event="$emit('selectEvent', $event)"
            />
        </div>
    </div>
</template>
