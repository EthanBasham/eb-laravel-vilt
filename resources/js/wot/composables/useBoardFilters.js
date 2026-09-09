import { useHttp } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

/**
 * Filter state for one of the tech-tree boards, remembered per account.
 *
 * Both grids filter the same two ways — by nation and by tier — and then differ
 * by one checkbox each, so the shape is a fixed pair plus whatever `extra` the
 * board passes in. Every value is held as what is *hidden* or *narrowed to*
 * rather than what is selected, so a column that appears later starts on.
 *
 * @param {string} board            'purchase' or 'freexp' — which column the server stores this under.
 * @param {object|null} saved       The stored filters, or null if they have never been saved.
 * @param {object} defaults         Fallbacks: { hiddenNations, hiddenTiers, extra: {...} }.
 */
export function useBoardFilters(board, saved, { hiddenNations = [], hiddenTiers = [], extra = {} } = {}) {
    /*
     * Read once, at construction, and never watched. A save writes the column
     * these came from, so re-seeding would feed every click back into the refs
     * that produced it.
     */
    const state = {
        hiddenNations: ref([...(saved?.hidden_nations ?? hiddenNations)]),
        hiddenTiers: ref([...(saved?.hidden_tiers ?? hiddenTiers)]),
    };

    // The board's own checkbox (or checkboxes), snake_cased on the wire and
    // camelCased in the template, the way every other prop on this page is.
    const extras = Object.fromEntries(
        Object.entries(extra).map(([key, fallback]) => [key, ref(saved?.[key] ?? fallback)]),
    );

    const payload = () => ({
        board,
        hidden_nations: state.hiddenNations.value,
        hidden_tiers: state.hiddenTiers.value,
        ...Object.fromEntries(Object.entries(extras).map(([key, r]) => [key, r.value])),
    });

    /*
     * useHttp rather than router.patch: nothing on the page changes as a result
     * of the save — the board already shows the filtered state — so this wants
     * a standalone request that returns 204 and touches no props, not a visit
     * that rebuilds the board to hand back data the client already has.
     *
     * Debounced because picking a nation means clicking several flags, and each
     * click is a separate ref write. Half a second is long enough to collect a
     * run of them and short enough that closing the tab straight after a click
     * still saves it.
     *
     * Fire-and-forget by design. A filter is a preference, not a record: if the
     * request fails the board on screen is still filtered the way you asked,
     * and interrupting that to report it would be worse than losing the
     * position.
     */
    const request = useHttp(payload());

    let handle;

    watch([state.hiddenNations, state.hiddenTiers, ...Object.values(extras)], () => {
        Object.assign(request, payload());

        clearTimeout(handle);
        handle = setTimeout(() => request.patch('/wot/grinding/filters'), 500);
    });

    const drop = (list, value) => (list.includes(value)
        ? list.filter((x) => x !== value)
        : [...list, value]);

    return {
        ...state,
        ...extras,
        toggleNation: (nation) => (state.hiddenNations.value = drop(state.hiddenNations.value, nation)),
        toggleTier: (tier) => (state.hiddenTiers.value = drop(state.hiddenTiers.value, tier)),
        clearNations: () => (state.hiddenNations.value = []),
        clearTiers: () => (state.hiddenTiers.value = []),
    };
}
