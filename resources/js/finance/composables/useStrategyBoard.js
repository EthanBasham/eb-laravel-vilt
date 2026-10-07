import { computed, ref, watch } from 'vue';
import { chartColors, colorOf } from '../lib/format';

/**
 * What the three retirement tabs share: a list of strategies set side by
 * side, one of them looked at closely, and a form to build or edit one.
 *
 * `strategies` is a getter for the list as the page shows it. `label` reads
 * what a strategy is called, which is not the same field on every tab.
 */
export function useStrategyBoard(strategies, { label = (strategy) => strategy.name } = {}) {
    // The form, and the strategy it is editing — null to build a new one.
    const form = ref({ open: false, strategy: null });
    const build = () => { form.value = { open: true, strategy: null }; };
    const edit = (strategy) => { form.value = { open: true, strategy }; };

    // The strategy under the closer look. It follows the list: the first one
    // to begin with, and the first again if the one being looked at goes.
    const selectedId = ref(strategies()[0]?.id ?? null);

    watch(() => strategies().map((strategy) => strategy.id), (ids) => {
        if (!ids.includes(selectedId.value)) selectedId.value = ids[0] ?? null;
    });

    const selectedIndex = computed(() => strategies().findIndex((strategy) => strategy.id === selectedId.value));
    const selected = computed(() => strategies()[selectedIndex.value] ?? null);

    // One line per strategy, of whichever figure in its rows. Keyed by id:
    // two strategies may well share a name.
    const lines = (field) => strategies().map((strategy, index) => ({
        key: strategy.id,
        label: label(strategy),
        color: colorOf(index),
        points: strategy.rows.map((row) => ({ x: row.age, y: row[field] })),
    }));

    return { form, build, edit, selectedId, selectedIndex, selected, lines };
}

/** A chart axis label for an age. */
export const age = (value) => `Age ${value}`;

/** One strategy's three buckets, year by year, as chart lines. */
export const bucketLines = (strategy) => [
    { label: 'Traditional', color: chartColors[1], points: strategy.rows.map((row) => ({ x: row.age, y: row.traditional })) },
    { label: 'Roth', color: chartColors[0], points: strategy.rows.map((row) => ({ x: row.age, y: row.roth })) },
    { label: 'Taxable savings', color: chartColors[2], dashed: true, points: strategy.rows.map((row) => ({ x: row.age, y: row.taxable })) },
];
