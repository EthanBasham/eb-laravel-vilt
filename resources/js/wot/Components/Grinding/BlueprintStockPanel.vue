<script setup>
import { computed } from 'vue';
import EditableNumber from '../EditableNumber.vue';
import NationFlag from '../NationFlag.vue';

/**
 * Blueprints held, before they are fragments of anything: one count per nation,
 * and the universal stack that spends on any of them. National and universal
 * blueprints are combined to build the fragments counted per vehicle in the
 * grid below.
 *
 * Each flag sits beside its own count, so a figure is read with its nation
 * rather than found by column. The eleven nations take two rows of six at full
 * width, dropping to fewer columns rather than overflowing on a narrow screen;
 * universal gets a row of its own beneath, because it is not a twelfth nation
 * but the stack every nation draws on. Each pair is a <label>, so the flag is a
 * click target for its input and names it for assistive tech.
 *
 * Steppers, like the Recruits & Books counts: these move by a few at a time
 * rather than being retyped.
 */
const props = defineProps({
    stock: { type: Array, default: () => [] },
});

const nationStacks = computed(() => props.stock.filter((stack) => stack.nation !== 'universal'));
const universalStack = computed(() => props.stock.find((stack) => stack.nation === 'universal'));
</script>

<template>
    <div class="mb-3 border border-wot-border bg-wot-panel p-3">
        <h3 class="text-xs font-bold uppercase tracking-wider text-wot-dim">National blueprints</h3>

        <div class="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-3 lg:grid-cols-6">
            <label v-for="stack in nationStacks" :key="stack.nation" class="flex items-center gap-2">
                <NationFlag :nation="stack.nation" />
                <EditableNumber
                    field="quantity"
                    stepper
                    :model-value="stack.quantity"
                    :url="`/wot/grinding/blueprints/${stack.nation}`"
                    :only="['blueprints']"
                />
            </label>
        </div>

        <label v-if="universalStack" class="mt-3 flex items-center gap-2 border-t border-wot-border-soft pt-3">
            <span class="text-xs font-bold uppercase tracking-wider text-wot-gold">Universal</span>
            <EditableNumber
                field="quantity"
                stepper
                :model-value="universalStack.quantity"
                :url="`/wot/grinding/blueprints/${universalStack.nation}`"
                :only="['blueprints']"
            />
        </label>
    </div>
</template>
