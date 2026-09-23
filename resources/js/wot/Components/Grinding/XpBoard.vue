<script setup>
import { IconEngine, IconLockOpen } from '@tabler/icons-vue';
import BoardFilterPanel from '../BoardFilterPanel.vue';
import EmptyState from '../EmptyState.vue';
import FilterCheck from '../FilterCheck.vue';
import FilterRow from '../FilterRow.vue';
import TechTreeBoard from '../TechTreeBoard.vue';
import XpCell from './XpCell.vue';
import { n } from '../../lib/format';

/**
 * Research XP, and nothing else. Two figures per cell, each behind its own
 * icon: the tank ahead of you, and the modules under you.
 *
 * The Lines checkbox matches the purchase board's rather than the Free XP
 * board's: a line with nothing left to research is settled in the same sense a
 * bought-out line is, so hiding it by default is the same judgement.
 */
defineProps({
    // The board's state — filters, visible rows and tiers, totals. Owned by the
    // page, because the headline cards read its total whichever tab is up.
    board: { type: Object, required: true },
    // Every tier column the server sent, for the filter row; the board decides
    // which of them the grid gets.
    tiers: { type: Array, required: true },
});
</script>

<template>
    <section class="mt-4" aria-labelledby="xp-heading">
        <h2 id="xp-heading" class="sr-only">XP remaining</h2>

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
                <FilterCheck v-model="board.hide_done">Hide lines with nothing left to research</FilterCheck>
            </FilterRow>

            <!-- The legend earns its place because the two figures in a cell are
                 both bare numbers, and which is which is otherwise only
                 discoverable by hovering. -->
            <div class="flex flex-wrap items-center gap-4 border-t border-wot-border-soft pt-2 text-xs text-wot-dim">
                <span class="inline-flex items-center gap-1.5">
                    <IconEngine :size="15" stroke-width="2" aria-hidden="true" />
                    XP for this tank's modules
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <IconLockOpen :size="15" stroke-width="2" aria-hidden="true" />
                    XP to unlock the next tank — tick the lock once it is researched
                </span>
            </div>
        </BoardFilterPanel>

        <EmptyState v-if="!board.shownRows.length">
            {{ board.hide_done
                ? 'Nothing left to research in the selected nations and tiers.'
                : 'No lines in the selected nations and tiers.' }}
        </EmptyState>

        <TechTreeBoard
            v-else
            total-label="Remaining"
            :rows="board.shownRows"
            :tiers="board.shownTiers"
            :row-total-class="(row) => (board.rowTotal(row) ? 'text-wot-heading' : 'text-wot-dim')"
        >
            <template #cell="{ cell }"><XpCell :cell="cell" /></template>
            <template #rowTotal="{ row }">{{ n(board.rowTotal(row)) }}</template>
            <template #tierTotal="{ tier }">{{ n(board.tierTotal(tier)) }}</template>
            <template #grandTotal>{{ n(board.grandTotal) }}</template>
        </TechTreeBoard>
    </section>
</template>
