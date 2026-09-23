<script setup>
import { computed, ref } from 'vue';
import BlueprintCell from './BlueprintCell.vue';
import BlueprintPlanner from '../BlueprintPlanner.vue';
import BlueprintStockPanel from './BlueprintStockPanel.vue';
import BoardFilterPanel from '../BoardFilterPanel.vue';
import EmptyState from '../EmptyState.vue';
import FilterCheck from '../FilterCheck.vue';
import FilterRow from '../FilterRow.vue';
import TechTreeBoard from '../TechTreeBoard.vue';
import { n } from '../../lib/format';

/**
 * What each vehicle costs, and how far its blueprint has come.
 *
 * A cell reads "built / needed = XP to research" over the blueprints its plan
 * would spend, and opens a planner. The figures behind it are hand-transcribed
 * — the encyclopedia publishes neither the fragments a tank needs nor the
 * discount they buy — and the XP Remaining board still keeps the cost a player
 * read off the game screen, with the derived figure printed beside it.
 */
const props = defineProps({
    board: { type: Object, required: true },
    rows: { type: Array, required: true },
    tiers: { type: Array, required: true },
    // Blueprints held, per nation plus the universal stack. Handed to the
    // planner as well, so each line can say what is in the stack it would
    // spend.
    stock: { type: Array, default: () => [] },
});

/*
 * The vehicle the planner is open for, held as a tank id rather than as the
 * cell itself.
 *
 * Crews.vue stores the cell object and gets away with it because CrewEditor
 * saves once and closes. This one stays open across several writes, and every
 * one of them reloads `blueprints` wholesale — so a stored object would be a
 * snapshot from before the edit, showing the figures the save was meant to
 * change. Looking the cell up again on each render is what keeps it live.
 *
 * The owning cell wins: a shared vehicle is edited where it is counted.
 */
const editing = ref(null);

const editingCell = computed(() => {
    if (editing.value === null) {
        return null;
    }

    for (const row of props.rows) {
        for (const cell of Object.values(row.cells)) {
            if (cell.tank_id === editing.value && !cell.is_shared) {
                return cell;
            }
        }
    }

    return null;
});
</script>

<template>
    <section class="mt-4" aria-labelledby="blueprints-heading">
        <h2 id="blueprints-heading" class="sr-only">Blueprints</h2>

        <BlueprintStockPanel :stock="stock" />

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
            <!-- Fragments only discount something you have yet to research, so a
                 line you have finished is a line they cannot help. Same polarity
                 as XP Remaining's checkbox, and on by default for the same
                 reason. -->
            <FilterRow v-if="board.hasDoneLines" label="Lines">
                <FilterCheck v-model="board.hide_done">Hide lines with nothing left to research</FilterCheck>
            </FilterRow>
        </BoardFilterPanel>

        <EmptyState v-if="!board.shownRows.length">
            {{ board.hide_done
                ? 'Nothing left to research in the selected nations and tiers.'
                : 'No lines in the selected nations and tiers.' }}
        </EmptyState>

        <TechTreeBoard
            v-else
            total-label="Held"
            :rows="board.shownRows"
            :tiers="board.shownTiers"
            :row-total-class="(row) => (board.rowTotal(row) ? 'text-wot-gold' : 'text-wot-dim')"
        >
            <template #cell="{ cell }">
                <BlueprintCell :cell="cell" @edit="editing = $event" />
            </template>

            <template #rowTotal="{ row }">{{ n(board.rowTotal(row)) }}</template>
            <template #tierTotal="{ tier }">{{ n(board.tierTotal(tier)) }}</template>
            <template #grandTotal>{{ n(board.grandTotal) }}</template>
        </TechTreeBoard>

        <BlueprintPlanner :cell="editingCell" :stock="stock" @close="editing = null" />
    </section>
</template>
