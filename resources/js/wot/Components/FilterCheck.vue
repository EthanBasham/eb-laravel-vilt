<script setup>
/**
 * One of the filter panel's checkboxes.
 *
 * A checkbox rather than another chip. The chip rows pick which of many values
 * to show, where a lit/unlit pair reads naturally; these are single on/off
 * decisions, and a label can say outright what ticking one does instead of
 * leaving you to infer it from which state looks active.
 *
 * Checked always means the thing the label says — so where a board's flag is
 * `hide_done`, the label reads "Hide lines…" and the control and the flag
 * agree. A checkbox whose label has to be read backwards is how a filter row
 * ends up with two of them pointing opposite ways.
 */
defineProps({
    modelValue: { type: Boolean, required: true },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <label class="flex items-center gap-2 text-xs text-wot-text">
        <input
            :checked="modelValue"
            type="checkbox"
            class="border"
            @change="$emit('update:modelValue', $event.target.checked)"
        >
        <slot />
    </label>
</template>
