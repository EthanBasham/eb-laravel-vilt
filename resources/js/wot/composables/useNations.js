import { usePage } from '@inertiajs/vue3';

/**
 * The nation list, in tech-tree order.
 *
 * `config('wargaming.nations')` is shared with every page by
 * HandleInertiaRequests, and its key order is the order the game lists nations
 * in. Every vehicle list here follows it rather than sorting alphabetically, so
 * the flags sit where a player expects to find them.
 *
 * This was written out in four places — the two board pages, the tank picker
 * and the dashboard's garage filter — which is also how the dashboard ended up
 * with the only copy that kept unknown nations.
 */
export function useNations() {
    const page = usePage();

    /** What a slug is called, falling back to the slug for a nation added by a patch. */
    const nationName = (slug) => page.props.nations?.[slug] ?? slug;

    /**
     * The nations present in `items`, in tech-tree order.
     *
     * @param {Array<{nation: string}>} items    rows or vehicles, anything carrying a nation
     * @param {boolean} includeUnknown           append nations the config has never heard of,
     *                                           alphabetically, rather than dropping them.
     *                                           The garage filter wants them — a vehicle you
     *                                           own is one you can filter to, whatever the
     *                                           config knows. A board does not: its rows are
     *                                           built from the encyclopedia the config
     *                                           describes.
     */
    const nationsOf = (items, { includeUnknown = false } = {}) => {
        const order = Object.keys(page.props.nations ?? {});
        const present = new Set(items.map((item) => item.nation));
        const known = order.filter((nation) => present.has(nation));

        if (! includeUnknown) {
            return known;
        }

        return [...known, ...[...present].filter((nation) => !order.includes(nation)).sort()];
    };

    return { nationName, nationsOf };
}
