<script setup>
import { computed } from 'vue';

/**
 * One row of filter chips: a label, a chip per value, and an All that clears
 * the row.
 *
 * Seventeen of these were written out by hand across the boards, the tank
 * picker and the grinding picker — the same markup three times over in each of
 * three polarities, which is how they came to disagree about which state looks
 * lit.
 *
 * The three polarities are `mode`, and they are genuinely different questions:
 *
 *   hidden   The boards. A view you come back to, so everything starts on and
 *            the row remembers what you switched off. Lit means shown.
 *   picked   The grinding picker. Four hundred tanks you are trying to find one
 *            in, where narrowing to a nation should cost one click rather than
 *            nine — so every chip starts unlit and picking one is what cuts.
 *   single   The tank picker. For finding a tank you already know, so picking a
 *            second nation replaces the first rather than widening the list.
 *            Marked up as a radio group, because that is what it is.
 *
 * `variant` is the chip's shape, which follows what is drawn in it rather than
 * what it filters: a flag is an image and wears only a border, a numeral and an
 * icon are both a fixed-width box.
 */
const props = defineProps({
    label: { type: String, required: true },
    items: { type: Array, required: true },
    /*
     * What is switched off (hidden), what is chosen (picked), or the one chosen
     * value (single, null for a row left open). The row derives both the lit
     * state and whether All has anything to do from it.
     */
    selected: { type: [Array, String, Number, null], default: () => [] },
    mode: { type: String, default: 'hidden' }, // hidden | picked | single
    variant: { type: String, default: 'badge' }, // flag | badge | icon
    /*
     * The accessible name of one chip, where what is drawn in it does not carry
     * one: a tier's numeral needs saying as "Tier VIII", a flag names itself.
     */
    itemLabel: { type: Function, default: null },
    // Shown on hover as well as read out, where the chip is a bare figure whose
    // meaning is only obvious from the row it is in.
    titled: { type: Boolean, default: false },
});

defineEmits(['toggle', 'clear']);

const isOn = (item) => {
    if (props.mode === 'single') {
        return props.selected === item;
    }

    return props.mode === 'picked'
        ? props.selected.includes(item)
        : !props.selected.includes(item);
};

// All only earns its place once it would do something.
const hasSelection = computed(() => (props.mode === 'single'
    ? props.selected !== null
    : props.selected.length > 0));

const CHIPS = {
    flag: {
        base: 'border p-1 leading-none transition-colors',
        on: 'border-wot-gold',
        off: 'border-wot-border opacity-30 hover:opacity-70',
    },
    badge: {
        base: 'min-w-9 border px-2 py-0.5 text-xs font-bold tracking-wider transition-colors',
        on: 'border-wot-gold text-wot-gold',
        off: 'border-wot-border text-wot-dim hover:text-wot-text',
    },
    icon: {
        base: 'inline-flex h-6 min-w-9 items-center justify-center border px-2 transition-colors',
        on: 'border-wot-gold text-wot-gold',
        off: 'border-wot-border text-wot-dim hover:text-wot-text',
    },
};

const chip = computed(() => CHIPS[props.variant]);

const isRadio = computed(() => props.mode === 'single');
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-1.5"
        :role="isRadio ? 'radiogroup' : undefined"
        :aria-label="isRadio ? label : undefined"
    >
        <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">{{ label }}</span>

        <button
            v-for="item in items"
            :key="item"
            type="button"
            :role="isRadio ? 'radio' : undefined"
            :class="[chip.base, isOn(item) ? chip.on : chip.off]"
            :aria-pressed="isRadio ? undefined : isOn(item)"
            :aria-checked="isRadio ? isOn(item) : undefined"
            :aria-label="itemLabel ? itemLabel(item) : undefined"
            :title="titled && itemLabel ? itemLabel(item) : undefined"
            @click="$emit('toggle', item)"
        >
            <slot :item="item">{{ item }}</slot>
        </button>

        <button
            v-if="hasSelection"
            type="button"
            class="ms-1 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-text"
            @click="$emit('clear')"
        >
            All
        </button>
    </div>
</template>
