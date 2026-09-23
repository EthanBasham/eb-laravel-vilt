import { computed, ref } from 'vue';

/**
 * Sort state for a table whose rows are all on the page already.
 *
 * The garage arrives in one payload — a few hundred rows at most — so sorting
 * here is instant and costs no round trip. Nothing about this is specific to
 * vehicles; it is the click-a-header-to-sort behaviour, kept out of the
 * component that draws the header.
 *
 * @param {Function} rows          () => the rows to sort, already filtered
 * @param {string} initialKey      the column to open on
 * @param {string[]} ascendingKeys the columns that read best smallest-first. Names
 *                                 want A–Z; every numeric column is more useful
 *                                 highest-first, which is why descending is the
 *                                 default rather than the exception.
 */
export function useTableSort(rows, initialKey, { ascendingKeys = [] } = {}) {
    const sortKey = ref(initialKey);
    const sortAsc = ref(ascendingKeys.includes(initialKey));

    const sorted = computed(() => {
        const direction = sortAsc.value ? 1 : -1;

        // Copied before sorting: Array.prototype.sort mutates, and the rows
        // handed in are usually a computed whose value must not be rewritten in
        // place.
        return [...rows()].sort((a, b) => {
            const x = a[sortKey.value];
            const y = b[sortKey.value];

            if (typeof x === 'string') {
                return x.localeCompare(y) * direction;
            }

            /*
             * Nothing recorded sorts last either way, rather than being treated
             * as a zero. A vehicle with no WN8 has no expected values published
             * for it, and burying those at the bottom of an ascending sort
             * would read as the worst scores on the page.
             */
            if (x === null) {
                return 1;
            }

            if (y === null) {
                return -1;
            }

            return (x - y) * direction;
        });
    });

    const sortBy = (key) => {
        if (sortKey.value === key) {
            sortAsc.value = !sortAsc.value;

            return;
        }

        sortKey.value = key;
        sortAsc.value = ascendingKeys.includes(key);
    };

    return { sortKey, sortAsc, sorted, sortBy };
}
