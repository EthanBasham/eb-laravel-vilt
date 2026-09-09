<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    stepId: { type: Number, required: true },
    field: { type: String, required: true },
    modelValue: { type: [Number, String], default: 0 },
    align: { type: String, default: 'text-right' },
});

const raw = ref(String(props.modelValue ?? 0));
const saving = ref(false);
const editing = ref(false);

// Keep in step with server responses — an edit elsewhere can change totals —
// but never while this field is being typed in.
watch(() => props.modelValue, (v) => {
    if (!editing.value) raw.value = String(v ?? 0);
});

const digits = (v) => String(v ?? '').replace(/[^\d]/g, '');

// Grouped while idle, bare while editing. These run to seven figures and are
// genuinely hard to read unseparated, but separators in a field you're typing
// into fight the cursor.
const display = computed(() => (editing.value
    ? raw.value
    : new Intl.NumberFormat().format(Number(digits(raw.value) || 0))));

const onInput = (event) => {
    // Strip as you type rather than validating on submit, so a stray character
    // never reaches the server and never sits in the field looking accepted.
    raw.value = digits(event.target.value);
    event.target.value = raw.value;
};

const focus = () => {
    editing.value = true;
    raw.value = digits(raw.value);
};

const commit = () => {
    editing.value = false;

    const next = Number(digits(raw.value) || 0);

    if (next === Number(props.modelValue ?? 0)) return;

    saving.value = true;
    router.patch(`/wot/grinding/steps/${props.stepId}`, { [props.field]: next }, {
        preserveScroll: true,
        // Only the board comes back; nothing else on the page moved.
        only: ['active', 'targets', 'totals'],
        onFinish: () => (saving.value = false),
    });
};
</script>

<template>
    <!--
        A text input rather than type="number": the spinner arrows are visual
        noise on a dense table, and arrow keys silently nudging a figure is a
        poor fit for numbers that are transcribed from the game rather than
        adjusted. inputmode keeps the numeric keypad on touch devices.
    -->
    <input
        :value="display"
        type="text"
        inputmode="numeric"
        autocomplete="off"
        class="w-24 border border-transparent bg-transparent px-1 py-0.5 tabular-nums transition-colors hover:border-wot-border focus:border-wot-gold"
        :class="[align, saving ? 'opacity-50' : '']"
        :disabled="saving"
        @input="onInput"
        @focus="focus"
        @blur="commit"
        @keyup.enter="$event.target.blur()"
    >
</template>
