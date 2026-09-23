<script setup>
import { nextTick, ref, watch } from 'vue';

/**
 * Every modal in the app.
 *
 * Native `<dialog>`, opened with `showModal()` rather than the `open`
 * attribute: `open` renders the dialog in normal flow, so it lands at the foot
 * of the page with no backdrop and no focus trap. `showModal()` puts it in the
 * top layer, where the browser centres it, makes the page behind it inert, and
 * supplies Escape handling — all of which a hand-rolled overlay would owe us.
 *
 * Six components carried their own copy of that paragraph and the four lines
 * under it. The lines are subtle enough to be worth having once: `nextTick`
 * because the element is behind a `v-if` on the same value this watches, so it
 * does not exist yet when the watcher fires; the `open` guard because a dialog
 * that is already showing throws if asked again, and a modal that stays open
 * across several writes gets asked again every time one lands.
 *
 * Closing is the element's job, not a prop's. `close()` asks the browser to
 * close it, the browser fires `close` — whether that came from the button, the
 * backdrop or Escape — and only then does the parent hear about it. A parent
 * that cleared its own state instead would miss two of those three.
 */
const props = defineProps({
    /*
     * Whether the dialog should be showing. Usually the thing it is open *for*
     * coerced to a boolean — a cell, an event — so that closing it and
     * forgetting what it held are one action in the parent.
     */
    open: { type: Boolean, default: false },
    label: { type: String, default: '' },
    // Wide enough for a table or a grid of chips; the default suits a column of
    // text and a pair of buttons.
    wide: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const dialog = ref(null);

watch(() => props.open, async (open) => {
    if (!open) {
        return;
    }

    await nextTick();

    if (!dialog.value?.open) {
        dialog.value?.showModal();
    }
}, { immediate: true });

const close = () => dialog.value?.close();

defineExpose({ close });
</script>

<template>
    <dialog
        v-if="open"
        ref="dialog"
        class="modal modal--dark"
        :class="wide ? 'modal--wide' : ''"
        :aria-label="label"
        @click.self="close"
        @close="emit('close')"
    >
        <div class="border border-wot-border bg-wot-panel-solid p-6">
            <!-- `close` goes to the slot so a footer button can close the
                 dialog without the page around it holding a ref to do it. -->
            <slot :close="close" />
        </div>
    </dialog>
</template>
