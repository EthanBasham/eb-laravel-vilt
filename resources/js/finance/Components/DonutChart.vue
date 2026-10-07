<script setup>
import { computed, ref } from 'vue';
import { chartColors, money, moneyShort } from '../lib/format';

/**
 * Parts of a whole, for a handful of parts.
 *
 * Colours are taken in a fixed order, and past six parts the smallest are
 * folded into "Other" rather than given invented hues: a seventh colour that
 * is nearly the first is worse than no colour. The list beside the ring
 * prints every label and figure, so the ring is never the only place a value
 * can be read.
 */
const props = defineProps({
    // [{ label, value }]
    items: { type: Array, required: true },
    centerLabel: { type: String, default: 'Total' },
});

const MAX_PARTS = chartColors.length;

const parts = computed(() => {
    const sorted = [...props.items].filter((item) => item.value > 0).sort((a, b) => b.value - a.value);

    if (sorted.length <= MAX_PARTS) return sorted;

    // Everything past the last colour is folded into one part. A part that
    // is itself called "Other" joins it, or there would be two.
    const kept = sorted.slice(0, MAX_PARTS - 1).filter((item) => item.label !== 'Other');
    const rest = sorted.filter((item) => !kept.includes(item));

    return [...kept, { label: 'Other', value: rest.reduce((sum, item) => sum + item.value, 0) }];
});

const total = computed(() => parts.value.reduce((sum, part) => sum + part.value, 0));

/*
 * Each arc is a circle whose stroke is dashed so that only its own share is
 * drawn, then rotated to start where the previous one stopped. Simpler than
 * computing arc paths, and the gap between arcs is just a shorter dash.
 */
const RADIUS = 40;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;
const GAP = 1.6;

const arcs = computed(() => {
    let offset = 0;

    return parts.value.map((part, index) => {
        const length = (part.value / total.value) * CIRCUMFERENCE;
        const arc = {
            ...part,
            color: chartColors[index],
            share: Math.round((part.value / total.value) * 1000) / 10,
            dash: `${Math.max(0.5, length - (parts.value.length > 1 ? GAP : 0))} ${CIRCUMFERENCE}`,
            offset: -offset,
        };

        offset += length;

        return arc;
    });
});

const active = ref(null);
</script>

<template>
    <div class="flex flex-wrap items-center gap-6">
        <div class="relative h-40 w-40 shrink-0">
            <svg viewBox="0 0 100 100" class="h-full w-full -rotate-90" role="img" :aria-label="arcs.map((arc) => `${arc.label} ${arc.share}%`).join(', ')">
                <circle cx="50" cy="50" :r="RADIUS" fill="none" stroke="var(--color-fin-grey-100)" stroke-width="11" />
                <circle
                    v-for="(arc, index) in arcs" :key="index"
                    cx="50" cy="50" :r="RADIUS" fill="none"
                    :stroke="arc.color" :stroke-width="active === index ? 13 : 11"
                    :stroke-dasharray="arc.dash" :stroke-dashoffset="arc.offset"
                    class="transition-[stroke-width]"
                    @mouseenter="active = index" @mouseleave="active = null"
                />
            </svg>

            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                <span class="text-[11px] text-fin-grey-500">{{ active === null ? centerLabel : arcs[active].label }}</span>
                <span class="text-base font-semibold text-fin-black">{{ moneyShort(active === null ? total : arcs[active].value) }}</span>
            </div>
        </div>

        <!-- Wide enough that a label is never cut: in a narrow card the list
             drops under the ring instead of squeezing beside it. -->
        <ul class="flex min-w-56 flex-1 flex-col gap-2 text-sm">
            <li
                v-for="(arc, index) in arcs" :key="index"
                class="flex items-center justify-between gap-3 rounded-md px-1"
                :class="active === index ? 'bg-fin-cream-100' : ''"
                @mouseenter="active = index" @mouseleave="active = null"
            >
                <span class="flex min-w-0 items-center gap-2 text-fin-charcoal">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ backgroundColor: arc.color }" />
                    <span class="truncate">{{ arc.label }}</span>
                </span>
                <span class="whitespace-nowrap tabular-nums text-fin-grey-500">
                    <span class="font-medium text-fin-black">{{ money(arc.value) }}</span>
                    · {{ arc.share }}%
                </span>
            </li>
        </ul>
    </div>
</template>
