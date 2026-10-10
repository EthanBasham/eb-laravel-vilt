<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * A panel that drops down from a control inside a table cell, without being
 * clipped by the table.
 *
 * Every board is wrapped in `overflow-x-auto`, which is what lets it scroll
 * sideways under its sticky line and total columns. That wrapper also clips
 * anything positioned inside it — and a box that scrolls on one axis scrolls on
 * both, so a panel hanging below the last rows did not spill over the page, it
 * gave the table a vertical scrollbar and had to be scrolled to inside it.
 *
 * So the panel leaves the table rather than the table giving up its scrolling.
 * It is a native popover, which the browser draws in the top layer: above
 * everything, clipped by nothing, and still where it was in the document, so
 * it keeps the fonts and colours it inherits and Vue keeps rendering it in
 * place. The cost is that the top layer positions against the viewport, not
 * against the cell, so where it goes is worked out here from the anchor's
 * rectangle and worked out again whenever either might have moved.
 *
 * `manual` rather than `auto`. An auto popover closes itself on a press
 * anywhere outside it — including on the button that opened it, whose click
 * then arrives and opens it again. Closing is three lines and worth owning.
 *
 * Rendered behind a `v-if` by the control that owns it: it shows itself when
 * mounted and asks to be closed by emitting, so a board of several hundred
 * cells carries no hidden panels.
 */
const props = defineProps({
    // The control it hangs from. Its right edge is the panel's right edge.
    anchor: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const panel = ref(null);
const style = ref({ top: '0px', left: '0px' });

// Clear of the viewport's edge, and of the anchor it hangs from.
const EDGE = 8;
const GAP = 4;

const place = () => {
    if (!panel.value || !props.anchor) {
        return;
    }

    const anchor = props.anchor.getBoundingClientRect();
    const width = panel.value.offsetWidth;
    const height = panel.value.scrollHeight;

    const below = window.innerHeight - anchor.bottom - GAP - EDGE;
    const above = anchor.top - GAP - EDGE;

    // Below by choice. Above only when it does not fit below and would fit
    // better there; and where it fits neither way, it takes the roomier side
    // and scrolls inside itself, since a panel in the top layer cannot be
    // reached by scrolling the page.
    const goesAbove = height > below && above > below;
    const room = Math.max(goesAbove ? above : below, 120);
    const shown = Math.min(height, room);

    const left = Math.min(
        Math.max(anchor.right - width, EDGE),
        Math.max(window.innerWidth - width - EDGE, EDGE),
    );

    style.value = {
        top: `${goesAbove ? anchor.top - GAP - shown : anchor.bottom + GAP}px`,
        left: `${left}px`,
        maxHeight: `${room}px`,
    };
};

const closeOnOutsidePress = (event) => {
    if (panel.value?.contains(event.target) || props.anchor?.contains(event.target)) {
        return;
    }

    emit('close');
};

const closeOnEscape = (event) => {
    if (event.key === 'Escape') {
        emit('close');
    }
};

let observer = null;

onMounted(() => {
    panel.value.showPopover();
    place();

    // Capture, because scroll does not bubble and the scroll that moves the
    // anchor is as likely to be the table's as the page's.
    window.addEventListener('scroll', place, { capture: true, passive: true });
    window.addEventListener('resize', place);
    document.addEventListener('pointerdown', closeOnOutsidePress, true);
    document.addEventListener('keydown', closeOnEscape);

    // Its own size changes too: ticking a module can add or remove a button.
    observer = new ResizeObserver(place);
    observer.observe(panel.value);
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', place, { capture: true });
    window.removeEventListener('resize', place);
    document.removeEventListener('pointerdown', closeOnOutsidePress, true);
    document.removeEventListener('keydown', closeOnEscape);
    observer?.disconnect();
});
</script>

<template>
    <!--
        The browser gives a popover its own colours, border, padding and
        centring (inset: 0; margin: auto). Each is set back here; the text
        colour in particular would otherwise be the system's black rather than
        the page's.
    -->
    <div
        ref="panel"
        popover="manual"
        class="inset-auto m-0 w-72 overflow-y-auto border border-wot-border bg-wot-panel-solid p-2 text-left text-wot-text shadow-lg"
        :style="style"
    >
        <slot />
    </div>
</template>
