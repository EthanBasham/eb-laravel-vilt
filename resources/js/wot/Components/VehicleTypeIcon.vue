<script setup>
import { computed } from 'vue';

const props = defineProps({
    type: { type: String, default: '' },
});

/*
 * The real `ico-vehicle-type__*` glyphs from worldoftanks.com's own tankopedia
 * nav — a diamond per light/medium/heavy tier of armor, a downward triangle
 * for a tank destroyer, a square for artillery. Not a separate image file:
 * they're inline base64 SVGs baked into WG's compiled CSS, extracted once and
 * kept here (and mirrored under public/images/vehicle-types for provenance —
 * see docs/setup-log.md). `fill="currentColor"` replaces WG's own tan
 * (#DFD9B7) so the badge follows whatever text color wraps it, matching how
 * every other muted label on this page (tier, dim text) is colored.
 */
const TYPES = {
    lightTank: {
        label: 'Light Tank',
        viewBox: '0 0 11 13',
        width: 11,
        height: 13,
        path: 'M5.5 0L0 6.5 5.5 13 11 6.5z',
    },
    mediumTank: {
        label: 'Medium Tank',
        viewBox: '0 0 12 15',
        width: 12,
        height: 15,
        path: 'M12 7.5L9.7 4.7l-6 7.5L6 15zM6 0L0 7.5l2.3 2.8 6-7.5z',
    },
    heavyTank: {
        label: 'Heavy Tank',
        viewBox: '0 0 15 18',
        width: 15,
        height: 18,
        path: 'M13.2 6.8l-7.5 9.1L7.5 18 15 9z M10.3 3.4l-7.4 9.1 1.8 2.1 7.4-9z M7.5 0L0 9l1.9 2.2 7.4-9z',
    },
    'AT-SPG': {
        label: 'Tank Destroyer',
        viewBox: '0 0 12 10',
        width: 12,
        height: 10,
        path: 'M0 0l6 10 6-10z',
    },
    SPG: {
        label: 'SPG',
        viewBox: '0 0 8 8',
        width: 8,
        height: 8,
        path: 'M0 0h8v8H0z',
    },
};

const known = computed(() => TYPES[props.type]);
</script>

<template>
    <svg
        v-if="known"
        :viewBox="known.viewBox"
        :width="known.width"
        :height="known.height"
        xmlns="http://www.w3.org/2000/svg"
        class="inline-block shrink-0 align-[-0.1em]"
        role="img"
    >
        <title>{{ known.label }}</title>
        <path :d="known.path" fill="currentColor" fill-rule="evenodd" />
    </svg>
</template>
