<script setup>
import { nextTick, ref, watch } from 'vue';

/**
 * Every modal in the sub-project.
 *
 * A native `<dialog>` opened with `showModal()`, which puts it in the top
 * layer, makes the page behind it inert and handles Escape — the things a
 * hand-built overlay would have to reimplement. The same approach as the
 * World of Tanks side's WotDialog, restyled; see that file for the long
 * version of why each line is here.
 *
 * Closing is the element's job: `close()` asks the browser, the browser fires
 * `close` whether it came from a button, the backdrop or Escape, and only
 * then does the parent hear about it.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    wide: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const dialog = ref(null);

watch(() => props.open, async (open) => {
    if (!open) return;

    // The element is behind a v-if on the value being watched, so it does
    // not exist until the next tick.
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
        class="fin fin-dialog"
        :class="{ 'fin-dialog--wide': wide }"
        :aria-label="title"
        @click.self="close"
        @close="emit('close')"
    >
        <div class="p-6">
            <h2 v-if="title" class="mb-5 text-lg font-semibold tracking-tight text-fin-black">{{ title }}</h2>

            <slot :close="close" />
        </div>
    </dialog>
</template>
