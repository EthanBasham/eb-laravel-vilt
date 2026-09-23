<script setup>
import { roman } from '../../lib/format';

/**
 * The garage table's filter bar.
 *
 * Every control is a v-model back to the table, which owns the state — the
 * counts above the table and the rows below it both read the same values, so
 * there is one copy of them and this is a view onto it.
 *
 * Plain selects rather than the chip rows the boards use: this filters a list
 * of a few hundred vehicles you already own, one dimension at a time, where the
 * boards filter a tree you are looking across. Chips there are a shape you scan;
 * a select here is a choice you make once.
 */
defineProps({
    tiers: { type: Array, required: true },
    nations: { type: Array, required: true },
    types: { type: Array, required: true },
    nationName: { type: Function, required: true },
});

defineEmits(['reset']);

const search = defineModel('search', { type: String, required: true });
const tier = defineModel('tier', { required: true });
const nation = defineModel('nation', { type: String, required: true });
const type = defineModel('type', { type: String, required: true });
const premiumOnly = defineModel('premiumOnly', { type: Boolean, required: true });
const minBattles = defineModel('minBattles', { required: true });

const field = 'mt-1 border border-wot-border bg-wot-sunken px-2 py-1.5 text-sm transition-colors focus:border-wot-gold';
const legend = 'block text-xs font-medium uppercase tracking-wider text-wot-dim';
</script>

<template>
    <div class="flex flex-wrap items-end gap-3 border border-wot-border bg-wot-panel p-4">
        <div class="min-w-48 flex-1">
            <label for="search" :class="legend">Search</label>
            <input id="search" v-model="search" type="search" placeholder="Vehicle name" :class="field" class="w-full">
        </div>

        <div>
            <label for="tier" :class="legend">Tier</label>
            <select id="tier" v-model="tier" :class="field">
                <option value="">All</option>
                <option v-for="t in tiers" :key="t" :value="t">{{ roman(t) }}</option>
            </select>
        </div>

        <div>
            <label for="nation" :class="legend">Nation</label>
            <select id="nation" v-model="nation" :class="field">
                <option value="">All</option>
                <option v-for="slug in nations" :key="slug" :value="slug">{{ nationName(slug) }}</option>
            </select>
        </div>

        <div>
            <label for="type" :class="legend">Type</label>
            <select id="type" v-model="type" :class="field">
                <option value="">All</option>
                <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
            </select>
        </div>

        <div>
            <label for="min-battles" :class="legend">Min battles</label>
            <input id="min-battles" v-model="minBattles" type="number" min="0" step="25" :class="field" class="w-24">
        </div>

        <label class="flex items-center gap-2 pb-2 text-sm text-wot-text">
            <input v-model="premiumOnly" type="checkbox" class="border">
            Premium only
        </label>

        <button type="button" class="pb-2 text-sm font-medium text-wot-gold hover:text-wot-gold-bright" @click="$emit('reset')">
            Reset
        </button>
    </div>
</template>
