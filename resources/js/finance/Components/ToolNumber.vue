<script setup>
import Field from './Field.vue';

/**
 * One numeric input on a tool's form.
 *
 * `v-model.lazy` would wait for blur; the tools recalculate as you type (see
 * useToolQuery, which debounces), so this binds on input and leaves an empty
 * field as an empty string — the server reads that as "use the default"
 * rather than as zero.
 */
defineProps({
    label: { type: String, required: true },
    prefix: { type: String, default: '' },
    suffix: { type: String, default: '' },
    hint: { type: String, default: '' },
    step: { type: [Number, String], default: 'any' },
    min: { type: [Number, String], default: null },
    max: { type: [Number, String], default: null },
});

const model = defineModel({ type: [Number, String], default: '' });
</script>

<template>
    <Field :label="label" :prefix="prefix" :suffix="suffix" :hint="hint">
        <input v-model="model" type="number" :step="step" :min="min" :max="max">
    </Field>
</template>
