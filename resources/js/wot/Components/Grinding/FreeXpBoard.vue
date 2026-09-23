<script setup>
import BoardFilterPanel from '../BoardFilterPanel.vue';
import EmptyState from '../EmptyState.vue';
import FilterCheck from '../FilterCheck.vue';
import FilterRow from '../FilterRow.vue';
import ModulePlanPicker from '../ModulePlanPicker.vue';
import TechTreeBoard from '../TechTreeBoard.vue';
import { n } from '../../lib/format';

/**
 * Modules, and nothing else.
 *
 * Laid out like Tanks to Purchase because it asks the same shape of question
 * against the same tree, but a cell here is a list to tick rather than a price
 * to pay — there is no discount, no owned state, and nothing to type.
 */
defineProps({
    board: { type: Object, required: true },
    rows: { type: Array, required: true },
    tiers: { type: Array, required: true },
});
</script>

<template>
    <section class="mt-4" aria-labelledby="freexp-heading">
        <h2 id="freexp-heading" class="sr-only">Free XP</h2>

        <EmptyState v-if="!rows.length">
            No lines to plan against. Run <code>php artisan wot:sync-vehicles</code> to fill the encyclopedia.
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
                <!-- Opposite polarity to the purchase board's Lines checkbox,
                     deliberately. There it hides what is settled; a module plan
                     is never settled, so this narrows to what you have already
                     planned instead — useful for reading the plan back, useless
                     as a default. -->
                <FilterRow label="Lines">
                    <FilterCheck v-model="board.only_planned">Only lines I have planned on</FilterCheck>
                    <!-- "to buy", not "to research": this board spends Free XP
                         on modules, and a line can owe an unlock it cannot buy
                         its way out of here. -->
                    <FilterCheck v-model="board.hide_researched" class="ms-3">Hide lines with nothing left to buy</FilterCheck>
                </FilterRow>
            </BoardFilterPanel>

            <EmptyState v-if="!board.shownRows.length">
                {{ board.only_planned
                    ? 'Nothing planned in the selected nations and tiers.'
                    : board.hide_researched
                        ? 'Nothing left to buy in the selected nations and tiers.'
                        : 'No lines in the selected nations and tiers.' }}
            </EmptyState>

            <TechTreeBoard
                v-else
                total-label="Planned"
                :rows="board.shownRows"
                :tiers="board.shownTiers"
                :row-total-class="(row) => (board.rowTotal(row) ? 'text-wot-gold' : 'text-wot-muted')"
            >
                <template #cell="{ cell }">
                    <!-- Finished according to XP Remaining: every module
                         researched and every tank ahead unlocked, so there is
                         nothing to spend Free XP on. -->
                    <span
                        v-if="cell.is_researched"
                        class="text-wot-muted"
                        :title="`${cell.name} — fully researched. Nothing left to spend Free XP on.`"
                    >
                        <!-- The same em dash ModulePlanPicker shows for a
                             vehicle with no modules at all: both mean nothing
                             here to plan. -->
                        —
                    </span>

                    <!-- Shared with a line above, where it is the editable one.
                         The same tank must never be two dropdowns writing the
                         same plan. -->
                    <span
                        v-else-if="cell.is_shared"
                        class="tabular-nums text-wot-dim/60"
                        :title="`${cell.name} — shared with ${cell.shared_with}, where it is planned.`"
                    >
                        {{ n(cell.planned_xp) }}
                    </span>

                    <ModulePlanPicker v-else :cell="cell" />
                </template>

                <template #rowTotal="{ row }">{{ n(board.rowTotal(row)) }}</template>
                <template #tierTotal="{ tier }">{{ n(board.tierTotal(tier)) }}</template>
                <template #grandTotal>{{ n(board.grandTotal) }}</template>
            </TechTreeBoard>
        </template>
    </section>
</template>
