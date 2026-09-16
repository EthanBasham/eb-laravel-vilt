<script setup>
import { router } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

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
    /*
     * A small count rather than a transcribed figure, for the Recruits & Books
     * tables. Still a plain text field — up/down chevrons were tried here and
     * taken back out.
     *
     * The field shows bare digits rather than grouped ones, selects its
     * contents only on the click that focuses it, and is never disabled while
     * a save is in flight.
     */
    stepper: { type: Boolean, default: false },
    /*
     * A minus and a plus flanking the field, for a count that is nudged rather
     * than transcribed — a fragment at a time against a blueprint.
     *
     * Not the chevrons that were tried and reverted here: those were the
     * browser's own spin buttons on an input[type=number], and the digits sat
     * hard against them because padding lands outside a ::-webkit-inner-spin-
     * button and a margin on it drew no space. These are ordinary buttons
     * outside the input, so the gap is the gap between two elements.
     *
     * Implies the stepper's own manners — bare digits, debounced saves — and is
     * only used alongside it.
     */
    buttons: { type: Boolean, default: false },
    // The range a button will step within. The server bounds these too; this is
    // so a button never offers a figure it knows would be refused.
    min: { type: Number, default: 0 },
    max: { type: Number, default: null },
});

const raw = ref(String(props.modelValue ?? 0));
const saving = ref(false);
const editing = ref(false);

/*
 * Whether a stepped value is written here but not yet saved.
 *
 * A button applies its step at once and saves after a pause, so for that moment
 * the field is ahead of the server. Without this the reload from some other
 * edit on the page would arrive carrying the old figure and undo the click.
 */
const pending = ref(false);

// Keep in step with server responses — an edit elsewhere can change totals —
// but never while this field is being typed in or holds an unsaved step.
watch(() => props.modelValue, (v) => {
    if (!editing.value && !pending.value) raw.value = String(v ?? 0);
});

const digits = (v) => String(v ?? '').replace(/[^\d]/g, '');

// Grouped while idle, bare while editing. These run to seven figures and are
// genuinely hard to read unseparated, but separators in a field you're typing
// into fight the cursor.
const display = computed(() => (editing.value || props.stepper
    ? raw.value
    : new Intl.NumberFormat().format(Number(digits(raw.value) || 0))));

const onInput = (event) => {
    // Strip as you type rather than validating on submit, so a stray character
    // never reaches the server and never sits in the field looking accepted.
    raw.value = digits(event.target.value);
    event.target.value = raw.value;
};

/*
 * Focusing a field selects what is in it, so a click and a keystroke replace
 * the figure. These hold numbers transcribed off the game's own screen — you
 * are always writing a new one, never amending a digit of the old.
 *
 * After nextTick because focusing switches the field from its grouped display
 * to bare digits, and selecting before Vue has written that would select the
 * string that is about to be replaced.
 *
 * Bound to click as well as focus: a click's mouseup lands after the focus
 * event and would otherwise drop the selection, leaving a caret where the
 * pointer was. Focus alone covers tabbing in.
 */
const selectAll = (event) => nextTick(() => event.target.select());

/*
 * Whether the next click is the one that put focus here. A stepper only selects
 * on that click: once the field has focus, a later click is placing the caret,
 * and re-selecting would take that away.
 */
let focusedByClick = false;

const focus = (event) => {
    editing.value = true;
    raw.value = digits(raw.value);
    focusedByClick = true;

    selectAll(event);
};

const click = (event) => {
    if (!props.stepper || focusedByClick) {
        selectAll(event);
    }

    focusedByClick = false;
};

/*
 * A stepper's change event schedules a save after a short pause.
 *
 * On a text field change only fires as the field is left, and blur commits at
 * once and cancels the timer, so in practice this rarely waits. The value sent
 * is absolute, so a request overtaken by a later one can never leave the count
 * wrong.
 */
let pendingSave;

const scheduleSave = () => {
    if (!props.stepper && !props.buttons) {
        return;
    }

    clearTimeout(pendingSave);
    pendingSave = setTimeout(save, 400);
};

const value = () => Number(digits(raw.value) || 0);

const atMin = computed(() => value() <= props.min);
const atMax = computed(() => props.max !== null && value() >= props.max);

/*
 * One step, applied here and saved after the same pause a typed change gets, so
 * holding a button down sends one request rather than one per click.
 */
const step = (delta) => {
    const next = Math.max(props.min, Math.min(props.max ?? Infinity, value() + delta));

    if (next === value()) {
        return;
    }

    raw.value = String(next);
    pending.value = true;

    scheduleSave();
};

const commit = () => {
    editing.value = false;
    clearTimeout(pendingSave);

    save();
};

const save = () => {
    const next = value();

    if (next === Number(props.modelValue ?? 0)) {
        pending.value = false;

        return;
    }

    saving.value = true;

    const options = {
        preserveScroll: true,
        // Only the board comes back; nothing else on the page moved.
        only: props.only,
        onFinish: () => {
            saving.value = false;
            pending.value = false;
        },
    };

    if (props.optimistic) {
        options.optimistic = (pageProps) => props.optimistic(pageProps, next);
    }

    router.patch(props.url, { [props.field]: next }, options);
};
</script>

<template>
    <!--
        A wrapper that only exists when there are buttons to wrap. Without them
        it is display:contents, so the input sits in the caller's own flex or
        table layout exactly as it did before this span was here — every other
        caller places it as a bare field and lays it out itself.
    -->
    <span :class="buttons ? 'inline-flex items-stretch' : 'contents'">
        <button
            v-if="buttons"
            type="button"
            class="border border-e-0 border-wot-border bg-wot-sunken px-2 text-sm leading-none text-wot-dim transition-colors hover:border-wot-gold hover:text-wot-gold disabled:opacity-30 disabled:hover:border-wot-border disabled:hover:text-wot-dim"
            :disabled="atMin"
            aria-label="One fewer"
            @click="step(-1)"
        >
            −
        </button>

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
            the border are counted, so 80px holds every realistic price. A field
            with buttons either side is a count rather than a price, and takes
            the narrower width the two chrome elements leave room for.
        -->
        <!-- A stepper is never disabled while saving: its saves are debounced, and
             disabling the field mid-save would eat the next chevron click. -->
        <input
            :value="display"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            class="border py-0.5 text-sm tabular-nums transition-colors focus:border-wot-gold"
            :class="[
                align,
                tone,
                buttons ? 'w-10' : 'w-20',
                stepper ? 'box-border ps-1 pe-1' : 'px-1',
                saving && !stepper ? 'opacity-50' : '',
            ]"
            :disabled="saving && !stepper"
            @input="onInput"
            @change="scheduleSave"
            @focus="focus"
            @click="click"
            @blur="commit"
            @keyup.enter="$event.target.blur()"
        >

        <button
            v-if="buttons"
            type="button"
            class="border border-s-0 border-wot-border bg-wot-sunken px-2 text-sm leading-none text-wot-dim transition-colors hover:border-wot-gold hover:text-wot-gold disabled:opacity-30 disabled:hover:border-wot-border disabled:hover:text-wot-dim"
            :disabled="atMax"
            aria-label="One more"
            @click="step(1)"
        >
            +
        </button>
    </span>
</template>
