<script setup>
import NationFlag from '../NationFlag.vue';

/**
 * Which tank someone is serving in, as a button into the tank picker.
 *
 * A button rather than a thousand-option dropdown: the list is narrowed by
 * nation, tier and type instead of scrolled.
 *
 * Only meaningful while they are in a tank, which is why it disables rather
 * than disappears — the column has to stay the same width down the roster, and
 * the server clears both this and the role when the status moves off it.
 */
defineProps({
    // Already resolved to something printable: the tank's name, 'Choose Tank'
    // when there is none, or 'Loading…' while the deferred vehicle list is
    // still on its way.
    label: { type: String, required: true },
    nation: { type: String, default: '' },
    chosen: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    ariaLabel: { type: String, required: true },
});

defineEmits(['choose']);
</script>

<template>
    <button
        type="button"
        class="inline-flex max-w-44 items-center gap-1.5 border border-wot-border bg-wot-sunken px-2 py-1 text-sm transition-colors hover:border-wot-gold disabled:pointer-events-none disabled:opacity-40"
        :class="chosen ? 'text-wot-text' : 'text-wot-dim'"
        :disabled="disabled"
        :aria-label="ariaLabel"
        @click="$emit('choose')"
    >
        <NationFlag v-if="nation" :nation="nation" />
        <span class="min-w-0 truncate">{{ label }}</span>
    </button>
</template>
