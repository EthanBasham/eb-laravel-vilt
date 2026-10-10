<script setup>
import { onMounted, ref, watch } from 'vue';

/**
 * What a perk is, shown over its icon.
 *
 * A native popover placed from the icon's rectangle, like CellPopover and for
 * the same reason: it is drawn in the top layer, so nothing it sits inside can
 * clip it and it never pushes the row around. Unlike that one it is only ever
 * read — it takes no pointer events, so it cannot get between the pointer and
 * the icon under it, and whoever shows it also takes it away.
 *
 * One of these serves a whole row of icons; `anchor` is whichever is under the
 * pointer or holds focus.
 */
const props = defineProps({
    anchor: { type: Object, required: true },
    name: { type: String, required: true },
    description: { type: String, default: null },
});

const tip = ref(null);
const style = ref({ top: '0px', left: '0px' });

const EDGE = 8;
const GAP = 6;

const place = () => {
    if (!tip.value) {
        return;
    }

    const anchor = props.anchor.getBoundingClientRect();
    const width = tip.value.offsetWidth;
    const height = tip.value.offsetHeight;

    // Above the icon, centred on it; below when there is no room above.
    const top = anchor.top - GAP - height >= EDGE ? anchor.top - GAP - height : anchor.bottom + GAP;
    const left = Math.min(
        Math.max(anchor.left + anchor.width / 2 - width / 2, EDGE),
        Math.max(window.innerWidth - width - EDGE, EDGE),
    );

    style.value = { top: `${top}px`, left: `${left}px` };
};

onMounted(() => {
    tip.value.showPopover();
    place();
});

// The same element is reused as the pointer moves along the row.
watch(() => [props.anchor, props.name], place, { flush: 'post' });
</script>

<template>
    <div
        ref="tip"
        popover="manual"
        role="tooltip"
        class="pointer-events-none inset-auto m-0 w-64 border border-wot-border bg-wot-panel-solid p-2 text-left text-xs text-wot-text shadow-lg"
        :style="style"
    >
        <p class="font-bold text-wot-heading">{{ name }}</p>
        <p v-if="description" class="mt-1">{{ description }}</p>
        <p v-else class="mt-1 italic text-wot-dim">Wargaming's API gives no description for this one.</p>
    </div>
</template>
