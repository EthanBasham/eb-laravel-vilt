import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Marks article cards seen once they have genuinely been on screen.
 *
 * Two thresholds, both deliberate:
 *
 *   threshold  How much of the card must be visible. A card clipped at the
 *              bottom edge of the viewport has not been read.
 *   dwellMs    How long it must stay there. Without this, flinging the page
 *              from top to bottom would mark everything seen — which is the
 *              failure people actually notice and resent.
 *
 * Marks are batched and flushed on a timer rather than sent per card, so a
 * scroll through a full page is one request instead of twenty-four.
 */
export function useSeenTracker({
    dwellMs = 1500,
    threshold = 0.6,
    flushMs = 2000,
    onMarked = null,
} = {}) {
    const idFor = new WeakMap();
    const timers = new Map();
    const pending = new Set();

    let observer = null;
    let flushHandle = null;

    const flush = () => {
        if (pending.size === 0) {
            return;
        }

        const ids = [...pending];
        pending.clear();

        router.post('/wot/news/seen', { ids }, {
            preserveScroll: true,
            preserveState: true,
            // Only the counter comes back. The NEW badges deliberately stay put
            // for the rest of this visit: cards silently restyling as you
            // scroll past them is distracting, and the list would shimmer.
            // They clear on the next load, which is when you'd look again.
            only: ['unseenCount'],
        });

        onMarked?.(ids);
    };

    const stopWatching = (element) => {
        const timer = timers.get(element);

        if (timer) {
            window.clearTimeout(timer);
            timers.delete(element);
        }
    };

    /**
     * Template ref callback. Vue passes the element on mount and null on
     * unmount, so both cases have to be handled or the observer leaks nodes
     * across pagination.
     */
    const track = (element, id, isSeen) => {
        // Already seen, or nothing to observe.
        if (!element || isSeen || !observer) {
            return;
        }

        idFor.set(element, id);
        observer.observe(element);
    };

    onMounted(() => {
        // No IntersectionObserver means no automatic marking — the "mark all as
        // seen" button still works, so the feature degrades rather than breaks.
        if (typeof window === 'undefined' || !('IntersectionObserver' in window)) {
            return;
        }

        observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    const element = entry.target;

                    if (!entry.isIntersecting) {
                        stopWatching(element);

                        return;
                    }

                    if (timers.has(element)) {
                        return;
                    }

                    timers.set(element, window.setTimeout(() => {
                        pending.add(idFor.get(element));
                        stopWatching(element);
                        // Stop watching entirely once counted; re-scrolling past
                        // a card shouldn't queue it again.
                        observer.unobserve(element);
                    }, dwellMs));
                });
            },
            { threshold },
        );

        flushHandle = window.setInterval(flush, flushMs);
    });

    onBeforeUnmount(() => {
        timers.forEach((timer) => window.clearTimeout(timer));
        timers.clear();
        observer?.disconnect();

        if (flushHandle) {
            window.clearInterval(flushHandle);
        }

        // Anything queued but not yet sent goes now, so navigating away
        // mid-interval doesn't lose it.
        flush();
    });

    return { track };
}
