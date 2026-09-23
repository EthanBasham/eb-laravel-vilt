<script setup>
/**
 * The row above the article grid: which category is showing, and the two
 * controls that act on the feed as a whole.
 *
 * The category chips are a single choice — an article has one category, so
 * two of them lit would be a filter nobody asked for — which is why All is a
 * chip of its own here rather than the clear button the board filters use.
 * Filtering navigates, since the server does the paging.
 */
defineProps({
    categories: { type: Array, required: true },
    activeCategory: { type: String, default: null },
    pinnedOnly: { type: Boolean, default: false },
    pinnedCount: { type: Number, default: 0 },
    unseenCount: { type: Number, default: 0 },
});

defineEmits(['filter', 'markAllSeen', 'togglePinnedOnly']);

const chip = 'border px-3 py-1.5 text-xs font-bold uppercase tracking-wider transition-colors';
const lit = 'border-wot-gold text-wot-gold';
const unlit = 'border-wot-border text-wot-dim hover:text-wot-text';
</script>

<template>
    <div class="mt-6 flex flex-wrap gap-2">
        <button type="button" :class="[chip, activeCategory ? unlit : lit]" @click="$emit('filter', null)">
            All
        </button>

        <button
            v-for="category in categories"
            :key="category"
            type="button"
            :class="[chip, activeCategory === category ? lit : unlit]"
            @click="$emit('filter', category)"
        >
            {{ category }}
        </button>

        <button
            v-if="unseenCount"
            type="button"
            class="ms-auto border border-wot-border px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-good hover:text-wot-good"
            @click="$emit('markAllSeen')"
        >
            Mark {{ unseenCount }} as seen
        </button>

        <!-- Takes the push to the right itself when there is nothing left to
             mark seen. ms-auto lives on the Mark button, and without that
             button in the row nothing else carried the pinned filter off the
             category chips. -->
        <button
            v-if="pinnedCount || pinnedOnly"
            type="button"
            :class="[chip, pinnedOnly ? lit : unlit, unseenCount ? '' : 'ms-auto']"
            :aria-pressed="pinnedOnly"
            @click="$emit('togglePinnedOnly')"
        >
            📌 Pinned ({{ pinnedCount }})
        </button>
    </div>
</template>
