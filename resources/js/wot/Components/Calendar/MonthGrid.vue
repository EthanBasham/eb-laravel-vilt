<script setup>
import DayCell from './DayCell.vue';

/**
 * The month, as seven columns of squares.
 *
 * `days` arrives already padded out to whole weeks by the server, each square
 * carrying whether it falls in the month being shown — so the grid never has to
 * work out where a month starts, and a leading Sunday is a square rather than a
 * gap.
 *
 * Monday first, and the labels are fixed English rather than localised: the
 * game's own week and every reset time it publishes run Monday to Sunday.
 */
defineProps({
    days: { type: Array, required: true },
});

defineEmits(['selectDay', 'selectEvent']);

const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
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

            <div class="grid grid-cols-7">
                <DayCell
                    v-for="day in days"
                    :key="day.date"
                    :day="day"
                    @select-day="$emit('selectDay', $event)"
                    @select-event="$emit('selectEvent', $event)"
                />
            </div>
        </div>
    </div>
</template>
