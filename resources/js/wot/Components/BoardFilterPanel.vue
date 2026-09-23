<script setup>
import FilterChipRow from './FilterChipRow.vue';
import NationFlag from './NationFlag.vue';
import { roman } from '../lib/format';

/**
 * The filter panel every tech-tree board wears: nation, tier, and then whatever
 * else that board asks.
 *
 * Nation and tier are here because all five boards filter by them in exactly
 * the same way, against the same stored shape — see useBoardFilters. What a
 * board adds below them is its own, and goes in the slot: the checkbox each one
 * carries means something different enough that they do not share a default,
 * let alone a control.
 *
 * Both rows are held as what is *hidden* rather than what is selected, so
 * everything starts on and stays on: buying a tank can retire a nation or
 * collapse a tier column, and a selected-set would then have to guess whether a
 * column reappearing later was meant to be on.
 */
defineProps({
    // Only the nations present on this board, in tech-tree order.
    nations: { type: Array, required: true },
    tiers: { type: Array, required: true },
    hiddenNations: { type: Array, required: true },
    hiddenTiers: { type: Array, required: true },
});

defineEmits(['toggleNation', 'clearNations', 'toggleTier', 'clearTiers']);
</script>

<template>
    <div class="mb-3 space-y-2 border border-wot-border bg-wot-panel p-3">
        <FilterChipRow
            label="Nation"
            variant="flag"
            :items="nations"
            :selected="hiddenNations"
            @toggle="$emit('toggleNation', $event)"
            @clear="$emit('clearNations')"
        >
            <template #default="{ item }">
                <NationFlag :nation="item" />
            </template>
        </FilterChipRow>

        <FilterChipRow
            label="Tier"
            :items="tiers"
            :selected="hiddenTiers"
            :item-label="(tier) => `Tier ${roman(tier)}`"
            @toggle="$emit('toggleTier', $event)"
            @clear="$emit('clearTiers')"
        >
            <template #default="{ item }">{{ roman(item) }}</template>
        </FilterChipRow>

        <slot />
    </div>
</template>
