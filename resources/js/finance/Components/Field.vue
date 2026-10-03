<script setup>
/**
 * A labelled form field: the label, whatever control is slotted in, then a
 * validation error if there is one or the hint if there is not.
 *
 * A `<label>` wrapping its control, so no id has to be invented to tie the
 * two together and clicking the text focuses the input.
 */
defineProps({
    label: { type: String, required: true },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
    // Printed inside the field's edge: "$" in front, "%" or "/ mo" behind.
    prefix: { type: String, default: '' },
    suffix: { type: String, default: '' },
});
</script>

<template>
    <label class="block">
        <span class="mb-1 block text-xs font-medium text-fin-grey-600">{{ label }}</span>

        <span class="relative block">
            <span v-if="prefix" class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-fin-grey-400">{{ prefix }}</span>

            <span class="fin-field block" :class="{ 'has-prefix': prefix, 'has-suffix': suffix }">
                <slot />
            </span>

            <span v-if="suffix" class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-fin-grey-400">{{ suffix }}</span>
        </span>

        <span v-if="error" class="mt-1 block text-xs text-fin-red-600">{{ error }}</span>
        <span v-else-if="hint" class="mt-1 block text-xs text-fin-grey-500">{{ hint }}</span>
    </label>
</template>

<style>
/*
 * Room for the adornment. Unlayered on purpose: the control's own padding is
 * set in @layer base by FinShell, and an unlayered rule outranks any layered
 * one, so this wins without needing to out-specify it.
 */
.fin-field.has-prefix > input {
    padding-left: 1.625rem;
}

.fin-field.has-suffix > input {
    padding-right: 2.75rem;
}
</style>
