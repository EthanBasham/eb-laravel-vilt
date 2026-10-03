<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { niceScale } from '../composables/useNiceScale';
import { money, moneyShort } from '../lib/format';

/**
 * A spread of outcomes over time: a shaded band from a bad outcome to a good
 * one (the 10th and 90th percentiles), the typical one (the median) through
 * it, and optionally one more line to compare against — the steady market.
 *
 * Geometry only: the percentiles arrive worked out.
 *
 * `band` is `[{ x, p10, p50, p90 }]`; `line` is `[{ x, y }]`.
 */
const props = defineProps({
    band: { type: Array, required: true },
    line: { type: Array, default: () => [] },
    lineLabel: { type: String, default: 'Steady market' },
    color: { type: String, default: 'var(--color-fin-chart-1)' },
    height: { type: Number, default: 240 },
    formatX: { type: Function, default: (x) => String(x) },
});

const pad = { top: 12, right: 16, bottom: 28, left: 56 };

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

const scale = computed(() => niceScale(0, Math.max(1000, ...props.band.map((point) => point.p90), ...props.line.map((point) => point.y))));
const plotWidth = computed(() => width.value - pad.left - pad.right);
const plotHeight = computed(() => props.height - pad.top - pad.bottom);

const first = computed(() => props.band[0]?.x ?? 0);
const last = computed(() => props.band[props.band.length - 1]?.x ?? 1);
const px = (x) => pad.left + (last.value === first.value ? plotWidth.value / 2 : ((x - first.value) / (last.value - first.value)) * plotWidth.value);
const py = (y) => pad.top + (1 - Math.max(0, y) / scale.value.high) * plotHeight.value;

const trace = (points, read) => points.map((point) => `${px(point.x).toFixed(1)},${py(read(point)).toFixed(1)}`).join(' L');

const bandPath = computed(() => `M${trace(props.band, (point) => point.p90)} L${trace([...props.band].reverse(), (point) => point.p10)} Z`);
const medianPath = computed(() => `M${trace(props.band, (point) => point.p50)}`);
const linePath = computed(() => (props.line.length ? `M${trace(props.line, (point) => point.y)}` : ''));

const xLabels = computed(() => {
    const every = Math.max(1, Math.ceil(props.band.length / Math.max(2, Math.floor(plotWidth.value / 60))));

    return props.band.filter((point, index) => index % every === 0);
});

const hoverX = ref(null);

const onMove = (event) => {
    const at = event.clientX - frame.value.getBoundingClientRect().left;

    hoverX.value = props.band.reduce((nearest, point) => (Math.abs(px(point.x) - at) < Math.abs(px(nearest) - at) ? point.x : nearest), first.value);
};

const hovered = computed(() => {
    const point = props.band.find((candidate) => candidate.x === hoverX.value);

    if (!point) return null;

    return { point, steady: props.line.find((candidate) => candidate.x === point.x), left: px(point.x), flip: px(point.x) > width.value * 0.55 };
});
</script>

<template>
    <div>
        <ul class="mb-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-fin-grey-600">
            <li class="flex items-center gap-1.5"><span class="h-2.5 w-4 rounded-sm" :style="{ backgroundColor: color, opacity: 0.2 }" /> Bad to good market (10th–90th)</li>
            <li class="flex items-center gap-1.5">
                <svg width="18" height="6" aria-hidden="true"><line x1="0" y1="3" x2="18" y2="3" :stroke="color" stroke-width="2" /></svg>
                Typical market
            </li>
            <li v-if="line.length" class="flex items-center gap-1.5">
                <svg width="18" height="6" aria-hidden="true"><line x1="0" y1="3" x2="18" y2="3" stroke="var(--color-fin-grey-500)" stroke-width="1.5" stroke-dasharray="4 4" /></svg>
                {{ lineLabel }}
            </li>
        </ul>

        <div ref="frame" class="relative" @mousemove="onMove" @mouseleave="hoverX = null">
            <svg v-if="band.length" :width="width" :height="height" role="img" aria-label="The spread of outcomes each year">
                <g v-for="tick in scale.ticks" :key="tick">
                    <line :x1="pad.left" :x2="width - pad.right" :y1="py(tick)" :y2="py(tick)" stroke="var(--color-fin-grey-200)" stroke-width="1" />
                    <text :x="pad.left - 8" :y="py(tick) + 4" text-anchor="end" class="fill-fin-grey-500 text-[11px] tabular-nums">{{ moneyShort(tick) }}</text>
                </g>

                <text v-for="point in xLabels" :key="point.x" :x="px(point.x)" :y="height - 8" text-anchor="middle" class="fill-fin-grey-500 text-[11px] tabular-nums">
                    {{ formatX(point.x) }}
                </text>

                <path :d="bandPath" :fill="color" fill-opacity="0.18" />
                <path v-if="linePath" :d="linePath" fill="none" stroke="var(--color-fin-grey-500)" stroke-width="1.5" stroke-dasharray="4 4" stroke-linejoin="round" />
                <path :d="medianPath" fill="none" :stroke="color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

                <line v-if="hovered" :x1="hovered.left" :x2="hovered.left" :y1="pad.top" :y2="height - pad.bottom" stroke="var(--color-fin-grey-400)" stroke-width="1" />
            </svg>

            <div
                v-if="hovered"
                class="pointer-events-none absolute top-2 z-10 min-w-44 rounded-lg border border-fin-grey-200 bg-fin-white px-3 py-2 text-xs shadow-lg"
                :style="hovered.flip ? { right: `${width - hovered.left + 12}px` } : { left: `${hovered.left + 12}px` }"
            >
                <p class="mb-1 font-medium text-fin-black">{{ formatX(hovered.point.x) }}</p>
                <p class="flex justify-between gap-4 text-fin-grey-600">Good market <span class="tabular-nums text-fin-black">{{ money(hovered.point.p90) }}</span></p>
                <p class="flex justify-between gap-4 text-fin-grey-600">Typical <span class="font-medium tabular-nums text-fin-black">{{ money(hovered.point.p50) }}</span></p>
                <p class="flex justify-between gap-4 text-fin-grey-600">Bad market <span class="tabular-nums text-fin-black">{{ money(hovered.point.p10) }}</span></p>
                <p v-if="hovered.steady" class="mt-1 flex justify-between gap-4 text-fin-grey-500">{{ lineLabel }} <span class="tabular-nums">{{ money(hovered.steady.y) }}</span></p>
            </div>
        </div>
    </div>
</template>
