<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    suffix: { type: String, default: '' },
    hint: { type: String, default: '' },
    tone: { type: String, default: 'default' }, // default | good | bad | gold
});

const toneClasses = {
    default: 'text-wot-heading',
    good: 'text-wot-good',
    bad: 'text-wot-bad',
    gold: 'text-wot-gold',
};
</script>

<template>
    <div class="border border-wot-border bg-wot-panel p-4 transition-colors hover:border-wot-blue-deep">
        <dt class="text-xs font-medium uppercase tracking-wider text-wot-dim">{{ label }}</dt>
        <dd class="mt-1 text-2xl tabular-nums" :class="toneClasses[tone]">
            {{ value }}<span v-if="suffix" class="text-base text-wot-dim">{{ suffix }}</span>
        </dd>
        <p v-if="hint" class="mt-1 text-xs text-wot-dim">{{ hint }}</p>
    </div>
</template>
