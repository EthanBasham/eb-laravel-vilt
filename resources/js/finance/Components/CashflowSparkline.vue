<script setup>
import { computed, useId } from 'vue';

/**
 * Take-home income against expenses across the plan, small enough for a
 * table row.
 *
 * Expenses are the red line. Income after tax is drawn against it: green, over a green
 * wash, wherever it clears expenses; grey, under a red wash that fills the
 * gap, wherever it falls short.
 *
 * The two lines cross between years, and nothing here works out where. Each
 * colour is the whole income line (or the whole band between the lines)
 * drawn through a clip: one clip is everything above the expense line, the
 * other everything below it. The crossing falls out of the clipping.
 *
 * `totals` is `[{ year, take_home, expenses }]`, as the server sends it.
 */
const props = defineProps({
    totals: { type: Array, required: true },
    width: { type: Number, default: 190 },
    height: { type: Number, default: 44 },
});

const id = useId();
const pad = 3;

const top = computed(() => Math.max(1, ...props.totals.flatMap((year) => [year.take_home, year.expenses])));

const px = (index) => pad + (props.totals.length > 1 ? (index / (props.totals.length - 1)) * (props.width - pad * 2) : (props.width - pad * 2) / 2);
const py = (amount) => pad + (1 - Math.max(0, amount) / top.value) * (props.height - pad * 2);

const trace = (key, reversed = false) => {
    const points = props.totals.map((year, index) => `${px(index).toFixed(1)},${py(year[key]).toFixed(1)}`);

    return (reversed ? points.reverse() : points).join(' L');
};

const incomeLine = computed(() => `M${trace('take_home')}`);
const expenseLine = computed(() => `M${trace('expenses')}`);

// The band between the two lines: along income, back along expenses.
const band = computed(() => `M${trace('take_home')} L${trace('expenses', true)} Z`);

// Everything above the expense line, and everything below it. A single year
// has no line to be either side of, so there is nothing to clip to.
const last = computed(() => px(props.totals.length - 1).toFixed(1));
const above = computed(() => `M${trace('expenses')} L${last.value},-10 L${px(0).toFixed(1)},-10 Z`);
const below = computed(() => `M${trace('expenses')} L${last.value},${props.height + 10} L${px(0).toFixed(1)},${props.height + 10} Z`);

const runsShort = computed(() => props.totals.some((year) => year.take_home < year.expenses));
const label = computed(() => `Income after tax against expenses, ${props.totals[0].year} to ${props.totals[props.totals.length - 1].year}. ${runsShort.value ? 'It falls short of expenses in some years.' : 'It covers expenses throughout.'}`);
</script>

<template>
    <svg :width="width" :height="height" role="img" :aria-label="label">
        <title>{{ label }}</title>

        <defs>
            <clipPath :id="`${id}-above`"><path :d="above" /></clipPath>
            <clipPath :id="`${id}-below`"><path :d="below" /></clipPath>
        </defs>

        <path :d="band" fill="var(--color-fin-green-500)" fill-opacity="0.18" :clip-path="`url(#${id}-above)`" />
        <path :d="band" fill="var(--color-fin-red-600)" fill-opacity="0.35" :clip-path="`url(#${id}-below)`" />

        <path :d="incomeLine" fill="none" stroke="var(--color-fin-grey-400)" stroke-width="1.5" stroke-linejoin="round" :clip-path="`url(#${id}-below)`" />
        <path :d="incomeLine" fill="none" stroke="var(--color-fin-green-500)" stroke-width="1.5" stroke-linejoin="round" :clip-path="`url(#${id}-above)`" />
        <path :d="expenseLine" fill="none" stroke="var(--color-fin-red-600)" stroke-width="1.5" stroke-linejoin="round" />
    </svg>
</template>
