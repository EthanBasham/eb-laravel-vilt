import { router } from '@inertiajs/vue3';

/**
 * Pins and unpins an article, from whichever list it is shown in.
 *
 * The request is the same everywhere; what differs is the page around it — which
 * props hold the list, and so what to reload and how to predict the result. So
 * this owns the URL and the verb, and the caller passes the rest:
 *
 * @param {string[]} only        The props to reload afterwards — the list, and anything that counts pins.
 * @param {Function} optimistic  (pageProps, article, pinning) => partialProps, applied on the click
 *                               and rolled back if the request fails. Optional.
 *
 * Scroll and state are preserved: pinning halfway down a list shouldn't throw the
 * page back to the top, and the reply should patch the page rather than remount
 * it, which would drop the seen tracker's record of what this visit counted.
 */
export function useArticlePin({ only, optimistic = null } = {}) {
    const togglePin = (article) => {
        const pinning = !article.is_pinned;

        const options = {
            preserveScroll: true,
            preserveState: true,
            only,
            ...(optimistic ? { optimistic: (pageProps) => optimistic(pageProps, article, pinning) } : {}),
        };

        pinning
            ? router.post(`/wot/news/${article.id}/pin`, {}, options)
            : router.delete(`/wot/news/${article.id}/pin`, options);
    };

    return { togglePin };
}
