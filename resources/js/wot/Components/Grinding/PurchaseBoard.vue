<script setup>
import BoardFilterPanel from '../BoardFilterPanel.vue';
import EmptyState from '../EmptyState.vue';
import FilterCheck from '../FilterCheck.vue';
import FilterRow from '../FilterRow.vue';
import PurchaseCell from './PurchaseCell.vue';
import TechTreeBoard from '../TechTreeBoard.vue';
import { n } from '../../lib/format';
import { salePrice } from '../../lib/sale';

/**
 * Credits only.
 *
 * Every other figure on this page belongs to a different question, and a
 * shopping list that also quotes XP is a shopping list you have to read twice.
 */
defineProps({
    board: { type: Object, required: true },
    rows: { type: Array, required: true },
    tiers: { type: Array, required: true },
});
</script>

<template>
    <section class="mt-4" aria-labelledby="purchase-heading">
        <h2 id="purchase-heading" class="sr-only">Tanks to purchase</h2>

        <EmptyState v-if="!rows.length">
            Nothing left to buy. Every tracked line has been bought out.
        </EmptyState>

        <template v-else>
            <BoardFilterPanel
                :nations="board.nations"
                :tiers="tiers"
                :hidden-nations="board.hiddenNations"
                :hidden-tiers="board.hiddenTiers"
                @toggle-nation="board.toggleNation"
                @clear-nations="board.clearNations"
                @toggle-tier="board.toggleTier"
                @clear-tiers="board.clearTiers"
            >
                <FilterRow v-if="board.hasDoneLines" label="Lines">
                    <FilterCheck v-model="board.hide_owned">Hide lines that are fully owned</FilterCheck>
                </FilterRow>

                <FilterRow label="Prices">
                    <FilterCheck v-model="board.show_sale">Show discounted prices</FilterCheck>
                </FilterRow>
            </BoardFilterPanel>

            <EmptyState v-if="!board.shownRows.length">
                Nothing left to buy in the selected nations and tiers.
            </EmptyState>

            <TechTreeBoard
                v-else
                total-label="Remaining"
                :rows="board.shownRows"
                :tiers="board.shownTiers"
                :row-total-class="() => 'font-bold text-wot-heading'"
            >
                <template #cell="{ cell }">
                    <PurchaseCell
                        :cell="cell"
                        :price="salePrice(cell, board.show_sale)"
                        :show-sale="board.show_sale"
                    />
                </template>

                <template #rowTotal="{ row }">{{ n(board.rowTotal(row)) }}</template>
                <template #tierTotal="{ tier }">{{ n(board.tierTotal(tier)) }}</template>
                <template #grandTotal>{{ n(board.grandTotal) }}</template>
            </TechTreeBoard>
        </template>
    </section>
</template>
