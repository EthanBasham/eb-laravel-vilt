<script setup>
/**
 * A labelled row inside the filter panel, for the controls that are not chips.
 *
 * Only here to keep the label column the same width as the chip rows above it.
 * A row whose label sat a few pixels off would read as a different kind of
 * control rather than as another filter.
 */
defineProps({
    label: { type: String, required: true },
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5">
        <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">{{ label }}</span>
        <slot />
    </div>
</template>
