<script setup>
/**
 * One figure with a label over it.
 *
 * Two densities, because the two places these appear are asking for different
 * things. The dashboard's are the page's subject — eight tiles reporting a
 * career — so they are given room. A board page's four sit above a table that
 * is the actual subject, and a card there is a summary you read on the way
 * past, so it is `dense`.
 */
defineProps({
    label: { type: String, required: true },
    // Ignored when the default slot is used, which is how a tile carrying two
    // figures ("banked / remaining") spells the pair out.
    value: { type: [String, Number], default: '' },
    suffix: { type: String, default: '' },
    hint: { type: String, default: '' },
    tone: { type: String, default: 'default' }, // default | good | bad | gold
    dense: { type: Boolean, default: false },
});

const toneClasses = {
    default: 'text-wot-heading',
    good: 'text-wot-good',
    bad: 'text-wot-bad',
    gold: 'text-wot-gold',
};
</script>

<template>
    <div
        class="border border-wot-border bg-wot-panel"
        :class="dense ? 'p-3' : 'p-4 transition-colors hover:border-wot-blue-deep'"
    >
        <dt class="text-xs uppercase tracking-wider text-wot-dim" :class="dense ? '' : 'font-medium'">
            {{ label }}
        </dt>

        <dd class="mt-1 tabular-nums" :class="[dense ? 'text-xl' : 'text-2xl', toneClasses[tone]]">
            <slot>
                {{ value }}<span v-if="suffix" class="text-base text-wot-dim">{{ suffix }}</span>
            </slot>
        </dd>

        <p v-if="hint" class="mt-1 text-xs text-wot-dim">{{ hint }}</p>
    </div>
</template>
