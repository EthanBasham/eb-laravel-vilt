<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import PerkTooltip from './PerkTooltip.vue';

/**
 * One role's perks, sorted by hand into two buckets: the green one is what to
 * train, left to right in the order to train it, and the red one is what to
 * leave.
 *
 * Every perk the role can train is always in exactly one of them, so only the
 * green bucket is state. The red one is whatever is left, in the game's own
 * order — it has no order of its own worth keeping, and a perk dropped there
 * goes back to its usual place rather than where it happened to land.
 *
 * Dragging rearranges as it goes: the row under the pointer is already what
 * letting go will save, so there is no insertion marker to read. Leaving the
 * drag anywhere else puts everything back.
 *
 * Native drag and drop does nothing on a touch screen and nothing from the
 * keyboard, so each icon also acts as a button: the arrow keys walk it along
 * the row and across the gap between the buckets, and Enter, Space or a double
 * click sends it straight to the other one.
 *
 * Acts as one, but is not a <button>. Firefox will not start a drag from a
 * button element at all — `draggable` on one is silently ignored — so each is
 * a div given the role, a tab stop and its own key handling.
 */
const props = defineProps({
    // What is included, in order — keys of `perks`.
    included: { type: Array, required: true },
    // Everything this role can train, in the game's order.
    trainable: { type: Array, required: true },
    // The catalogue: { key: { name, description } }.
    perks: { type: Object, required: true },
    // Whose perks these are, for the labels.
    roleName: { type: String, required: true },
});

const emit = defineEmits(['change']);

/*
 * A copy the drag can rearrange freely. It follows the prop whenever the
 * server's answer arrives — except mid-drag, when a reply to an earlier drop
 * would otherwise snatch the row back from under the pointer.
 */
const order = ref([...props.included]);
const dragging = ref(null);

watch(() => props.included, (included) => {
    if (dragging.value === null) {
        order.value = [...included];
    }
});

const excluded = computed(() => props.trainable.filter((perk) => !order.value.includes(perk)));

const same = (a, b) => a.length === b.length && a.every((perk, index) => perk === b[index]);

/** Saves the row as it stands, if that is not what is already saved. */
const commit = () => {
    if (!same(order.value, props.included)) {
        emit('change', [...order.value]);
    }
};

// Dragging

// What the row was when the drag began, to go back to if it is abandoned.
let before = [];

/*
 * Ends the drag, keeping the row as it stands or putting it back.
 *
 * Reached three ways, and only the first to arrive does anything. A drop on
 * either bucket keeps the row. `dragend` on the icon, with no drop before it,
 * means it was let go somewhere else. And a mouse move with no button held is
 * the net under both: rearranging as it goes can move the dragged icon into
 * the other list, which is a new element, and a browser does not always send
 * `dragend` to one that has left the page.
 *
 * The net has to look at the buttons. The move that turns a press into a drag
 * can still be delivered after `dragstart`, with the button down; taking any
 * move at all as the end cancelled every drag the moment it began.
 */
const finish = (keep) => {
    if (dragging.value === null) {
        return;
    }

    if (!keep) {
        order.value = before;
    }

    dragging.value = null;
    window.removeEventListener('mousemove', abandonOnceReleased);
    commit();
};

const abandon = () => finish(false);

const abandonOnceReleased = (event) => {
    if (event.buttons === 0) {
        finish(false);
    }
};

onBeforeUnmount(() => window.removeEventListener('mousemove', abandonOnceReleased));

const startDrag = (perk, event) => {
    dragging.value = perk;
    before = [...order.value];
    hideTip();

    window.addEventListener('mousemove', abandonOnceReleased);

    // Firefox will not start a drag that carries no data.
    event.dataTransfer.setData('text/plain', perk);
    event.dataTransfer.effectAllowed = 'move';
};

const place = (next) => {
    if (!same(next, order.value)) {
        order.value = next;
    }
};

/** Over an included perk: take the place before or after it, by which half. */
const dragOverPerk = (target, event) => {
    if (dragging.value === null || target === dragging.value) {
        return;
    }

    const rest = order.value.filter((perk) => perk !== dragging.value);
    const box = event.currentTarget.getBoundingClientRect();
    const index = rest.indexOf(target) + (event.clientX < box.left + box.width / 2 ? 0 : 1);

    place([...rest.slice(0, index), dragging.value, ...rest.slice(index)]);
};

/** Over the green bucket itself, clear of any perk: the end of the line. */
const dragOverIncluded = (event) => {
    if (dragging.value === null || event.target !== event.currentTarget) {
        return;
    }

    place([...order.value.filter((perk) => perk !== dragging.value), dragging.value]);
};

const dragOverExcluded = () => {
    if (dragging.value !== null) {
        place(order.value.filter((perk) => perk !== dragging.value));
    }
};


// Keyboard and double click

const root = ref(null);

// Crossing the gap moves the icon to the other list, which is a new element.
const refocus = async (perk) => {
    await nextTick();
    root.value?.querySelector(`[data-perk="${perk}"]`)?.focus();
};

const move = (perk, next) => {
    order.value = next;
    commit();
    refocus(perk);
};

const toggle = (perk) => move(perk, order.value.includes(perk)
    ? order.value.filter((item) => item !== perk)
    : [...order.value, perk]);

const step = (perk, direction) => {
    const index = order.value.indexOf(perk);

    // From the red bucket the only way is left, onto the end of the green.
    if (index === -1) {
        if (direction < 0) {
            move(perk, [...order.value, perk]);
        }

        return;
    }

    const target = index + direction;

    if (target < 0) {
        return;
    }

    // Off the right-hand end of the green bucket is the red one.
    if (target >= order.value.length) {
        move(perk, order.value.filter((item) => item !== perk));

        return;
    }

    const next = [...order.value];
    [next[index], next[target]] = [next[target], next[index]];
    move(perk, next);
};

const onKey = (perk, event) => {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        step(perk, event.key === 'ArrowLeft' ? -1 : 1);
    } else if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        toggle(perk);
    }
};

// Tooltip

// { perk, anchor } for the icon under the pointer or holding focus.
const tip = ref(null);

/*
 * Held back for a second, so sweeping the pointer along the row on the way
 * to dragging something does not flash a tooltip over every icon it crosses.
 * Resting on one is what asks for it. Moving to the next icon starts the wait
 * again.
 */
const TIP_DELAY = 1000;

let tipTimer = null;

const hideTip = () => {
    clearTimeout(tipTimer);
    tip.value = null;
};

const showTip = (perk, event) => {
    hideTip();

    if (dragging.value !== null) {
        return;
    }

    // The event's currentTarget is gone by the time the timer fires.
    const anchor = event.currentTarget;

    tipTimer = setTimeout(() => (tip.value = { perk, anchor }), TIP_DELAY);
};

onBeforeUnmount(() => clearTimeout(tipTimer));

const label = (perk) => {
    const position = order.value.indexOf(perk);

    return position === -1
        ? `${props.perks[perk].name}: not trained`
        : `${props.perks[perk].name}: trained ${position + 1} of ${order.value.length}`;
};

const chip = 'relative block cursor-grab select-none border border-transparent p-0.5 transition-opacity hover:border-wot-gold focus-visible:border-wot-gold active:cursor-grabbing';
</script>

<template>
    <div ref="root" class="flex min-w-0 flex-wrap items-stretch gap-2">
        <!-- Green: what to train, in order. Each bucket is only as wide as
             what is in it, so the pair stays one length while the line
             between them moves; an empty one keeps the width of a single
             icon, which is enough to drop onto. -->
        <ol
            role="list"
            class="flex min-h-9 min-w-9 flex-wrap content-center items-center gap-1.5 border border-wot-good/40 bg-wot-good/10 p-1.5"
            :aria-label="`Perks the ${roleName} trains, in order`"
            @dragover.prevent="dragOverIncluded"
            @drop.prevent="finish(true)"
        >
            <li v-for="perk in order" :key="perk">
                <div
                    role="button"
                    tabindex="0"
                    draggable="true"
                    :data-perk="perk"
                    :class="[chip, dragging === perk ? 'opacity-30' : '']"
                    :aria-label="label(perk)"
                    @dragstart="startDrag(perk, $event)"
                    @dragover.prevent="dragOverPerk(perk, $event)"
                    @dragend="abandon"
                    @dblclick="toggle(perk)"
                    @keydown="onKey(perk, $event)"
                    @mouseenter="showTip(perk, $event)"
                    @mouseleave="hideTip"
                    @focus="showTip(perk, $event)"
                    @blur="hideTip"
                >
                    <img
                        :src="`/images/crew-perks/${perk}.png`"
                        alt=""
                        width="52"
                        height="52"
                        class="h-5 w-5"
                        draggable="false"
                    >
                </div>
            </li>
        </ol>

        <!-- Red: what to leave. Faded, so the eye goes to what is being
             trained. -->
        <ul
            role="list"
            class="flex min-h-9 min-w-9 flex-wrap content-center items-center gap-1.5 border border-wot-bad/40 bg-wot-bad/10 p-1.5"
            :aria-label="`Perks the ${roleName} does not train`"
            @dragover.prevent="dragOverExcluded"
            @drop.prevent="finish(true)"
        >
            <li v-for="perk in excluded" :key="perk">
                <div
                    role="button"
                    tabindex="0"
                    draggable="true"
                    :data-perk="perk"
                    :class="[chip, 'opacity-60 grayscale hover:opacity-100 hover:grayscale-0 focus-visible:opacity-100 focus-visible:grayscale-0']"
                    :aria-label="label(perk)"
                    @dragstart="startDrag(perk, $event)"
                    @dragend="abandon"
                    @dblclick="toggle(perk)"
                    @keydown="onKey(perk, $event)"
                    @mouseenter="showTip(perk, $event)"
                    @mouseleave="hideTip"
                    @focus="showTip(perk, $event)"
                    @blur="hideTip"
                >
                    <img
                        :src="`/images/crew-perks/${perk}.png`"
                        alt=""
                        width="52"
                        height="52"
                        class="h-5 w-5"
                        draggable="false"
                    >
                </div>
            </li>
        </ul>

        <PerkTooltip
            v-if="tip && dragging === null"
            :anchor="tip.anchor"
            :name="perks[tip.perk].name"
            :description="perks[tip.perk].description"
        />
    </div>
</template>
