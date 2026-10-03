<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { niceScale } from '../composables/useNiceScale';
import { money, moneyShort } from '../lib/format';

/**
 * One figure a year, drawn against a ladder of thresholds: a year's income
 * against the tops of the tax brackets, or against the IRMAA tiers.
 *
 * The thresholds are flat lines because everything arrives in today's
 * dollars. The bands between them are shaded alternately so it is plain which
 * one a year sits in, and each line is named at the right-hand edge.
 *
 * Each point may carry `before`: the figure without the thing being studied
 * (the conversion). It is drawn dashed, and the gap up to the solid line is
 * filled, so what the conversion added — and which line it pushed the year
 * over — can be read straight off.
 *
 * Geometry only: every figure is worked out in PHP.
 *
 * `points` is `[{ x, y, before?, note? }]`; `thresholds` is `[{ label, value }]`,
 * lowest first.
 */
const props = defineProps({
    points: { type: Array, required: true },
    thresholds: { type: Array, required: true },
    color: { type: String, default: 'var(--color-fin-chart-1)' },
    height: { type: Number, default: 260 },
    formatX: { type: Function, default: (x) => String(x) },
    // What the solid and dashed lines are called in the tooltip.
    label: { type: String, default: 'Income' },
    beforeLabel: { type: String, default: 'Before converting' },
});

const pad = { top: 12, right: 88, bottom: 28, left: 56 };

const frame = ref(null);
const width = ref(640);
let observer = null;

onMounted(() => {
    observer = new ResizeObserver(([entry]) => {
        width.value = Math.max(280, entry.contentRect.width);
    });
    observer.observe(frame.value);
});

onBeforeUnmount(() => observer?.disconnect());

/*
 * Tall enough for the highest year and for the first threshold above it, so
 * there is always a line overhead to show how much room is left — but no
 * taller, or a top bracket at $640,000 would flatten a $90,000 income.
 */
const scale = computed(() => {
    const peak = Math.max(0, ...props.points.map((point) => point.y));
    const next = props.thresholds.find((threshold) => threshold.value > peak);

    return niceScale(0, Math.max(peak * 1.08, next ? next.value * 1.04 : 0, 1000));
});

const shown = computed(() => props.thresholds.filter((threshold) => threshold.value <= scale.value.high));

// Which lines get their name printed: all of them, except one that would sit
// on top of the name below it (the 0% and 10% brackets are a few pixels apart
// on a chart that runs to six figures).
const named = computed(() => {
    let last = Infinity;

    return shown.value.filter((threshold) => {
        const y = py(threshold.value);
        const clear = last - y >= 11;

        if (clear) last = y;

        return clear;
    }).map((threshold) => threshold.label);
});

// The bands between the lines, bottom to top, for the alternate shading.
const bands = computed(() => {
    const edges = [0, ...shown.value.map((threshold) => threshold.value), scale.value.high];

    return edges.slice(0, -1).map((low, index) => ({ low, high: edges[index + 1], shaded: index % 2 === 1 }));
});

const plotWidth = computed(() => width.value - pad.left - pad.right);
const plotHeight = computed(() => props.height - pad.top - pad.bottom);

const xs = computed(() => props.points.map((point) => point.x));
const px = (x) => {
    const span = xs.value[xs.value.length - 1] - xs.value[0];

    return pad.left + (span === 0 ? plotWidth.value / 2 : ((x - xs.value[0]) / span) * plotWidth.value);
};
const py = (y) => pad.top + (1 - Math.min(y, scale.value.high) / scale.value.high) * plotHeight.value;

const trace = (read, reversed = false) => {
    const pairs = props.points.map((point) => `${px(point.x).toFixed(1)},${py(read(point)).toFixed(1)}`);

    return (reversed ? pairs.reverse() : pairs).join(' L');
};

const hasBefore = computed(() => props.points.some((point) => point.before !== undefined && point.before !== point.y));
const linePath = computed(() => `M${trace((point) => point.y)}`);
const beforePath = computed(() => `M${trace((point) => point.before ?? point.y)}`);
const gapPath = computed(() => `M${trace((point) => point.y)} L${trace((point) => point.before ?? point.y, true)} Z`);

const xLabels = computed(() => {
    const every = Math.max(1, Math.ceil(props.points.length / Math.max(2, Math.floor(plotWidth.value / 56))));

    return props.points.filter((point, index) => index % every === 0);
});

const hoverX = ref(null);

const onMove = (event) => {
    const at = event.clientX - frame.value.getBoundingClientRect().left;

    hoverX.value = xs.value.reduce((nearest, x) => (Math.abs(px(x) - at) < Math.abs(px(nearest) - at) ? x : nearest), xs.value[0]);
};

const hovered = computed(() => {
    const point = props.points.find((candidate) => candidate.x === hoverX.value);

    return point ? { point, left: px(point.x), flip: px(point.x) > width.value * 0.55 } : null;
});
</script>

<template>
    <div ref="frame" class="relative" @mousemove="onMove" @mouseleave="hoverX = null">
        <svg v-if="points.length" :width="width" :height="height" role="img" :aria-label="`${label} each year against ${thresholds.map((threshold) => threshold.label).join(', ')}`">
            <rect
                v-for="band in bands" :key="band.low"
                :x="pad.left" :width="plotWidth" :y="py(band.high)" :height="py(band.low) - py(band.high)"
                :fill="band.shaded ? 'var(--color-fin-cream-200)' : 'var(--color-fin-cream-50)'" fill-opacity="0.7"
            />

            <text v-for="tick in scale.ticks" :key="tick" :x="pad.left - 8" :y="py(tick) + 4" text-anchor="end" class="fill-fin-grey-500 text-[11px] tabular-nums">
                {{ moneyShort(tick) }}
            </text>

            <g v-for="threshold in shown" :key="threshold.label">
                <line :x1="pad.left" :x2="width - pad.right" :y1="py(threshold.value)" :y2="py(threshold.value)" stroke="var(--color-fin-grey-400)" stroke-width="1" stroke-dasharray="2 3" />
                <text v-if="named.includes(threshold.label)" :x="width - pad.right + 6" :y="py(threshold.value) + 4" class="fill-fin-grey-600 text-[10px] font-medium">{{ threshold.label }}</text>
            </g>

            <text v-for="point in xLabels" :key="point.x" :x="px(point.x)" :y="height - 8" text-anchor="middle" class="fill-fin-grey-500 text-[11px] tabular-nums">
                {{ formatX(point.x) }}
            </text>

            <line v-if="hovered" :x1="hovered.left" :x2="hovered.left" :y1="pad.top" :y2="height - pad.bottom" stroke="var(--color-fin-grey-400)" stroke-width="1" />

            <template v-if="hasBefore">
                <path :d="gapPath" fill="var(--color-fin-gold-400)" fill-opacity="0.3" />
                <path :d="beforePath" fill="none" stroke="var(--color-fin-grey-500)" stroke-width="1.5" stroke-dasharray="4 4" stroke-linejoin="round" />
            </template>
            <path :d="linePath" fill="none" :stroke="color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

            <circle v-if="hovered" :cx="hovered.left" :cy="py(hovered.point.y)" r="4.5" :fill="color" stroke="var(--color-fin-white)" stroke-width="2" />
        </svg>

        <div
            v-if="hovered"
            class="pointer-events-none absolute top-2 z-10 min-w-44 rounded-lg border border-fin-grey-200 bg-fin-white px-3 py-2 text-xs shadow-lg"
            :style="hovered.flip ? { right: `${width - hovered.left + 12}px` } : { left: `${hovered.left + 12}px` }"
        >
            <p class="mb-1 font-medium text-fin-black">{{ formatX(hovered.point.x) }}</p>
            <p class="flex justify-between gap-4 text-fin-grey-600">{{ label }} <span class="font-medium tabular-nums text-fin-black">{{ money(hovered.point.y) }}</span></p>
            <p v-if="hovered.point.before !== undefined && hovered.point.before !== hovered.point.y" class="flex justify-between gap-4 text-fin-grey-600">
                {{ beforeLabel }} <span class="tabular-nums text-fin-charcoal">{{ money(hovered.point.before) }}</span>
            </p>
            <p v-if="hovered.point.note" class="mt-1 text-fin-grey-600">{{ hovered.point.note }}</p>
        </div>
    </div>
</template>
