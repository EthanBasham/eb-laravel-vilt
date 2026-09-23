<script setup>
import { computed, ref } from 'vue';
import BoardFilterPanel from '../BoardFilterPanel.vue';
import CrewCell from '../CrewCell.vue';
import CrewEditor from '../CrewEditor.vue';
import CrewLegend from './CrewLegend.vue';
import CrewXpProgression from './CrewXpProgression.vue';
import EmptyState from '../EmptyState.vue';
import FilterCheck from '../FilterCheck.vue';
import FilterChipRow from '../FilterChipRow.vue';
import FilterRow from '../FilterRow.vue';
import TechTreeBoard from '../TechTreeBoard.vue';
import { useBoardFilters } from '../../composables/useBoardFilters';
import { useBoardTotals } from '../../composables/useBoardTotals';
import { useNations } from '../../composables/useNations';

/**
 * The tech tree, one cell per vehicle, each cell the crew sitting in it.
 *
 * Every cue a cell uses is explained in the legend under the filters — a cell
 * is five letters and everything else it says is said by how they are drawn.
 */
const props = defineProps({
    crews: { type: Object, required: true },
    settings: { type: Object, required: true },
    xpProgression: { type: Array, default: () => [] },
});

/*
 * Nation and tier work as they do on every other board. The third control
 * narrows to lines something has been recorded on, which makes it a review tool
 * rather than a tidy-up — so it starts off, like Free XP's. Most of the tree has
 * no crew in it, and hiding that by default would hide the work.
 */
const {
    hiddenNations,
    hiddenTiers,
    only_crewed: onlyCrewed,
    hidden_crew_sizes: hiddenCrewSizes,
    toggleNation,
    toggleTier,
    clearNations,
    clearTiers,
} = useBoardFilters('crews', props.settings.crews_filters, {
    /*
     * hidden_crew_sizes is held as what is hidden, like nations and tiers, so a
     * size that first appears after a patch starts shown.
     */
    extra: { only_crewed: false, hidden_crew_sizes: [] },
    url: '/wot/crews/filters',
});

const { nationsOf } = useNations();

const nations = computed(() => nationsOf(props.crews.rows));
const shownTiers = computed(() => props.crews.tiers.filter((tier) => !hiddenTiers.value.includes(tier)));

/*
 * The crew sizes that actually occur on the tree, smallest first, so there is a
 * chip for every size a vehicle has and none for a size nothing has. A crew's
 * size is its seat count — one per body, the same count as the letters in the
 * cell — and a vehicle the encyclopedia publishes no crew for has no size.
 */
const crewSizes = computed(() => [...new Set(props.crews.rows
    .flatMap((row) => Object.values(row.cells))
    .map((cell) => cell.members.length)
    .filter((size) => size > 0))]
    .sort((a, b) => a - b));

const toggleCrewSize = (size) => (hiddenCrewSizes.value = hiddenCrewSizes.value.includes(size)
    ? hiddenCrewSizes.value.filter((hidden) => hidden !== size)
    : [...hiddenCrewSizes.value, size]);

/*
 * A vehicle whose crew size is switched off reads as an empty tier and counts
 * for nothing — the filter narrows the tanks, not just the lines, since the
 * question it answers is about one vehicle's crew.
 */
const isShown = (cell) => Boolean(cell) && !hiddenCrewSizes.value.includes(cell.members.length);

/*
 * A shared cell is counted on the row that owns the vehicle, which keeps the
 * row figures summing to the grand total — the same rule every board here
 * follows, and the reason a tier VIII under three tier Xs is one crew rather
 * than three.
 */
const isCounted = (cell) => isShown(cell) && !cell.is_shared;

// Shared cells included: a line keeps its place while any tank on it is still
// visible, wherever that tank happens to be counted.
const hasShownCell = (row) => shownTiers.value.some((tier) => isShown(row.cells[tier]));

const { rowTotal: rowCrews, shownRows, tierTotal, grandTotal } = useBoardTotals({
    rows: () => props.crews.rows,
    tiers: shownTiers,
    // A crew counts once, so the board's figures are counts rather than sums —
    // which is the same arithmetic every other board does with a price in the
    // cell instead of a one.
    cellValue: (cell) => (isCounted(cell) && cell.has_crew ? 1 : 0),
    keepRow: (row, crews) => !hiddenNations.value.includes(row.nation)
        && !(onlyCrewed.value && crews === 0)
        // Only once a size is switched off: otherwise a line keeps its row even
        // with every tier column hidden, as it always has.
        && (!hiddenCrewSizes.value.length || hasShownCell(row)),
});

// Seats, not crews: the denominator of the row's figure, counting every vehicle
// this row is answerable for whether or not anyone is sitting in it.
const rowSeats = (row) => shownTiers.value.filter((tier) => isCounted(row.cells[tier])).length;

// 0-6, the levels the progression table is keyed by — so the editor's select
// and that table can never offer different ceilings.
const levels = computed(() => props.xpProgression.map((step) => step.level));

const editing = ref(null);
</script>

<template>
    <section class="mt-4" aria-labelledby="crews-heading">
        <h2 id="crews-heading" class="sr-only">Crews</h2>

        <CrewXpProgression :steps="xpProgression" />

        <BoardFilterPanel
            :nations="nations"
            :tiers="crews.tiers"
            :hidden-nations="hiddenNations"
            :hidden-tiers="hiddenTiers"
            @toggle-nation="toggleNation"
            @clear-nations="clearNations"
            @toggle-tier="toggleTier"
            @clear-tiers="clearTiers"
        >
            <!-- Crew size, as a chip per size present. Only offered when sizes
                 differ — one size on the whole board is nothing to filter. -->
            <FilterChipRow
                v-if="crewSizes.length > 1"
                label="Crew"
                titled
                :items="crewSizes"
                :selected="hiddenCrewSizes"
                :item-label="(size) => `Crews of ${size}`"
                @toggle="toggleCrewSize"
                @clear="hiddenCrewSizes = []"
            />

            <FilterRow label="Lines">
                <FilterCheck v-model="onlyCrewed">Only lines with a crew recorded</FilterCheck>
            </FilterRow>

            <CrewLegend />
        </BoardFilterPanel>

        <EmptyState v-if="!shownRows.length">
            {{ onlyCrewed
                ? 'No crews recorded in the selected nations, tiers and crew sizes.'
                : 'No lines in the selected nations, tiers and crew sizes.' }}
        </EmptyState>

        <TechTreeBoard
            v-else
            align="center"
            total-label="Crews"
            :rows="shownRows"
            :tiers="shownTiers"
            :is-visible="isShown"
            :row-total-class="(row) => (rowCrews(row) ? 'text-wot-heading' : 'text-wot-dim')"
        >
            <template #cell="{ cell }">
                <CrewCell :cell="cell" @edit="editing = $event" />
            </template>

            <template #rowTotal="{ row }">
                {{ rowCrews(row) }}<span class="text-wot-dim"> / {{ rowSeats(row) }}</span>
            </template>

            <template #tierTotal="{ tier }">{{ tierTotal(tier) }}</template>
            <template #grandTotal>{{ grandTotal }}</template>
        </TechTreeBoard>

        <CrewEditor :cell="editing" :levels="levels" @close="editing = null" />
    </section>
</template>
