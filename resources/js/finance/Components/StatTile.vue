<script setup>
/**
 * One headline figure: a label, the number, and optionally a line under it.
 *
 * `tone` colours the hint, not the number — the figure stays in ink so a row
 * of tiles reads evenly, and the colour says only whether the news is good.
 * `feature` is the one dark tile a page leads with; `accent` is the same
 * tile in the brand green rather than charcoal. The green is the 600, not
 * the 500, so the small label and hint keep enough contrast on it.
 */
defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    hint: { type: String, default: '' },
    tone: { type: String, default: 'neutral' },
    feature: { type: Boolean, default: false },
    accent: { type: Boolean, default: false },
});

const tones = {
    neutral: 'text-fin-grey-500',
    good: 'text-fin-green-600',
    bad: 'text-fin-red-600',
    gold: 'text-fin-gold-600',
};
</script>

<template>
    <div
        class="rounded-2xl border px-5 py-4"
        :class="accent ? 'border-fin-green-600 bg-fin-green-600' : (feature ? 'border-fin-charcoal bg-fin-charcoal' : 'border-fin-grey-200 bg-fin-white')"
    >
        <p class="text-xs font-medium" :class="accent ? 'text-fin-green-100' : (feature ? 'text-fin-grey-400' : 'text-fin-grey-500')">{{ label }}</p>
        <p class="mt-1.5 text-2xl font-semibold tracking-tight" :class="accent || feature ? 'text-fin-white' : 'text-fin-black'">{{ value }}</p>
        <p v-if="hint" class="mt-1 text-xs" :class="accent ? 'text-fin-green-100' : (feature ? 'text-fin-gold-300' : tones[tone])">{{ hint }}</p>
    </div>
</template>
