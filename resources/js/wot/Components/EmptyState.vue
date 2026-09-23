<script setup>
/**
 * "There is nothing here, and here is why."
 *
 * A dashed border rather than the solid one every panel with content wears, so
 * an empty board reads as an outline waiting to be filled rather than as a
 * panel that failed to draw. Sixteen copies of the same paragraph said this
 * before; the wording is what differs between them, which is what the slot is
 * for.
 */
defineProps({
    // The pickers sit inside a panel that is already padded, where p-8 leaves
    // the message floating in the middle of a box twice the size it needs.
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <p
        class="border border-dashed border-wot-border text-center text-sm text-wot-dim"
        :class="compact ? 'p-6' : 'p-8'"
    >
        <slot />
    </p>
</template>
