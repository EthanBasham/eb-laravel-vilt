<script setup>
import { computed } from 'vue';

/**
 * A tanker's portrait: helmet, goggles, headset and all.
 *
 * The two drawings are files in public/images/crew, traced from line art the
 * owner supplied, and are far too detailed to write out as paths here — about
 * 20 KB each, which would otherwise ride along in the bundle on every page.
 *
 * They are shown as a CSS mask over a block of currentColor rather than as an
 * <img>, because an <img> cannot be recoloured and the cards tell the two
 * apart by colour as much as by drawing. The card sets the colour with a text
 * class, as it would for any other icon.
 *
 * Each comes in two versions: the plain one has the face left blank, and
 * `face` shows the one with eyes, nose and mouth drawn in (the -face files).
 * Blank is the default — at this size the features are mostly noise.
 *
 * Several simpler hand-drawn sets came before these (plain busts, and three
 * attempts at a helmet that read as a hard hat at this size).
 */
const props = defineProps({
    gender: { type: String, required: true },
    face: { type: Boolean, default: false },
    size: { type: Number, default: 56 },
});

const style = computed(() => {
    const mask = `url(/images/crew/tanker-${props.gender === 'female' ? 'female' : 'male'}${props.face ? '-face' : ''}.svg) center / contain no-repeat`;

    return {
        width: `${props.size}px`,
        height: `${props.size}px`,
        backgroundColor: 'currentColor',
        mask,
        WebkitMask: mask,
    };
});
</script>

<template>
    <span class="inline-block" :style="style"></span>
</template>
