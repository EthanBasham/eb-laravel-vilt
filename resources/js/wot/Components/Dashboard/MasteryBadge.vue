<script setup>
import { MASTERY_NAMES } from '../../lib/achievements';

/**
 * A mastery badge, drawn from Wargaming's own art.
 *
 * The intrinsic size is always the source's 67×71 so the browser reserves the
 * right box before the image lands; the displayed height is the caller's, since
 * this appears both as a garage row's marker and as the subject of its own
 * panel.
 */
defineProps({
    // 'third' | 'second' | 'first' | 'ace'
    badge: { type: String, required: true },
});
</script>

<template>
    <img
        :src="`/images/wot/achievements/mastery-${badge}.png`"
        :alt="MASTERY_NAMES[badge]"
        :title="MASTERY_NAMES[badge]"
        width="67"
        height="71"
        loading="lazy"
        decoding="async"
    >
</template>
