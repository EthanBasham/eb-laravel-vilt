import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

/**
 * The form behind a tool page.
 *
 * Every tool is a GET that renders from its query string, and all of its
 * arithmetic happens on the server. So "live" recalculation is a visit: this
 * keeps a reactive copy of the inputs, and a moment after one changes it asks
 * for the same page again with the new values in the URL.
 *
 * Three visit options do the work. `preserveState` keeps this component (and
 * so the field being typed in) alive across the visit, where a plain visit
 * would rebuild it and drop focus. `preserveScroll` keeps the page where it
 * is. `replace` swaps the history entry instead of adding one, so the back
 * button leaves the tool rather than stepping back through every keystroke.
 *
 * The pause is so a number being typed digit by digit sends one request, not
 * one per digit.
 */
export function useToolQuery(initial, { delay = 350 } = {}) {
    const params = reactive(JSON.parse(JSON.stringify(initial)));

    let timer = null;

    const submit = () => {
        router.get(window.location.pathname, JSON.parse(JSON.stringify(params)), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    watch(params, () => {
        clearTimeout(timer);
        timer = setTimeout(submit, delay);
    }, { deep: true });

    return params;
}
