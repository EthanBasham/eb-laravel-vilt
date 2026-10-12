<script setup>
import { computed } from 'vue';
import DayCell from './DayCell.vue';
import { eventBorderClass, eventColorStyle } from '../../lib/events';

/**
 * One week of the month: seven squares, with the events laid over them.
 *
 * The row is a grid of its own. The squares fill every row of it as the
 * background; the first row is left for their dates, then one row per lane of
 * multi-day bars, then a last row where each square lists its single-day
 * sessions. A bar is placed by column and lane, both decided by the server
 * (CalendarBoard::spans()), so it crosses the squares it covers and its label
 * is written once per week rather than once per day.
 */
const props = defineProps({
    days: { type: Array, required: true },
    spans: { type: Array, default: () => [] },
});

defineEmits(['selectDay', 'selectEvent']);

const lanes = computed(() => props.spans.reduce((most, span) => Math.max(most, span.lane + 1), 0));

/*
 * `repeat(0, auto)` is invalid, and an invalid template drops the whole
 * declaration — so the lane rows are only written when there are lanes.
 */
const rowTemplate = computed(() => ['1.75rem', lanes.value ? `repeat(${lanes.value}, auto)` : null, '1fr']
    .filter(Boolean)
    .join(' '));

/** A square's own sessions: everything on it that is not already a bar. */
const singleDayEvents = (day) => day.events.filter((event) => !event.spans_days);

/*
 * A bar that carries on from last week has no start to mark, so it loses the
 * rule down its left edge, and both of a carried-on bar's ends run to the
 * square's edge so it reads as continuing past it.
 */
const spanClass = (span) => [
    span.continues_before ? '' : ['border-l-2', eventBorderClass(span.event)],
    span.continues_before ? '' : 'ms-1.5',
    span.continues_after ? '' : 'me-1.5',
];
</script>

<template>
    <div class="grid grid-cols-7" :style="{ gridTemplateRows: rowTemplate }">
        <DayCell
            v-for="(day, index) in days"
            :key="day.date"
            :day="day"
            :style="{ gridColumn: index + 1, gridRow: '1 / -1' }"
            @select-day="$emit('selectDay', $event)"
        />

        <button
            v-for="span in spans"
            :key="span.event.id"
            type="button"
            class="relative mt-1 px-1.5 py-0.5 text-left text-xs transition-opacity hover:opacity-80"
            :class="spanClass(span)"
            :style="{ ...eventColorStyle(span.event), gridColumn: `${span.column} / span ${span.length}`, gridRow: span.lane + 2 }"
            @click="$emit('selectEvent', span.event)"
        >
            <span class="block truncate">
                <span v-if="span.continues_before" aria-hidden="true">&lsaquo; </span>
                <span v-if="span.time" class="font-bold tabular-nums">{{ span.time }}</span>
                {{ span.event.title }}
            </span>

            <!-- No "Final day" marker for now: a second line made the lanes
                 uneven. The server still sends span.is_final_day for it. -->
        </button>

        <ul
            v-for="(day, index) in days"
            :key="`${day.date}-sessions`"
            role="list"
            class="pointer-events-none relative space-y-1 px-1.5 pt-1 pb-1.5"
            :style="{ gridColumn: index + 1, gridRow: lanes + 2 }"
        >
            <li v-for="event in singleDayEvents(day)" :key="event.id">
                <button
                    type="button"
                    class="pointer-events-auto w-full px-1.5 py-0.5 text-left text-xs transition-opacity hover:opacity-80"
                    :class="['border-l-2', eventBorderClass(event)]"
                    :style="eventColorStyle(event)"
                    @click="$emit('selectEvent', event)"
                >
                    <span class="block truncate">
                        <span v-if="event.time" class="font-bold tabular-nums">{{ event.time }}</span>
                        {{ event.title }}
                    </span>
                </button>
            </li>
        </ul>
    </div>
</template>
