import { computed, reactive } from 'vue';
import { useBoardFilters } from './useBoardFilters';
import { useBoardTotals } from './useBoardTotals';
import { useNations } from './useNations';

/**
 * One tech-tree board, whole: its filters, the rows and tiers they leave
 * showing, and what those come to.
 *
 * Every board on the grinding page is the same three things wired together in
 * the same order, and the wiring is where they used to differ by accident. What
 * is genuinely a board's own — what a cell is worth, and which lines it hides —
 * arrives as `rules`, which is handed the filter state so those decisions can
 * read it.
 *
 * ## Why this lives on the page rather than in the board component
 *
 * Two of the headline cards report a *filtered* board total: the server bills
 * the whole tree, and narrowing to a nation is what turns that into a number
 * worth reading. The cards are on show whichever tab is up, but only one tab's
 * table is mounted at a time — four boards of four hundred rows each is not
 * something to render for the sake of a card. So the state outlives the view of
 * it, which means the page owns it and the board components are handed it.
 *
 * `reactive` rather than a plain object, so the refs inside unwrap on the way
 * out: the bundle reads as ordinary properties in a template, stays live, and
 * a view can write a checkbox straight back to it — which is what makes it one
 * board's state rather than a copy of it.
 *
 * @param {string} name             the column the filters are stored under
 * @param {object|null} saved       the stored filters, or null if never saved
 * @param {object} options
 * @param {Function} options.rows   () => every row the server sent for this board
 * @param {Function} options.tiers  () => every tier column the server sent
 * @param {object} options.extra    the board's own filter flags and their defaults
 * @param {number[]} options.hiddenTiers  tiers to start hidden, on a board never saved
 * @param {Function} options.rules  (filters) => { cellValue, keepRow, isDone }, where
 *                                  `isDone` is optional and answers only whether a line
 *                                  is settled — which is what decides whether the board
 *                                  offers its Lines checkbox at all.
 */
export function useGrindBoard(name, saved, { rows, tiers, extra = {}, hiddenTiers = [], rules }) {
    const filters = useBoardFilters(name, saved, { extra, hiddenTiers });

    const shownTiers = computed(() => tiers().filter((tier) => !filters.hiddenTiers.value.includes(tier)));

    const { cellValue, keepRow, isDone } = rules(filters);

    const totals = useBoardTotals({ rows, tiers: shownTiers, cellValue, keepRow });

    const { nationsOf } = useNations();

    return reactive({
        ...filters,
        // Only the nations this board has rows for, in tech-tree order — a chip
        // for a nation with nothing on the board would filter nothing.
        nations: computed(() => nationsOf(rows())),
        shownTiers,
        ...totals,
        /*
         * Whether anything on the board is settled. The checkbox that hides
         * those lines is only offered when it would do something — a board with
         * nothing finished on it should not carry a control that changes
         * nothing when ticked.
         */
        hasDoneLines: computed(() => (isDone
            ? rows().some((row) => isDone(row, totals.rowTotal(row)))
            : false)),
    });
}
