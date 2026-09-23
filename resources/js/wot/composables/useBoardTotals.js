import { computed } from 'vue';

/**
 * A tech-tree board's arithmetic: what a row owes, what a tier column owes, and
 * what the board owes altogether.
 *
 * The server bills the whole tree. The client's only job is to re-total the
 * cells a filter leaves visible — otherwise a filter would change what you see
 * but not what you owe — and every board did that with its own copy of the same
 * four reducers. The copies agreed, which is the good case; nothing here is
 * covered by a test runner, so five chances to disagree was the risk.
 *
 * Two rules are baked in because all five boards follow them:
 *
 *   Only visible tiers count. `tiers` is what survived the tier chips.
 *   A shared cell costs its row nothing. Sharing is handled inside `cellValue`,
 *   since only the board knows which half of its cell is shared — the XP board
 *   shares a vehicle's modules and its unlock separately.
 *
 * @param {object}   options
 * @param {Function} options.rows       () => every row on the board, before nation and line filters
 * @param {import('vue').Ref} options.tiers   the tier columns left showing
 * @param {Function} options.cellValue  (cell) => what this cell contributes, 0 for nothing
 * @param {Function} options.keepRow    (row, total) => whether the row survives this board's
 *                                      own filters. Handed the row's total because most
 *                                      boards hide the lines that come to nothing.
 */
export function useBoardTotals({ rows, tiers, cellValue, keepRow }) {
    /*
     * Not a computed: this is called per row per render, and memoising it would
     * mean a cache keyed by row for a sum of at most eleven numbers.
     */
    const rowTotal = (row) => tiers.value.reduce((sum, tier) => sum + cellValue(row.cells[tier]), 0);

    const shownRows = computed(() => rows().filter((row) => keepRow(row, rowTotal(row))));

    const tierTotal = (tier) => shownRows.value.reduce((sum, row) => sum + cellValue(row.cells[tier]), 0);

    const grandTotal = computed(() => shownRows.value.reduce((sum, row) => sum + rowTotal(row), 0));

    return { rowTotal, shownRows, tierTotal, grandTotal };
}
