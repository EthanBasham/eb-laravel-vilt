<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    field: { type: String, required: true },
    modelValue: { type: [Number, String], default: 0 },
    align: { type: String, default: 'text-right' },
    // Every field on this page edits a tank, so there is no default worth
    // guessing at — only which of them the caller happens to be editing.
    url: { type: String, required: true },
    only: { type: Array, default: () => ['active', 'totals'] },
    /*
     * Resting border, background and text colours, as utility classes.
     *
     * They live here rather than in the base class because two utilities
     * setting the same property on one element are decided by their order in
     * the generated stylesheet, not by their order in the attribute — so a
     * caller could not reliably override a hardcoded one.
     *
     * The default restates the shell's own form-control colours rather than
     * leaving them to be inherited. AppShell sets those in @layer base, which
     * a utility now overrides, so a field that named no tone would otherwise
     * come out transparent instead of sunken.
     */
    tone: { type: String, default: 'border-wot-border bg-wot-sunken' },
    /*
     * Optional optimistic update, as (pageProps, nextValue) => partialProps.
     *
     * Supplied by the parent rather than built here: applying one of these
     * means knowing the shape of the props the field feeds, and this component
     * is used against four different boards. Keeping that knowledge at the call
     * site is what lets it stay usable against any of them.
     */
    optimistic: { type: Function, default: null },
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

    const options = {
        preserveScroll: true,
        // Only the board comes back; nothing else on the page moved.
        only: props.only,
        onFinish: () => (saving.value = false),
    };

    if (props.optimistic) {
        options.optimistic = (pageProps) => props.optimistic(pageProps, next);
    }

    router.patch(props.url, { [props.field]: next }, options);
};
</script>

<template>
    <!--
        A text input rather than type="number": the spinner arrows are visual
        noise on a dense table, and arrow keys silently nudging a figure is a
        poor fit for numbers that are transcribed from the game rather than
        adjusted. inputmode keeps the numeric keypad on touch devices.

        text-sm is not redundant with the surrounding table. @tailwindcss/forms
        puts font-size: 1rem on text inputs in the base layer, so these did not
        inherit the table's 14px and rendered a size larger than every figure
        beside them. The utility overrides it, and brings the line-height down
        with it — which is what makes the group shorter, since the buttons take
        their height from this field.

        w-20 is measured rather than guessed: Instrument Sans at 14px puts a
        seven-figure price ("6,100,000") at 67.6px of text, 77.6px once px-1 and
        the border are counted, so 80px holds every realistic price.
    -->
    <input
        :value="display"
        type="text"
        inputmode="numeric"
        autocomplete="off"
        class="w-20 border px-1 py-0.5 text-sm tabular-nums transition-colors focus:border-wot-gold"
        :class="[align, tone, saving ? 'opacity-50' : '']"
        :disabled="saving"
        @input="onInput"
        @focus="focus"
        @blur="commit"
        @keyup.enter="$event.target.blur()"
    >
</template>
