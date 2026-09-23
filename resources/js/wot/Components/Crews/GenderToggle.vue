<script setup>
/**
 * Male or female, as two letters rather than a dropdown.
 *
 * Two values with one letter each, so they sit out in the open like the crew
 * editor's zero-skills switch rather than behind a select that has to be opened
 * to be read. Real radios under the labels: the grouping, the arrow keys and
 * the announcement all come free.
 */
defineProps({
    genders: { type: Object, required: true },
    modelValue: { type: String, required: true },
    // Radios group by name, so each row needs its own or the whole roster
    // behaves as one control.
    group: { type: String, required: true },
    legend: { type: String, required: true },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <fieldset class="flex gap-1">
        <legend class="sr-only">{{ legend }}</legend>

        <label
            v-for="(gender, key) in genders"
            :key="key"
            class="cursor-pointer border px-2.5 py-0.5 text-xs font-bold transition-colors focus-within:ring-1 focus-within:ring-wot-gold"
            :class="modelValue === key
                ? 'border-wot-gold text-wot-gold'
                : 'border-wot-border text-wot-dim hover:text-wot-text'"
            :title="gender.name"
        >
            <input
                type="radio"
                class="sr-only"
                :name="group"
                :value="key"
                :checked="modelValue === key"
                @change="$emit('update:modelValue', key)"
            >
            {{ gender.letter }}
        </label>
    </fieldset>
</template>
