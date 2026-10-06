<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { niceScale } from '../composables/useNiceScale';
import { money, moneyShort } from '../lib/format';

/**
 * One flow across the years of the plan, with a slider on every year.
 *
 * Two lines: dashed, what the rate alone makes of each year; solid, the
 * figure in use. They part company wherever a year has been pinned. Each
 * year's point is a slider — drag it, or focus it and use the arrow keys —
 * and the panel underneath takes an exact amount for whichever year is
 * selected.
 *
 * Nothing is worked out here beyond geometry. A dragged point *is* the figure
 * (the amount the year is pinned to), and it goes to the server as-is; the
 * rate-driven line and every total come back from PHP.
 *
 * Each point is `{ year, age, base, amount, is_pinned }`. Where the rate can
 * be started again from a pinned year (`restartable`), a point also says
 * whether it is one (`is_restart`) and what it comes to with no pin of its
 * own (`unpinned`), which is then no longer `base`.
 */
const props = defineProps({
    points: { type: Array, required: true },
    color: { type: String, default: 'var(--color-fin-chart-1)' },
    // Marked on the x axis when it falls inside the plan.
    retirementYear: { type: Number, default: null },
    height: { type: Number, default: 260 },
    // Names the chart for a screen reader: the flow it belongs to.
    label: { type: String, default: '' },
    // Whether a pinned year can be made where the rate starts again from.
    restartable: { type: Boolean, default: false },
});

const emit = defineEmits(['pin', 'unpin', 'restart', 'unrestart']);

const pad = { top: 22, right: 14, bottom: 30, left: 56 };

// Never narrower than a usable slider per year; the frame scrolls instead.
const minWidth = computed(() => pad.left + pad.right + props.points.length * 14);

const frame = ref(null);
const svg = ref(null);
const width = ref(640);
let observer = null;

onMounted(() => {
    observer = new ResizeObserver(([entry]) => {
        width.value = Math.max(minWidth.value, entry.contentRect.width);
    });
    observer.observe(frame.value);
});

onBeforeUnmount(() => observer?.disconnect());

// The year being dragged or nudged, and where it has got to. Kept apart from
// the points so the scale below does not move under the pointer mid-drag.
const live = ref(null);
const dragging = ref(false);
const selected = ref(null);

const valueOf = (point) => (live.value?.year === point.year ? live.value.amount : point.amount);

/*
 * Headroom above the tallest year, so there is somewhere to drag to. Read
 * from the settled points only: once a drag is let go the scale grows to fit
 * it, and the next drag has room again.
 */
const scale = computed(() => {
    const top = Math.max(0, ...props.points.flatMap((point) => [point.base, point.amount]));

    return niceScale(0, top > 0 ? top * 1.3 : 1000);
});

// What a drag or a key press snaps to: 1,000 on a six-figure scale, 100 on a
// five-figure one, never less than a dollar.
const step = computed(() => 10 ** Math.max(0, Math.floor(Math.log10(scale.value.high)) - 2));

const plotWidth = computed(() => width.value - pad.left - pad.right);
const plotHeight = computed(() => props.height - pad.top - pad.bottom);
const spacing = computed(() => (props.points.length > 1 ? plotWidth.value / (props.points.length - 1) : plotWidth.value));

const px = (index) => pad.left + (props.points.length > 1 ? index * spacing.value : plotWidth.value / 2);
const py = (amount) => pad.top + (1 - Math.min(amount, scale.value.high) / scale.value.high) * plotHeight.value;

const path = (read) => props.points.map((point, index) => `${index ? 'L' : 'M'}${px(index).toFixed(1)},${py(read(point)).toFixed(1)}`).join(' ');

const basePath = computed(() => path((point) => point.base));
const linePath = computed(() => path(valueOf));
const areaPath = computed(() => `${linePath.value} L${px(props.points.length - 1).toFixed(1)},${py(0)} L${px(0).toFixed(1)},${py(0)} Z`);

const xLabels = computed(() => {
    const every = Math.max(1, Math.ceil(props.points.length / Math.max(2, Math.floor(plotWidth.value / 90))));

    return props.points.map((point, index) => ({ point, index })).filter(({ index }) => index % every === 0);
});

const retirementIndex = computed(() => props.points.findIndex((point) => point.year === props.retirementYear));

const snap = (amount) => Math.min(scale.value.high, Math.max(0, Math.round(amount / step.value) * step.value));

const amountAt = (clientY) => {
    const ratio = 1 - (clientY - svg.value.getBoundingClientRect().top - pad.top) / plotHeight.value;

    return snap(ratio * scale.value.high);
};

// Hands a moved year to the parent. A point picked up and put down where it
// was is a selection, not a pin.
const commit = () => {
    const moved = live.value;
    live.value = null;

    if (!moved) return;

    const point = props.points.find((candidate) => candidate.year === moved.year);

    if (point && moved.amount !== point.amount) {
        emit('pin', moved.year, moved.amount);
    }
};

const onDown = (point, event) => {
    event.currentTarget.setPointerCapture(event.pointerId);
    selected.value = point.year;
    dragging.value = true;
    live.value = { year: point.year, amount: point.amount };
};

const onMove = (point, event) => {
    if (!dragging.value || live.value?.year !== point.year) return;

    live.value = { year: point.year, amount: amountAt(event.clientY) };
};

const onUp = () => {
    if (!dragging.value) return;

    dragging.value = false;
    commit();
};

const onCancel = () => {
    dragging.value = false;
    live.value = null;
};

const NUDGES = { ArrowUp: 1, ArrowDown: -1, PageUp: 10, PageDown: -10 };

const onKeydown = (point, event) => {
    if ((event.key === 'Delete' || event.key === 'Backspace') && point.is_pinned) {
        event.preventDefault();
        emit('unpin', point.year);

        return;
    }

    if (!(event.key in NUDGES)) return;

    event.preventDefault();
    selected.value = point.year;
    live.value = { year: point.year, amount: snap(valueOf(point) + NUDGES[event.key] * step.value) };
};

// The nudges of one held key are sent as one change, when it is let go.
const onKeyup = (event) => {
    if (event.key in NUDGES) commit();
};

// Leaving a slider settles a nudge still held. Only its own, and never a
// drag: pressing on one slider blurs the last one a moment after the press
// has begun, which would otherwise end the new drag before it had moved.
const onBlur = (point) => {
    if (!dragging.value && live.value?.year === point.year) commit();
};

const selectedPoint = computed(() => props.points.find((point) => point.year === selected.value) ?? null);

// The year whose figure is printed above its point.
const labelled = computed(() => {
    const year = live.value?.year ?? selected.value;
    const index = props.points.findIndex((point) => point.year === year);

    return index === -1 ? null : { index, point: props.points[index] };
});

const typeAmount = (event) => {
    const amount = Number(event.target.value);

    if (event.target.value === '' || Number.isNaN(amount) || amount < 0) {
        event.target.value = selectedPoint.value.amount;

        return;
    }

    if (amount !== selectedPoint.value.amount) {
        emit('pin', selectedPoint.value.year, Math.round(amount * 100) / 100);
    }
};
</script>

<template>
    <div>
        <ul class="mb-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-fin-grey-600">
            <li class="flex items-center gap-1.5">
                <svg width="18" height="6" aria-hidden="true"><line x1="0" y1="3" x2="18" y2="3" :stroke="color" stroke-width="2" stroke-linecap="round" /></svg>
                Projected
            </li>
            <li class="flex items-center gap-1.5">
                <svg width="18" height="6" aria-hidden="true"><line x1="0" y1="3" x2="18" y2="3" stroke="var(--color-fin-grey-400)" stroke-width="2" stroke-linecap="round" stroke-dasharray="4 4" /></svg>
                By the rate alone
            </li>
            <li class="flex items-center gap-1.5">
                <svg width="10" height="10" aria-hidden="true"><circle cx="5" cy="5" r="4" fill="var(--color-fin-gold-400)" /></svg>
                Set by hand
            </li>
            <li v-if="points.some((point) => point.is_restart)" class="flex items-center gap-1.5">
                <svg width="10" height="10" aria-hidden="true"><circle cx="5" cy="5" r="4" fill="var(--color-fin-gold-600)" /></svg>
                Rate restarts here
            </li>
        </ul>

        <div class="overflow-x-auto">
            <div ref="frame" :style="{ minWidth: `${minWidth}px` }">
                <svg ref="svg" :width="width" :height="height" class="select-none" role="group" :aria-label="`${label}, year by year`">
                    <g v-for="tick in scale.ticks" :key="tick">
                        <line :x1="pad.left" :x2="width - pad.right" :y1="py(tick)" :y2="py(tick)" stroke="var(--color-fin-grey-200)" stroke-width="1" />
                        <text :x="pad.left - 8" :y="py(tick) + 4" text-anchor="end" class="fill-fin-grey-500 text-[11px] tabular-nums">{{ moneyShort(tick) }}</text>
                    </g>

                    <text v-for="{ point, index } in xLabels" :key="point.year" :x="px(index)" :y="height - 8" text-anchor="middle" class="fill-fin-grey-500 text-[11px] tabular-nums">
                        {{ point.year }} ({{ point.age }})
                    </text>

                    <g v-if="retirementIndex > 0">
                        <line
                            :x1="px(retirementIndex)" :x2="px(retirementIndex)" :y1="pad.top - 6" :y2="height - pad.bottom"
                            stroke="var(--color-fin-grey-400)" stroke-width="1" stroke-dasharray="2 4"
                        />
                        <text :x="px(retirementIndex) + 5" :y="pad.top - 10" class="fill-fin-grey-500 text-[10px]">Retire</text>
                    </g>

                    <!-- The selected year's column, which is also what shows
                         where keyboard focus is: the sliders drop the
                         browser's ring, which would box the whole column. -->
                    <rect
                        v-if="labelled"
                        :x="px(labelled.index) - Math.min(spacing, 28) / 2" :y="pad.top" :width="Math.min(spacing, 28)" :height="plotHeight"
                        fill="var(--color-fin-gold-100)" fill-opacity="0.7" rx="4"
                    />

                    <path :d="areaPath" :fill="color" fill-opacity="0.08" />
                    <path :d="basePath" fill="none" stroke="var(--color-fin-grey-400)" stroke-width="1.5" stroke-dasharray="4 4" stroke-linejoin="round" />
                    <path :d="linePath" fill="none" :stroke="color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

                    <circle
                        v-for="(point, index) in points" :key="`dot-${point.year}`"
                        :cx="px(index)" :cy="py(valueOf(point))"
                        :r="point.year === selected || point.is_pinned ? 4.5 : 3"
                        :fill="point.is_restart ? 'var(--color-fin-gold-600)' : (point.is_pinned || live?.year === point.year ? 'var(--color-fin-gold-400)' : 'var(--color-fin-white)')"
                        :stroke="point.is_pinned || live?.year === point.year ? 'var(--color-fin-gold-600)' : color"
                        stroke-width="1.5"
                    />

                    <text
                        v-if="labelled"
                        :x="Math.min(width - pad.right - 28, Math.max(pad.left + 28, px(labelled.index)))" :y="py(valueOf(labelled.point)) - 10"
                        text-anchor="middle" class="fill-fin-black text-[11px] font-semibold tabular-nums"
                        stroke="var(--color-fin-white)" stroke-width="3" paint-order="stroke"
                    >
                        {{ money(valueOf(labelled.point)) }}
                    </text>

                    <!-- The sliders: one column per year, over everything
                         else, so a point is easy to catch wherever it sits. -->
                    <rect
                        v-for="(point, index) in points" :key="`slider-${point.year}`"
                        :x="px(index) - Math.min(spacing, 28) / 2" :y="pad.top" :width="Math.min(spacing, 28)" :height="plotHeight"
                        fill="transparent" class="cursor-ns-resize" style="touch-action: none; outline: none"
                        tabindex="0" role="slider" aria-orientation="vertical"
                        :aria-label="`${point.year}, age ${point.age}`"
                        :aria-valuemin="0" :aria-valuemax="scale.high" :aria-valuenow="valueOf(point)" :aria-valuetext="money(valueOf(point))"
                        @pointerdown="onDown(point, $event)" @pointermove="onMove(point, $event)"
                        @pointerup="onUp" @pointercancel="onCancel"
                        @focus="selected = point.year" @blur="onBlur(point)"
                        @keydown="onKeydown(point, $event)" @keyup="onKeyup"
                    />
                </svg>
            </div>
        </div>

        <div class="mt-3 flex min-h-[3.25rem] flex-wrap items-center gap-x-5 gap-y-2 rounded-xl bg-fin-cream-100 px-4 py-2.5 text-sm">
            <p v-if="!selectedPoint" class="text-fin-grey-600">
                Drag any year's point up or down to set it by hand, or select one to type an exact amount. A year set by hand moves only that year.
            </p>

            <template v-else>
                <p class="font-semibold text-fin-black">{{ selectedPoint.year }} <span class="font-normal text-fin-grey-500">· age {{ selectedPoint.age }}</span></p>

                <label class="flex items-center gap-2 text-fin-grey-600">
                    Amount for the year
                    <span class="relative block w-36">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-fin-grey-400">$</span>
                        <input :value="selectedPoint.amount" type="number" min="0" step="1" class="!pl-7" @change="typeAmount">
                    </span>
                </label>

                <p class="text-xs text-fin-grey-500">The rate alone makes it {{ money(selectedPoint.unpinned ?? selectedPoint.base) }}.</p>

                <div v-if="selectedPoint.is_pinned" class="ml-auto flex flex-wrap items-center gap-2">
                    <button type="button" class="fin-btn fin-btn-quiet" @click="emit('unpin', selectedPoint.year)">
                        Back to the rate
                    </button>
                    <button
                        v-if="restartable && !selectedPoint.is_restart" type="button" class="fin-btn fin-btn-quiet"
                        title="The years after this one carry on from this amount at the rate" @click="emit('restart', selectedPoint.year)"
                    >
                        Restart rate from here
                    </button>
                    <button
                        v-if="restartable && selectedPoint.is_restart" type="button" class="fin-btn fin-btn-quiet"
                        title="Leave this as a year on its own: the years after go back to where they were" @click="emit('unrestart', selectedPoint.year)"
                    >
                        Stop restarting here
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>
