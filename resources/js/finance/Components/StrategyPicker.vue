<script setup>
/**
 * The row of pills that picks which strategy a "closer look" card is looking
 * at: one a strategy, each with its colour from the charts above.
 *
 * `v-model` is the id of the one picked.
 */
defineProps({
    strategies: { type: Array, required: true },
    colorOf: { type: Function, required: true },
    // What a strategy is called, which is not the same field on every tab.
    label: { type: Function, default: (strategy) => strategy.name },
});

const selectedId = defineModel({ type: Number, default: null });
</script>

<template>
    <div class="flex flex-wrap gap-1.5" role="group" aria-label="Strategy to look at">
        <button
            v-for="(strategy, index) in strategies" :key="strategy.id" type="button"
            class="fin-pill" :aria-pressed="strategy.id === selectedId" @click="selectedId = strategy.id"
        >
            <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: colorOf(index) }" aria-hidden="true" />
            {{ label(strategy) }}
        </button>
    </div>
</template>
