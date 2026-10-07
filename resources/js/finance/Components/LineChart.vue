<script setup>
import { computed, ref } from 'vue';
import { useChartWidth } from '../composables/useChartWidth';
import { moneyShort } from '../lib/format';
import { niceScale } from '../lib/scale';

/**
 * The one line chart: change over time, for one series or a few.
 *
 * Drawn by hand in SVG — there is no charting library in this project. The
 * figures arrive finished from the server; everything computed here is
 * geometry (where a value sits on the page), never a financial result.
 *
 * Each series is `{ label, color, points: [{ x, y }], dashed?, area?, key? }`.
 * Give `key` when two series may share a label — two strategies with the
 * same name — or they would collide in the legend and the tooltip. `x`
 * is any number — a year, a month index, a timestamp — and series do not have
 * to share their x values: the hover finds the nearest x on the chart and
 * reports whichever series have a point there.
 */
const props = defineProps({
    series: { type: Array, required: true },
    height: { type: Number, default: 280 },
    formatY: { type: Function, default: moneyShort },
    formatX: { type: Function, default: (x) => String(x) },
    // How many x labels to aim for along the bottom.
    xTicks: { type: Number, default: 6 },
    // Draw the y axis from zero even when the data sits well above it.
    fromZero: { type: Boolean, default: true },
    // For a chart of surplus and shortfall: wash the plot green above zero and
    // red below it, and ring each point where a line crosses zero.
    zeroBands: { type: Boolean, default: false },
});

const pad = { top: 12, right: 16, bottom: 28, left: 56 };

// The SVG is drawn at its real pixel width rather than scaled by viewBox, so
// text stays the size it was set at on any screen.
const { frame, width } = useChartWidth(240);

const drawn = computed(() => props.series.filter((line) => line.points.length > 0));
const allPoints = computed(() => drawn.value.flatMap((line) => line.points));

const xRange = computed(() => {
    const xs = allPoints.value.map((point) => point.x);

    return { min: Math.min(...xs), max: Math.max(...xs) };
});

const yScale = computed(() => {
    const ys = allPoints.value.map((point) => point.y);

    return niceScale(Math.min(...ys, props.fromZero ? 0 : Infinity), Math.max(...ys, props.fromZero ? 0 : -Infinity));
});

const plotWidth = computed(() => width.value - pad.left - pad.right);
const plotHeight = computed(() => props.height - pad.top - pad.bottom);

const px = (x) => {
    const span = xRange.value.max - xRange.value.min;

    return pad.left + (span === 0 ? plotWidth.value / 2 : ((x - xRange.value.min) / span) * plotWidth.value);
};

const py = (y) => pad.top + (1 - (y - yScale.value.low) / (yScale.value.high - yScale.value.low)) * plotHeight.value;

const linePath = (line) => line.points.map((point, index) => `${index ? 'L' : 'M'}${px(point.x).toFixed(1)},${py(point.y).toFixed(1)}`).join(' ');

const areaPath = (line) => {
    const first = line.points[0];
    const last = line.points[line.points.length - 1];
    const floor = py(Math.max(yScale.value.low, 0));

    return `${linePath(line)} L${px(last.x).toFixed(1)},${floor} L${px(first.x).toFixed(1)},${floor} Z`;
};

// Every distinct x on the chart, in order: what the hover snaps to and what
// the bottom axis picks its labels from.
const xs = computed(() => [...new Set(allPoints.value.map((point) => point.x))].sort((a, b) => a - b));

const xLabels = computed(() => {
    const every = Math.max(1, Math.ceil(xs.value.length / props.xTicks));

    return xs.value.filter((x, index) => index % every === 0);
});

/*
 * Where each line crosses zero: between two points either side of it, at the
 * spot the drawn segment meets the axis, or on a point that sits exactly on
 * zero having come from elsewhere. `x` is where to draw the mark; `at` is the
 * first x on the far side, which is what the tooltip names.
 */
const zeroCrossings = computed(() => {
    if (!props.zeroBands) return [];

    return drawn.value.flatMap((line) => line.points.flatMap((point, index) => {
        const previous = line.points[index - 1];

        if (!previous || previous.y === 0) return [];

        if (point.y === 0) return [{ line, x: point.x, at: point.x }];

        if (previous.y * point.y > 0) return [];

        return [{ line, x: previous.x + (point.x - previous.x) * (previous.y / (previous.y - point.y)), at: point.x }];
    }));
});

const hoverX = ref(null);

const onMove = (event) => {
    const bounds = frame.value.getBoundingClientRect();
    const at = event.clientX - bounds.left;

    hoverX.value = xs.value.reduce((nearest, x) => (Math.abs(px(x) - at) < Math.abs(px(nearest) - at) ? x : nearest), xs.value[0]);
};

const hovered = computed(() => {
    if (hoverX.value === null) return null;

    const rows = drawn.value
        .map((line) => ({ line, point: line.points.find((point) => point.x === hoverX.value) }))
        .filter((row) => row.point);

    const left = px(hoverX.value);

    return { rows, left, flip: left > width.value * 0.6 };
});
</script>

<template>
    <div>
        <!-- A legend only when there is more than one thing to tell apart; a
             single line is named by the card it sits in. -->
        <ul v-if="drawn.length > 1" class="mb-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-fin-grey-600">
            <li v-for="line in drawn" :key="line.key ?? line.label" class="flex items-center gap-1.5">
                <svg width="18" height="6" aria-hidden="true">
                    <line x1="0" y1="3" x2="18" y2="3" :stroke="line.color" stroke-width="2" stroke-linecap="round" :stroke-dasharray="line.dashed ? '4 4' : null" />
                </svg>
                {{ line.label }}
            </li>
        </ul>

        <div ref="frame" class="relative" @mousemove="onMove" @mouseleave="hoverX = null">
            <svg v-if="allPoints.length" :width="width" :height="height" role="img" :aria-label="drawn.map((line) => line.label).join(', ')">
                <template v-if="zeroBands">
                    <rect
                        v-if="yScale.high > 0"
                        :x="pad.left" :width="plotWidth" :y="py(yScale.high)" :height="py(Math.max(yScale.low, 0)) - py(yScale.high)"
                        fill="var(--color-fin-green-500)" fill-opacity="0.08"
                    />
                    <rect
                        v-if="yScale.low < 0"
                        :x="pad.left" :width="plotWidth" :y="py(Math.min(yScale.high, 0))" :height="py(yScale.low) - py(Math.min(yScale.high, 0))"
                        fill="var(--color-fin-red-600)" fill-opacity="0.1"
                    />
                </template>

                <g v-for="tick in yScale.ticks" :key="tick">
                    <line :x1="pad.left" :x2="width - pad.right" :y1="py(tick)" :y2="py(tick)" stroke="var(--color-fin-grey-200)" stroke-width="1" />
                    <text :x="pad.left - 8" :y="py(tick) + 4" text-anchor="end" class="fill-fin-grey-500 text-[11px] tabular-nums">{{ formatY(tick) }}</text>
                </g>

                <text v-for="x in xLabels" :key="x" :x="px(x)" :y="height - 8" text-anchor="middle" class="fill-fin-grey-500 text-[11px] tabular-nums">
                    {{ formatX(x) }}
                </text>

                <line
                    v-if="hovered"
                    :x1="hovered.left" :x2="hovered.left" :y1="pad.top" :y2="height - pad.bottom"
                    stroke="var(--color-fin-grey-400)" stroke-width="1"
                />

                <template v-for="line in drawn" :key="line.key ?? line.label">
                    <path v-if="line.area" :d="areaPath(line)" :fill="line.color" fill-opacity="0.1" />
                    <path
                        :d="linePath(line)" fill="none" :stroke="line.color" stroke-width="2"
                        stroke-linejoin="round" stroke-linecap="round" :stroke-dasharray="line.dashed ? '5 5' : null"
                    />
                </template>

                <!-- A ring where a line crosses zero: hollow, so it does not
                     read as one of the solid dots below. -->
                <circle
                    v-for="(crossing, index) in zeroCrossings" :key="`zero-${index}`"
                    :cx="px(crossing.x)" :cy="py(0)" r="4.5"
                    fill="var(--color-fin-white)" :stroke="crossing.line.color" stroke-width="2"
                >
                    <title>{{ crossing.line.label }} crosses zero by {{ formatX(crossing.at) }}</title>
                </circle>

                <!-- A dot per line: at the hovered x while hovering, otherwise
                     at the line's end. The white ring keeps it legible where
                     lines cross. -->
                <template v-for="line in drawn" :key="`dot-${line.key ?? line.label}`">
                    <circle
                        v-if="!hovered"
                        :cx="px(line.points[line.points.length - 1].x)" :cy="py(line.points[line.points.length - 1].y)"
                        r="4" :fill="line.color" stroke="var(--color-fin-white)" stroke-width="2"
                    />
                </template>
                <template v-if="hovered">
                    <circle
                        v-for="row in hovered.rows" :key="`hover-${row.line.key ?? row.line.label}`"
                        :cx="px(row.point.x)" :cy="py(row.point.y)"
                        r="4.5" :fill="row.line.color" stroke="var(--color-fin-white)" stroke-width="2"
                    />
                </template>
            </svg>

            <div
                v-if="hovered && hovered.rows.length"
                class="pointer-events-none absolute top-2 z-10 min-w-36 rounded-lg border border-fin-grey-200 bg-fin-white px-3 py-2 text-xs shadow-lg"
                :style="hovered.flip ? { right: `${width - hovered.left + 12}px` } : { left: `${hovered.left + 12}px` }"
            >
                <p class="mb-1 font-medium text-fin-black">{{ formatX(hoverX) }}</p>
                <p v-for="row in hovered.rows" :key="row.line.key ?? row.line.label" class="flex items-center justify-between gap-4 text-fin-grey-600">
                    <span class="flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: row.line.color }" />
                        {{ row.line.label }}
                    </span>
                    <span class="font-medium tabular-nums text-fin-black">{{ formatY(row.point.y) }}</span>
                </p>
            </div>
        </div>
    </div>
</template>
